<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\DAV;

use OCA\FadenstackAgent\Files\Operations;
use OCA\FadenstackAgent\Identity\CloudIdentityVerifier;
use OCA\FadenstackAgent\Providers\NativeRequest;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/** Hold public native path locks across the real core COPY/MOVE, not an earlier preflight. */
final class FileOperationsGuardPlugin extends ServerPlugin {
	private array $locks = [];
	private ?array $proposal = null;
	private ?Folder $destination = null;
	private bool $reopened = false;
	public function __construct(
		private Operations $operations,
		private FileService $files,
		private ISession $session,
		private IUserSession $users,
		private Configuration $configuration,
		private CloudIdentityVerifier $cloud,
		private LoggerInterface $logger,
	) {
	}
	public function initialize(Server $server): void {
		foreach (['COPY', 'MOVE'] as $method) {
			$server->on('beforeMethod:' . $method, [$this, 'before'], 20);
			$server->on('afterMethod:' . $method, [$this, 'after'], 20);
		}
		$server->on('exception', [$this, 'failed'], 20);
	}
	public function before(RequestInterface $request, ResponseInterface $response): void {
		$id = $request->getHeader('X-Fadenstack-File-Ticket');
		if ($id === null || $id === '') {
			return;
		}
		try {
			$this->reopened = $this->session->reopen();
			$this->files->check($request->getHeader('X-Fadenstack-Session') ?? '');
			$token = $request->getHeader('X-Fadenstack-Agent-Token') ?? '';
			if ($this->configuration->historyEnabled() || $token !== '') {
				$this->files->bindCloudPrincipal($this->cloud->verify($token));
			}
			$proposal = $this->operations->claim($id);
			$this->proposal = $proposal;
			[$source, $destination] = $this->operations->validate($proposal);
			$parents = [$source->getParent(), $destination];
			usort($parents, static fn (Folder $a, Folder $b): int => strcmp($a->getPath(), $b->getPath()));
			$locked = [];
			foreach ($parents as $parent) {
				if (isset($locked[$parent->getPath()])) {
					continue;
				}
				$parent->lock(ILockingProvider::LOCK_SHARED);
				$this->locks[] = $parent;
				$locked[$parent->getPath()] = true;
			}
			// COPY tolerates a nested shared lock. MOVE must acquire/upgrade its own
			// leaf lock; a pre-held shared leaf lock prevents that native upgrade.
			// MOVE therefore retains parent locks and native If-Match/Overwrite:F,
			// with its non-atomic leaf preflight limitation disclosed in approval.
			if ($proposal['operation'] === 'copy') {
				$source->lock(ILockingProvider::LOCK_SHARED);
				$this->locks[] = $source;
			}
			[$freshSource, $freshDestination] = $this->operations->validate($proposal);
			if ($freshSource->getPath() !== $source->getPath() || $freshDestination->getPath() !== $destination->getPath()) {
				throw new \RuntimeException('A native path changed while acquiring the lock.');
			}
			$uid = $this->users->getUser()?->getUID();
			$expectedPath = 'files/' . $uid . '/' . ltrim($proposal['sourcePath'], '/');
			if ($request->getMethod() !== strtoupper($proposal['operation']) || $request->getPath() !== $expectedPath
				|| $request->getHeader('Destination') !== $this->operations->expectedDestination($proposal) || $request->getHeader('Overwrite') !== 'F'
				|| $request->getHeader('If-Match') !== NativeRequest::strongEtag($proposal['expectedEtag']) || $request->getBodyAsString() !== '') {
				throw new \RuntimeException('The native request differs from its approved ticket.');
			}
			$this->destination = $freshDestination;
			$this->operations->dispatched($proposal);
		} catch (\Throwable $error) {
			$this->failed($error);
			throw new Forbidden('The approved Files operation could not be dispatched.', 0, $error);
		}
	}
	public function after(RequestInterface $request, ResponseInterface $response): void {
		if ($this->proposal === null || $this->destination === null) {
			return;
		}
		try {
			if (!in_array($response->getStatus(), [201, 204], true)) {
				throw new \RuntimeException('Unconfirmed native Files effect.');
			}
			$result = $this->destination->get($this->proposal['destinationName']);
			if (!$result instanceof File || ($this->proposal['operation'] === 'move' && $result->getId() !== (int)substr($this->proposal['sourceResourceId'], 16))
				|| ($this->proposal['operation'] === 'copy' && $result->getId() === (int)substr($this->proposal['sourceResourceId'], 16))) {
				throw new \RuntimeException('The native result identity changed.');
			}
			$this->operations->complete($this->proposal, $result);
		} catch (\Throwable $error) {
			$this->operations->failed($this->proposal['id']);
			throw $error;
		} finally {
			$this->release();
		}
	}
	public function failed(\Throwable $error): void {
		if ($this->proposal) {
			$this->logger->warning('A guarded Files action requires inspection.', ['app' => 'fadenstack_agent', 'exception' => $error]);
		}
		try {
			if ($this->proposal) {
				$this->operations->failed($this->proposal['id']);
			}
		} finally {
			$this->release();
		}
	}
	private function release(): void {
		foreach (array_reverse($this->locks) as $node) {
			try {
				$node->unlock(ILockingProvider::LOCK_SHARED);
			} catch (\Throwable) {
			}
		}
		$this->locks = [];
		$this->proposal = null;
		$this->destination = null;
		if ($this->reopened) {
			$this->reopened = false;
			$this->session->close();
		}
	}
}
