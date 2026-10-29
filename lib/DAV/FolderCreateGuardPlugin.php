<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\DAV;

use OCA\DAV\Connector\Sabre\Exception\FileLocked;
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
use OCP\Lock\LockedException;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Conflict;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotAuthenticated;
use Sabre\DAV\Server as SabreServer;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class FolderCreateGuardPlugin extends ServerPlugin {
	private ?Folder $lockedFolder = null;
	private ?string $activeTicketId = null;

	public function __construct(
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private ISession $session,
		private ScopePolicy $scopePolicy,
		private Configuration $config,
		private FileService $files,
	) {
	}

	#[\Override]
	public function initialize(SabreServer $server): void {
		$server->on('beforeMethod:MKCOL', [$this, 'beforeMkcol'], 20);
		$server->on('afterMethod:MKCOL', [$this, 'afterMkcol'], 20);
		$server->on('exception', [$this, 'onException'], 20);
	}

	public function beforeMkcol(RequestInterface $request, ResponseInterface $response): void {
		$ticketId = $request->getHeader('X-Fadenstack-Folder-Ticket');
		if ($ticketId === null || $ticketId === '') {
			return;
		}
		if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $ticketId)) {
			throw new BadRequest('Invalid folder creation ticket format.');
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new NotAuthenticated('User is not authenticated.');
		}
		$uid = $user->getUID();

		$state = $this->session->get(FileService::KEY) ?? [];
		$ticket = $state['folderReceipts'][$ticketId] ?? null;
		if (!$ticket || ($ticket['expires'] ?? 0) <= time()) {
			throw new Forbidden('The folder creation ticket is expired, missing, or was already consumed.');
		}

		try {
			$this->files->assertProposalIdentity($ticket);
		} catch (Problem $e) {
			throw new Forbidden($e->getMessage(), 0, $e);
		}

		if (!$this->config->folderCreateEnabled()) {
			throw new Forbidden('Folder creation is not enabled.');
		}
		try {
			$this->scopePolicy->assertFolderCreateTool();
		} catch (Problem $e) {
			throw new Forbidden($e->getMessage(), 0, $e);
		}

		$home = $this->rootFolder->getUserFolder($uid);
		$nodes = $home->getById((int)$ticket['folderId']);
		if (empty($nodes) || !($nodes[0] instanceof Folder)) {
			throw new Conflict('Approved destination is no longer an available folder.');
		}
		$parentFolder = $nodes[0];

		try {
			$parentFolder->lock(ILockingProvider::LOCK_SHARED);
			$this->lockedFolder = $parentFolder;
			$this->activeTicketId = $ticketId;
		} catch (LockedException $e) {
			throw new FileLocked($e->getMessage(), 0, $e);
		}

		$currentParentPath = '/' . ltrim($home->getRelativePath($parentFolder->getPath()), '/');
		if ($currentParentPath !== $ticket['folderPath']) {
			throw new Conflict('Approved destination folder moved before creation.');
		}

		if ($parentFolder->getEtag() !== $ticket['parentEtag']) {
			throw new Conflict('Approved destination folder revision changed before creation.');
		}

		if (!$parentFolder->isCreatable() || ($parentFolder->getPermissions() & Constants::PERMISSION_CREATE) === 0) {
			throw new Forbidden('You do not have permission to create folders in this destination.');
		}

		try {
			$this->scopePolicy->assertMovement($parentFolder, $parentFolder);
		} catch (Problem $e) {
			throw new Forbidden($e->getMessage(), 0, $e);
		}

		$requestPath = rtrim(rawurldecode($request->getPath()), '/');
		$expectedPath = rtrim('files/' . $uid . ($ticket['folderPath'] === '/' ? '' : $ticket['folderPath']) . '/' . $ticket['name'], '/');
		if ($requestPath !== $expectedPath) {
			throw new Conflict('MKCOL request destination does not match approved ticket target.');
		}

		if ($parentFolder->nodeExists($ticket['name'])) {
			throw new Conflict('A folder or file with this name already exists.');
		}
	}

	public function afterMkcol(RequestInterface $request, ResponseInterface $response): void {
		if ($this->lockedFolder !== null) {
			try {
				$this->lockedFolder->unlock(ILockingProvider::LOCK_SHARED);
			} catch (\Throwable) {
			} finally {
				$this->lockedFolder = null;
			}
		}

		$ticketId = $this->activeTicketId;
		$this->activeTicketId = null;
		if ($ticketId === null) {
			return;
		}

		$status = $response->getStatus();
		$state = $this->session->get(FileService::KEY) ?? [];
		if ($status === 201) {
			$user = $this->userSession->getUser();
			if ($user !== null) {
				$ticket = $state['folderReceipts'][$ticketId] ?? null;
				if ($ticket !== null && !empty($ticket['folderId'])) {
					$home = $this->rootFolder->getUserFolder($user->getUID());
					$nodes = $home->getById((int)$ticket['folderId']);
					if (!empty($nodes) && $nodes[0] instanceof Folder && $nodes[0]->nodeExists($ticket['name'])) {
						$child = $nodes[0]->get($ticket['name']);
						if ($child instanceof Folder) {
							$davFileId = DavUtil::getDavFileId($child->getId());
							$state['folderReceipts'][$ticketId]['executed'] = true;
							$state['folderReceipts'][$ticketId]['createdFileId'] = $davFileId;
							$state['folderReceipts'][$ticketId]['createdNodeId'] = $child->getId();
							$this->session->set(FileService::KEY, $state);
							return;
						}
					}
				}
			}
		}

		unset($state['folderReceipts'][$ticketId]);
		$this->session->set(FileService::KEY, $state);
	}

	public function onException(\Throwable $e): void {
		if ($this->lockedFolder !== null) {
			try {
				$this->lockedFolder->unlock(ILockingProvider::LOCK_SHARED);
			} catch (\Throwable) {
			} finally {
				$this->lockedFolder = null;
			}
		}

		if ($this->activeTicketId !== null) {
			$state = $this->session->get(FileService::KEY) ?? [];
			unset($state['folderReceipts'][$this->activeTicketId]);
			$this->session->set(FileService::KEY, $state);
			$this->activeTicketId = null;
		}
	}
}
