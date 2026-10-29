<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\DAV;

use OCA\FadenstackAgent\Identity\CloudIdentityVerifier;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Constants;
use OCP\Files\DavUtil;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Conflict;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/** Development-only, local home storage / native DB locking acceptance. */
class FolderCreateGuardPlugin extends ServerPlugin {
	private ?Folder $parent = null;
	private ?Folder $child = null;
	private ?string $ticketId = null;
	private ?array $ticket = null;
	private bool $reopened = false;

	public function __construct(
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private ISession $session,
		private ScopePolicy $scopePolicy,
		private Configuration $config,
		private FileService $files,
		private CloudIdentityVerifier $cloud,
	) {
	}

	#[\Override]
	public function initialize(Server $server): void {
		$server->on('beforeMethod:MKCOL', [$this, 'beforeMkcol'], 20);
		$server->on('afterMethod:MKCOL', [$this, 'afterMkcol'], 20);
		$server->on('exception', [$this, 'onException'], 20);
	}

	public function beforeMkcol(RequestInterface $request, ResponseInterface $response): void {
		$id = $request->getHeader('X-Fadenstack-Folder-Ticket');
		if ($id === null || $id === '') {
			return; // Ordinary native DAV remains ordinary native DAV.
		}
		if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id)) {
			throw new BadRequest('Invalid folder ticket.');
		}
		try {
			// Native DAV authentication closes the session. Reopen before reading
			// and claiming the ticket, so another request cannot claim a stale copy.
			$this->reopened = $this->session->reopen();
			$this->files->checkSession($request->getHeader('X-Fadenstack-Session') ?? '');
			$this->ticketId = $id;
			if ($request->getMethod() !== 'MKCOL' || $request->getHeader('If-None-Match') !== '*' || $request->getBodyAsString() !== '') {
				throw new BadRequest('Only the approved conditional empty-folder request is allowed.');
			}
			$token = $request->getHeader('X-Fadenstack-Agent-Token') ?? '';
			if ($this->config->historyEnabled() || $token !== '') {
				$this->files->bindCloudPrincipal($this->cloud->verify($token));
			}
			$ticket = $this->files->claimFolderDispatch($id);
			$this->ticket = $ticket;
			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new Forbidden('Sign in to Nextcloud.');
			}
			$home = $this->rootFolder->getUserFolder($user->getUID());
			$parent = $home->getById($ticket['folderId'])[0] ?? null;
			if (!$parent instanceof Folder) {
				throw new Conflict('The approved parent is unavailable.');
			}
			if (!$parent->getStorage()->isLocal() || $parent->getStorage()->getId() !== $home->getStorage()->getId()
				|| $parent->getOwner()?->getUID() !== $user->getUID()) {
				throw new Forbidden('Folder creation is unavailable for this storage.');
			}
			$parent->lock(ILockingProvider::LOCK_SHARED);
			$this->parent = $parent;
			// The first node may already be stale when lock() returns. Resolve ID,
			// path, revision and rights again while holding the actual path lock.
			$fresh = $home->getById($ticket['folderId'])[0] ?? null;
			if (!$fresh instanceof Folder || $fresh->getPath() !== $parent->getPath()
				|| $fresh->getStorage()->getId() !== $parent->getStorage()->getId()
				|| $fresh->getInternalPath() !== $parent->getInternalPath()
				|| '/' . ltrim($home->getRelativePath($fresh->getPath()), '/') !== $ticket['folderPath']
				|| $fresh->getEtag() !== $ticket['parentEtag']) {
				throw new Conflict('The approved parent moved or changed before creation.');
			}
			if (!$fresh->isCreatable() || ($fresh->getPermissions() & Constants::PERMISSION_CREATE) === 0) {
				throw new Forbidden('The destination is not writable.');
			}
			$this->scopePolicy->assertMovement($fresh, $fresh);
			// Sabre already decoded getPath(); decoding again changes literal % names.
			$expected = 'files/' . $user->getUID() . ($ticket['folderPath'] === '/' ? '' : $ticket['folderPath']) . '/' . $ticket['name'];
			if ($request->getPath() !== $expected || $fresh->nodeExists($ticket['name'])) {
				throw new Conflict('The request target changed or already exists.');
			}
		} catch (\Throwable $error) {
			$this->onException($error);
			if ($error instanceof Problem) {
				throw new Forbidden($error->getMessage(), 0, $error);
			}
			throw $error;
		}
	}

	public function afterMkcol(RequestInterface $request, ResponseInterface $response): void {
		if ($this->ticketId === null || $this->parent === null || $this->ticket === null) {
			return;
		}
		try {
			if ($response->getStatus() !== 201) {
				$this->files->abandonFolderDispatch($this->ticketId);
				return;
			}
			$child = $this->parent->get($this->ticket['name']);
			if (!$child instanceof Folder) {
				throw new Conflict('The created folder could not be confirmed.');
			}
			$child->lock(ILockingProvider::LOCK_SHARED);
			$this->child = $child;
			$fresh = $this->parent->get($this->ticket['name']);
			$header = $response->getHeader('OC-FileId');
			if (!$fresh instanceof Folder || $fresh->getId() !== $child->getId()
				|| ($header !== null && $header !== DavUtil::getDavFileId($fresh->getId()))) {
				throw new Conflict('The native creation identity changed.');
			}
			$this->files->completeFolderDispatch($this->ticketId, $fresh->getId());
		} catch (\Throwable $error) {
			$this->files->abandonFolderDispatch($this->ticketId);
			throw $error;
		} finally {
			$this->release();
		}
	}

	public function onException(\Throwable $error): void {
		try {
			if ($this->ticketId !== null) {
				$this->files->abandonFolderDispatch($this->ticketId);
			}
		} finally {
			$this->release();
		}
	}

	private function release(): void {
		foreach ([$this->child, $this->parent] as $node) {
			if ($node !== null) {
				try {
					$node->unlock(ILockingProvider::LOCK_SHARED);
				} catch (\Throwable) {
				}
			}
		}
		$this->child = $this->parent = null;
		$this->ticket = null;
		$this->ticketId = null;
		if ($this->reopened) {
			$this->reopened = false;
			$this->session->close();
		}
	}
}
