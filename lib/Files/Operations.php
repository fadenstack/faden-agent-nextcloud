<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Files;

use OCA\FadenstackAgent\AppInfo\Application;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\VerifiedReadback;
use OCA\FadenstackAgent\Providers\NativeApiBridge;
use OCA\FadenstackAgent\Providers\NativeRequest;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\App\IAppManager;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\ServerVersion;
use Psr\Log\LoggerInterface;

/** Three typed operations, not a URL/method proxy. Stored proposals contain no credentials. */
final class Operations {
	private const KEY = 'fadenstack_agent.file_operations';
	public const MAX_BYTES = 10485760;
	private ?string $attempt = null;

	public function __construct(private FileService $files, private ScopePolicy $scope, private Configuration $configuration,
		private IRootFolder $root, private IUserSession $users, private ISession $session, private IConfig $config,
		private NativeApiBridge $bridge, private JournalService $journal, private IManager $shares, private IUserManager $directory,
		private IAppManager $apps, private LoggerInterface $logger, private ServerVersion $version, private QuietShare $quietShare) {
	}

	public function attemptId(): ?string { return $this->attempt; }
	public function expectedDestination(array $proposal): string {
		return NativeRequest::fileOperation($this->uid(), $proposal['operation'], $proposal['sourcePath'], $this->bridge->base(),
			$proposal['parentPath'], $proposal['destinationName'], $proposal['expectedEtag'], $proposal['id'], '', '')->headers['Destination'];
	}
	private function uid(): string { return $this->users->getUser()?->getUID() ?? throw new Problem('Sign in to Nextcloud.', 401); }
	private function home(): Folder { return $this->root->getUserFolder($this->uid()); }
	private function state(): array { $state = $this->session->get(self::KEY); return is_array($state) ? $state : []; }
	private function save(array $state): void { $this->session->set(self::KEY, $state); }
	private function stamp(): string {
		$identity = $this->files->journalIdentity();
		return hash('sha256', json_encode([$identity->scopeHash, $identity->sessionHash, $this->scope->configStamp(), $this->recipients()], JSON_THROW_ON_ERROR));
	}
	private function recipients(): array {
		try { $values = json_decode($this->config->getAppValue(Application::APP_ID, 'files_share_recipients', '[]'), true, 4, JSON_THROW_ON_ERROR); }
		catch (\Throwable) { throw new Problem('The sharing recipient policy is invalid.', 503); }
		if (!is_array($values) || !array_is_list($values) || count($values) > 50 || array_filter($values, static fn ($v) => !is_string($v) || $v === '' || strlen($v) > 255 || preg_match('/[\x00-\x1f\x7f]/', $v))) {
			throw new Problem('The sharing recipient policy is invalid.', 503);
		}
		return $values;
	}
	public function capabilities(): array {
		if (!$this->configuration->filesMutationsEnabled() || !$this->configuration->folderDispatchSupported() || $this->version->getMajorVersion() !== 35
			|| $this->journal->writesPaused($this->files->journalIdentity())) return [];
		if (!$this->supportedRoot(false)) return [];
		$tools = [];
		foreach (ScopePolicy::FILE_MUTATION_TOOLS as $tool) {
			try {
				$this->scope->assertFileMutationTool($tool);
				if ($tool === 'nc_files_share_readonly') {
					if (!$this->sharingEnabled() || !array_filter($this->recipients(), fn ($id) => $id !== $this->uid() && ($this->directory->get($id)?->isEnabled() ?? false))) continue;
				} elseif (!$this->supportedRoot(true)) continue;
				$tools[] = $tool;
			} catch (Problem) { continue; }
		}
		return $tools;
	}
	private function sharingEnabled(): bool {
		return $this->apps->isEnabledForUser('files_sharing') && $this->shares->shareApiEnabled() && !$this->shares->sharingDisabledForUser($this->uid());
	}
	private function supportedRoot(bool $write): bool {
		$policy = $this->files->identity()['scopePolicy'];
		foreach ($policy[$write ? 'destinations' : 'sources'] as $entry) {
			try {
				$node = $entry['kind'] === 'user-files' ? $this->home() : $this->node($entry['resourceId']);
				if ($write && !$node instanceof Folder) continue;
				$this->supported($node);
				$this->scope->describeNode($node, $write ? 'write' : 'read', false);
				return true;
			} catch (\Throwable) { continue; }
		}
		return false;
	}
	private function node(string $reference): File|Folder {
		if (!preg_match('/^nextcloud:files:([1-9][0-9]{0,14})$/D', $reference, $match)) throw new Problem('Use a canonical Nextcloud file reference.', 400);
		foreach ($this->home()->getById((int)$match[1]) as $node) {
			if (($node instanceof File || $node instanceof Folder) && $node->isReadable()) return $node;
		}
		throw new Problem('The native resource is unavailable.', 404);
	}
	public function digest(File $file): string {
		if ($file->getSize() > self::MAX_BYTES) throw new Problem('Use a single file of at most 10 MiB.', 413);
		$stream = $file->fopen('rb');
		if (!is_resource($stream)) throw new Problem('The file could not be read.', 403);
		try { $body = stream_get_contents($stream, self::MAX_BYTES + 1); }
		finally { fclose($stream); }
		if (!is_string($body) || strlen($body) > self::MAX_BYTES) throw new Problem('Use a single file of at most 10 MiB.', 413);
		return hash('sha256', $body);
	}
	private function supported(File|Folder $node): void {
		if ($this->version->getMajorVersion() !== 35 || !$this->configuration->folderDispatchSupported() || !$node->getStorage()->isLocal()
			|| $node->getStorage()->getId() !== $this->home()->getStorage()->getId() || $node->getOwner()?->getUID() !== $this->uid() || $node->isEncrypted()) {
			throw new Problem('This operation requires the tested local home storage and native DB locking.', 422);
		}
	}
	private function audience(File|Folder $node): string {
		return hash('sha256', json_encode($this->shares->getAccessList($node, true, false), JSON_THROW_ON_ERROR));
	}
	/** Fresh identity/path/revision/class/ACL metadata, rechecked under the native effect locks. */
	public function validate(array $proposal, bool $probe = true): array {
		$this->scope->assertFileMutationTool($proposal['toolName']);
		if (!hash_equals($proposal['stamp'], $this->stamp())) throw new Problem('The account, policy or recipient configuration changed.', 409);
		$source = $this->node($proposal['sourceResourceId']);
		if (!$source instanceof File) throw new Problem('Choose one file, not a folder.', 415);
		$this->supported($source);
		$grant = $this->scope->describeNode($source, 'read', $probe);
		if ($grant['sourceClass'] !== $proposal['sourceClass'] || $this->audience($source) !== $proposal['sourceAudienceHash']
			|| $this->home()->getRelativePath($source->getPath()) !== $proposal['sourcePath']
			|| NativeRequest::strongEtag($source->getEtag()) !== NativeRequest::strongEtag($proposal['expectedEtag'])
			|| ($probe && !hash_equals($proposal['contentHash'], $this->digest($source)))) throw new Problem('The approved source changed.', 409);
		if ($proposal['operation'] === 'share_readonly') {
			$this->scope->assertShareMovement($source);
			if (!$this->sharingEnabled() || ($source->getPermissions() & Constants::PERMISSION_SHARE) === 0
				|| !in_array($proposal['recipientUserId'], $this->recipients(), true)
				|| $proposal['recipientUserId'] === $this->uid() || !($this->directory->get($proposal['recipientUserId'])?->isEnabled() ?? false)) throw new Problem('This recipient or native sharing capability is unavailable.', 403);
			foreach ($this->shares->getSharesBy($this->uid(), IShare::TYPE_USER, $source, false, 101, 0) as $share) {
				if ($share->getSharedWith() === $proposal['recipientUserId']) throw new Problem('This user already has a direct share. No existing share will be modified.', 409);
			}
			return [$source, $source->getParent()];
		}
		$parent = $this->node($proposal['destinationResourceId']);
		if (!$parent instanceof Folder) throw new Problem('Choose an explicit destination folder.', 415);
		$this->supported($parent);
		$this->scope->assertMovement($source, $parent, $probe);
		if ($this->home()->getRelativePath($parent->getPath()) !== $proposal['parentPath'] || $parent->getEtag() !== $proposal['parentEtag']
			|| $this->audience($parent) !== $proposal['destinationAudienceHash'] || !$parent->isCreatable()
			|| $parent->nodeExists($proposal['destinationName'])) throw new Problem('The approved destination changed or already exists.', 409);
		if ($proposal['operation'] === 'move' && !$source->isDeletable()) throw new Problem('The source cannot be moved by this native role.', 403);
		return [$source, $parent];
	}
	public function propose(string $operation, string $sourceResourceId, string $expectedEtag, string $destinationResourceId = '', string $destinationName = '', string $recipientUserId = '', string $clientRequestId = ''): array {
		if ($clientRequestId !== '' && !preg_match('/^request-[a-f0-9-]{36}$/D', $clientRequestId)) throw new Problem('Invalid Files request identifier.', 400);
		$cancelled = $this->session->get(self::KEY . '.cancelled') ?? [];
		if (($cancelled['overflow'] ?? 0) > time() || ($clientRequestId !== '' && ($cancelled[$clientRequestId] ?? 0) > time())) throw new Problem('This Files request was cancelled.', 410);
		if (!in_array($operation, ['copy', 'move', 'share_readonly'], true)) throw new Problem('Unsupported Files operation.', 400);
		$tool = 'nc_files_' . $operation;
		$this->scope->assertFileMutationTool($tool);
		$source = $this->node($sourceResourceId);
		if (!$source instanceof File) throw new Problem('Choose one file.', 415);
		$this->supported($source);
		$grant = $this->scope->describeNode($source, 'read');
		if (NativeRequest::strongEtag($expectedEtag) !== NativeRequest::strongEtag($source->getEtag())) throw new Problem('The source revision changed.', 409);
		$parent = $operation === 'share_readonly' ? $source->getParent() : $this->node($destinationResourceId);
		if (!$parent instanceof Folder) throw new Problem('Choose a destination folder.', 415);
		if ($operation !== 'share_readonly') FileService::folderName($destinationName);
		$proposal = ['id' => bin2hex(random_bytes(24)), 'operation' => $operation, 'toolName' => $tool, 'sourceResourceId' => $sourceResourceId,
			'sourcePath' => $this->home()->getRelativePath($source->getPath()), 'expectedEtag' => $source->getEtag(), 'sourceClass' => $grant['sourceClass'],
			'contentHash' => $this->digest($source), 'sourceAudienceHash' => $this->audience($source),
			'destinationResourceId' => 'nextcloud:files:' . $parent->getId(), 'parentPath' => $this->home()->getRelativePath($parent->getPath()),
			'parentEtag' => $parent->getEtag(), 'destinationAudienceHash' => $this->audience($parent), 'destinationName' => $destinationName,
			'recipientUserId' => $operation === 'share_readonly' ? $recipientUserId : '', 'clientRequestId' => $clientRequestId,
			'expires' => time() + 600, 'approved' => false, 'stamp' => $this->stamp(), 'state' => 'prepared'];
		$this->validate($proposal);
		$proposal['target'] = $operation === 'share_readonly' ? $proposal['sourcePath'] . ' → ' . $recipientUserId : rtrim($proposal['parentPath'], '/') . '/' . $destinationName;
		$proposal['digest'] = hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR));
		$state = array_filter($this->state(), static fn ($p) => ($p['expires'] ?? 0) > time());
		$pending = array_filter($state, static fn ($p) => in_array($p['state'], ['prepared', 'ready', 'claimed'], true));
		if (count($pending) >= 20) throw new Problem('Too many pending Files actions. Cancel a preview or wait for expiry.', 429);
		// Completed tickets do not consume the pending quota. Their durable audit
		// remains in the journal; keep only a bounded short-lived receipt cache.
		$state = [...array_slice(array_diff_key($state, $pending), -20, null, true), ...$pending];
		$state[$proposal['id']] = $proposal; $this->save($state);
		return $this->view($proposal);
	}
	private function view(array $proposal): array {
		return array_intersect_key($proposal, array_flip(['id', 'digest', 'operation', 'toolName', 'target', 'sourceResourceId', 'sourcePath', 'destinationResourceId', 'destinationName', 'expectedEtag', 'recipientUserId', 'expires']))
			+ ['history' => 'audit-only', 'conflict' => 'native-conditions-with-preflight', 'limitation' => $proposal['operation'] === 'share_readonly'
				? 'This account will gain read access. Nextcloud activity notifications may occur; no share email is sent. Other people can change access while this runs. This action is recorded for inspection and has no agent Undo.'
				: 'Nextcloud checks the file revision and refuses to overwrite an existing destination. Other people can still change access while this runs; moving a file does not lock out all source changes. Review this action individually. It is recorded for inspection and has no agent Undo.'];
	}
	private function load(string $id, string $digest): array {
		if (!preg_match('/^[a-f0-9]{48}$/D', $id) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) throw new Problem('Invalid Files preview.', 400);
		$proposal = $this->state()[$id] ?? null;
		if (!$proposal || $proposal['expires'] <= time()) throw new Problem('This Files preview expired or was consumed.', 410);
		if (!hash_equals($proposal['digest'], $digest)) {
			$state = $this->state(); $state[$id]['state'] = 'invalidated'; $state[$id]['approved'] = false; $this->save($state);
			throw new Problem('The Files preview changed.', 409);
		}
		return $proposal;
	}
	public function approve(string $id, string $digest): array {
		$proposal = $this->pending($id, $digest);
		if ($proposal['state'] !== 'prepared') throw new Problem('This Files preview was consumed.', 410);
		$state = $this->state(); $state[$id]['approved'] = true; $this->save($state);
		return ['approved' => true];
	}
	private function pending(string $id, string $digest, bool $probe = true): array {
		$proposal = $this->load($id, $digest);
		if ($proposal['state'] !== 'prepared') throw new Problem('This Files preview was consumed.', 410);
		try { $this->validate($proposal, $probe); }
		catch (\Throwable $error) {
			$state = $this->state(); $state[$id]['state'] = 'invalidated'; $state[$id]['approved'] = false; $this->save($state);
			throw $error;
		}
		return $proposal;
	}
	public function cancel(string $id): array {
		if (!preg_match('/^(?:[a-f0-9]{48}|request-[a-f0-9-]{36})$/D', $id)) throw new Problem('Invalid Files preview.', 400);
		if (str_starts_with($id, 'request-')) {
			$cancelled = array_filter($this->session->get(self::KEY . '.cancelled') ?? [], static fn ($expiry) => $expiry > time());
			if (count($cancelled) < 100 || isset($cancelled[$id])) $cancelled[$id] = time() + 600;
			else $cancelled['overflow'] = time() + 600; // Never revive a delayed preview by evicting its tombstone.
			$this->session->set(self::KEY . '.cancelled', $cancelled);
		}
		$state = $this->state();
		foreach ($state as $key => $proposal) if ($proposal['state'] === 'prepared' && ($key === $id || ($proposal['clientRequestId'] ?? '') === $id)) unset($state[$key]);
		$this->save($state);
		return ['cancelled' => true];
	}
	public function commit(string $id, string $digest, string $binding, #[\SensitiveParameter] string $token): array {
		// DB shared read locks are cached until request shutdown even after fclose.
		// Do not open a stream in the outer HTTP request while its sibling MOVE
		// needs an exclusive native lock. The ticket guard rechecks bytes and native
		// access inside the effect request, before marking the journal dispatched.
		$proposal = $this->pending($id, $digest, false);
		if (!$proposal['approved'] || $proposal['state'] !== 'prepared') throw new Problem('Individual approval is required or already consumed.', 403);
		$state = $this->state(); $state[$id]['state'] = 'ready'; $this->save($state);
		if ($proposal['operation'] === 'share_readonly') return $this->share($proposal);
		try {
			$this->bridge->send(NativeRequest::fileOperation($this->uid(), $proposal['operation'], $proposal['sourcePath'],
				$this->bridge->base(), $proposal['parentPath'], $proposal['destinationName'], $proposal['expectedEtag'], $id, $binding, $token));
		} catch (\Throwable) {
			// Read the guarded receipt; a network failure is never a second dispatch.
		}
		$this->session->reopen();
		return $this->receipt($id);
	}
	public function claim(string $id): array {
		$proposal = $this->state()[$id] ?? null;
		if (!$proposal || $proposal['expires'] <= time() || $proposal['state'] !== 'ready') throw new Problem('The native Files ticket was consumed or expired.', 410);
		$state = $this->state(); $state[$id]['state'] = 'claimed'; $this->save($state);
		$this->validate($proposal);
		return $proposal;
	}
	public function dispatched(array $proposal): void {
		$authority = $proposal['operation'] === 'share_readonly' ? 'file:' . substr($proposal['sourceResourceId'], 16) : 'folder:' . substr($proposal['destinationResourceId'], 16);
		$reference = $proposal['operation'] === 'share_readonly' ? 'file:' . substr($proposal['sourceResourceId'], 16) : 'parent:' . substr($proposal['destinationResourceId'], 16) . '/name:' . $proposal['destinationName'];
		$entry = $this->journal->prepare($this->files->journalIdentity(), new JournalResource('files', $reference, $authority), $proposal['toolName'],
			['operation' => $proposal['operation'], 'sourceResourceId' => $proposal['sourceResourceId'], 'sourceClass' => $proposal['sourceClass'], 'target' => $proposal['target']], [],
			['sourceId' => (int)substr($proposal['sourceResourceId'], 16), 'destinationId' => (int)substr($proposal['destinationResourceId'], 16),
				'destinationName' => $proposal['destinationName'], 'contentHash' => $proposal['contentHash'], 'recipientUserId' => $proposal['recipientUserId']]);
		$this->attempt = $entry['id'];
		$state = $this->state(); $state[$proposal['id']]['attempt'] = ['id' => $entry['id'], 'ticket' => $entry['ticket']]; $this->save($state);
		$this->journal->markDispatched($this->files->journalIdentity(), $entry['id'], $entry['ticket']);
	}
	public function complete(array $proposal, File $result, ?int $shareId = null): void {
		if (!hash_equals($proposal['contentHash'], $this->digest($result))) throw new Problem('Native content readback differs from the approved source.', 409);
		$state = $this->state(); $attempt = $state[$proposal['id']]['attempt'];
		$entry = $this->journal->describe($this->files->journalIdentity(), $attempt['id']);
		$resource = new JournalResource('files', $entry['resource']['reference'], 'file:' . $result->getId());
		$this->journal->finish($this->files->journalIdentity(), $attempt['id'], $attempt['ticket'], 'verified', new VerifiedReadback($result->getEtag(), $resource));
		$receipt = ['outcome' => 'verified', 'status' => match ($proposal['operation']) { 'copy' => 'copied', 'move' => 'moved', default => 'shared' },
			'actionId' => $attempt['id'], 'history' => 'audit-only', 'shareId' => $shareId, 'parentResourceId' => 'nextcloud:files:' . $result->getParent()->getId()];
		try { $receipt['file'] = $this->files->resource('nextcloud:files:' . $result->getId()); }
		catch (\Throwable) { $receipt['disclosure'] = 'restricted'; }
		$state[$proposal['id']]['result'] = $receipt;
		$state[$proposal['id']]['state'] = 'executed'; $this->save($state);
	}
	public function failed(string $id): void {
		$state = $this->state(); $attempt = $state[$id]['attempt'] ?? null;
		if ($attempt && ($state[$id]['state'] ?? '') !== 'executed') {
			try { $this->journal->finish($this->files->journalIdentity(), $attempt['id'], $attempt['ticket'], 'unknown'); } catch (\Throwable) { /* durable intent remains inspectable */ }
		}
		if (($state[$id]['state'] ?? '') !== 'executed') { $state[$id]['state'] = !$attempt && ($state[$id]['state'] ?? '') === 'claimed' ? 'not-dispatched' : 'unknown'; $this->save($state); }
	}
	private function receipt(string $id): array {
		$state = $this->state(); $proposal = $state[$id] ?? [];
		if (isset($proposal['result'])) return $proposal['result'];
		if (($proposal['state'] ?? '') === 'not-dispatched') return ['outcome' => 'not-dispatched', 'history' => 'audit-only', 'actionId' => null, 'message' => 'The native guard rejected this ticket before the effect was dispatched.'];
		return ['outcome' => 'unknown', 'history' => 'audit-only', 'actionId' => $proposal['attempt']['id'] ?? null,
			'message' => 'The request may have reached Nextcloud. Inspect the native state and action history; do not repeat the operation automatically.'];
	}
	private function share(array $proposal): array {
		$source = $this->node($proposal['sourceResourceId']); $parent = $source->getParent();
		$parent->lock(ILockingProvider::LOCK_SHARED);
		try {
			[$source] = $this->validate($proposal);
			$this->dispatched($proposal);
			$share = $this->shares->newShare();
			$share->setNode($source)->setShareType(IShare::TYPE_USER)->setSharedWith($proposal['recipientUserId'])
				->setSharedBy($this->uid())->setPermissions(Constants::PERMISSION_READ)->setMailSend(false);
			$created = $this->quietShare->create($share);
			$state = $this->state(); $state[$proposal['id']]['shareId'] = (int)$created->getId(); $this->save($state);
			$observed = $this->shares->getShareById($created->getFullId());
			if ($observed->getNodeId() !== $source->getId() || $observed->getPermissions() !== Constants::PERMISSION_READ
				|| $observed->getShareType() !== IShare::TYPE_USER || $observed->getSharedWith() !== $proposal['recipientUserId']) throw new Problem('The native share could not be verified.', 409);
			$this->complete($proposal, $source, (int)$created->getId());
		} catch (\Throwable $error) {
			$this->logger->warning('A Files sharing action requires inspection.', ['app' => 'fadenstack_agent', 'exception' => $error]);
			$this->failed($proposal['id']);
		}
		finally { $parent->unlock(ILockingProvider::LOCK_SHARED); }
		return $this->receipt($proposal['id']);
	}
}
