<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Service;

use OCA\FadenstackAgent\History\FilesHistoryPlan;
use OCA\FadenstackAgent\History\FilesHistoryPlanner;
use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\VerifiedReadback;
use OCA\FadenstackAgent\Identity\VerifiedCloudPrincipal;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountManager;
use OCP\Files\Node;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;

class FileService {
	public const MAX_TEXT_BYTES = 2097152;
	public const MAX_PDF_BYTES = 10485760;
	public const MAX_WRITE_BYTES = 262144;
	private const KEY = 'fadenstack_agent.state';
	private ?VerifiedCloudPrincipal $principal = null;

	public function __construct(
		private IRootFolder $root,
		private IUserSession $users,
		private ISession $session,
		private IURLGenerator $urls,
		private Configuration $config,
		private IMountManager $mounts,
		private JournalService $journal,
		private ScopePolicy $scope,
		private FilesHistoryPlanner $history,
	) {
	}

	private function journalIdentity(): JournalIdentity {
		$state = $this->state();
		$binding = hash('sha256', json_encode([$state['binding'], $this->config->get(), $this->scope->configStamp(), $this->config->historyEnabled()], JSON_THROW_ON_ERROR));
		return $this->principal === null ? new JournalIdentity($this->uid(), JournalIdentity::nativeScope(), $binding)
			: $this->pairedHistoryIdentity($this->principal);
	}

	/** Request-local only; the controller supplies a freshly verified server profile. */
	public function bindCloudPrincipal(VerifiedCloudPrincipal $principal): void {
		$this->principal = $principal;
	}

	public function pairedHistoryIdentity(VerifiedCloudPrincipal $principal): JournalIdentity {
		$state = $this->state();
		// Durable private history survives app/policy upgrades. Current native policy
		// and configuration still invalidate tickets and previews, not ownership.
		$scope = hash('sha256', 'nextcloud-files-paired-history-v1');
		$binding = hash('sha256', json_encode([$state['binding'], $this->config->get(), $this->scope->configStamp(), $this->config->historyEnabled()], JSON_THROW_ON_ERROR));
		return JournalIdentity::forPrincipal($this->uid(), $scope, $binding, $principal);
	}

	private function assertProposalIdentity(array $proposal): void {
		$identity = $this->journalIdentity();
		if (($proposal['identityScope'] ?? null) !== $identity->scopeHash || ($proposal['identitySession'] ?? null) !== $identity->sessionHash) {
			throw new Problem('The account, device, profile or scope changed. Review a new proposal.', 409);
		}
	}

	private function prepareJournal(array $proposal, Folder $folder, Node $node): array {
		$reference = 'parent:' . $folder->getId() . '/name:' . $proposal['filename'];
		$recovery = null;
		if ($proposal['operation'] === 'update') {
			$grant = $this->scope->describeNode($node, 'history');
			// Do not infer historical protection from today's filename/classification.
			// Broader/protected-input snapshots require a separate lineage handler.
			if ($grant['sourceClass'] === 'standard' && $grant['audience'] === 'private'
				&& ($this->identity()['scopePolicy']['sourceClasses'] ?? []) === ['standard']) {
				$recovery = JournalResource::PRIVATE_TEXT_RECOVERY;
			}
		}
		$resource = new JournalResource('files', $reference, ($proposal['operation'] === 'create' ? 'folder:' . $folder->getId() : 'file:' . $node->getId()), $recovery);
		return $this->journal->prepare($this->journalIdentity(), $resource, $proposal['operation'] === 'create' ? 'nc_create_text_file' : 'nc_update_text_file',
			['operation' => $proposal['operation'], 'filename' => $proposal['filename'], 'content' => $proposal['content']],
			['operation' => $proposal['operation'] === 'create' ? 'delete_created_file' : 'update', 'content' => $proposal['before']],
			['parentId' => $folder->getId(), 'parentPath' => $this->root->getUserFolder($this->uid())->getRelativePath($folder->getPath()), 'fileId' => $proposal['operation'] === 'create' ? null : $node->getId(),
				'expectedEtag' => $proposal['expectedEtag'], 'beforeHash' => $proposal['before'] === null ? null : hash('sha256', $proposal['before']),
				'afterHash' => hash('sha256', $proposal['content'])]);
	}

	/** Legacy internal typed preview. Public paired recovery goes through HistoryService. */
	public function prepareHistory(string $actionId, string $direction): FilesHistoryPlan {
		return $this->history->prepare($this->journalIdentity(), $actionId, $direction);
	}

	private function uid(): string {
		$user = $this->users->getUser();
		if ($user === null) {
			throw new Problem('Sign in to Nextcloud.', 401);
		}
		return $user->getUID();
	}

	private function state(): array {
		$uid = $this->uid();
		$state = $this->session->get(self::KEY);
		if (!is_array($state) || ($state['user'] ?? '') !== $uid) {
			$state = ['user' => $uid, 'binding' => bin2hex(random_bytes(32)), 'contexts' => [], 'proposals' => [], 'receipts' => []];
			$this->session->set(self::KEY, $state);
		}
		return $state;
	}

	public function identity(): array {
		$s = $this->state();
		$config = $this->config->get();
		$policy = $config['enabled'] && $config['agent'] && $config['serverUrl'] ? $this->scope->getPolicy($s['user'], $config['serverUrl']) : null;
		return ['userId' => $s['user'], 'binding' => $s['binding'], ...$config, 'scopePolicy' => $policy, 'historyEnabled' => $this->config->historyEnabled()];
	}

	public function check(string $binding): void {
		$s = $this->state();
		if (!$binding || !hash_equals($s['binding'], $binding)) {
			throw new Problem('The Nextcloud session changed. Reload and sign in again.', 401);
		}
		$this->config->requireEnabled();
	}

	private function node(int $id): Node {
		if ($id < 1) {
			throw new Problem('Invalid file identifier.');
		}
		$nodes = $this->root->getUserFolder($this->uid())->getById($id);
		foreach ($nodes as $node) {
			if ($node->isReadable()) {
				return $node;
			}
		}
		throw new Problem('The file is unavailable or you do not have permission.', 404);
	}

	private function metadata(Node $n, string $purpose = 'metadata'): array {
		$grant = $this->scope->describeNode($n, $purpose);
		$home = $this->root->getUserFolder($this->uid());
		return ['id' => $n->getId(), 'name' => $n->getName(), 'path' => $home->getRelativePath($n->getPath()),
			'mime' => $n->getMimetype(), 'size' => $n->getSize(), 'etag' => $n->getEtag(),
			'folder' => $n instanceof Folder, 'permissions' => $n->getPermissions(),
			'modified' => $n->getMtime(), 'created' => $n->getCreationTime(),
			'url' => $this->urls->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $n->getId()]), 'agentScope' => $grant];
	}

	public function scopeResource(string $reference, string $purpose = 'metadata'): array {
		return $this->scope->resolve($reference, $purpose);
	}

	public function scopeReference(string $requested = 'all'): string {
		return $this->scope->searchScope($requested);
	}
	public function indexedContentAllowed(): bool {
		return $this->scope->indexedContentAllowed();
	}

	/** Resolve every resource through the current user's tree, including shares. */
	public function resource(string $resourceId): array {
		if (!preg_match('/^nextcloud:files:([1-9][0-9]{0,14})$/D', $resourceId, $match)) {
			throw new Problem('Use a Nextcloud file resource ID, not a path or URL.');
		}
		return $this->metadata($this->node((int)$match[1]));
	}

	public function assertContentAccess(string $resourceId): void {
		$file = $this->resource($resourceId);
		$n = $this->node($file['id']);
		$this->scope->describeNode($n, 'read');
		if (!$n instanceof File) {
			throw new Problem('Document contents require a file, not a folder.', 415);
		}
		// Opening through the active storage enforces wrappers such as access-control
		// rules too. No file bytes are read or returned by this authorization probe.
		$stream = $n->fopen('rb');
		if (!is_resource($stream)) {
			throw new Problem('You cannot read this document.', 403);
		}
		fclose($stream);
	}

	public function searchScope(string $scope): array {
		$scope = $this->scope->searchScope($scope);
		$path = '';
		$folder = $this->root->getUserFolder($this->uid());
		$id = $folder->getId();
		if ($scope !== 'all') {
			$file = $this->resource($scope);
			if (!$file['folder']) {
				throw new Problem('Search scope must be an accessible folder.');
			}
			$path = $file['path'];
			$id = $file['id'];
			$folder = $this->node($id);
		}
		return ['href' => '/files/' . rawurlencode($this->uid()) . implode('/', array_map('rawurlencode', explode('/', $path))), 'id' => $id,
			'can_page' => count($this->mounts->findIn(rtrim($folder->getPath(), '/') . '/')) === 0];
	}

	public function select(int $fileId): array {
		$n = $this->node($fileId);
		$s = $this->state();
		$id = bin2hex(random_bytes(24));
		$context = ['id' => $id, 'file' => $this->metadata($n),
			'parentId' => $n instanceof Folder ? $n->getId() : $n->getParent()->getId(), 'expires' => time() + 3600];
		$s['contexts'] = array_filter($s['contexts'], fn ($c) => $c['expires'] > time());
		$s['contexts'][$id] = $context;
		$s['contexts'] = array_slice($s['contexts'], -32, null, true);
		$this->session->set(self::KEY, $s);
		return $context;
	}

	private function context(string $id): array {
		$c = $this->state()['contexts'][$id] ?? null;
		if (!$c || $c['expires'] < time()) {
			throw new Problem('This file context expired. Select and include the file again.', 410);
		}
		return $c;
	}

	private function selected(string $id): Node {
		$c = $this->context($id);
		$n = $this->node($c['file']['id']);
		if ($n->getEtag() !== $c['file']['etag'] || $this->metadata($n)['path'] !== $c['file']['path']) {
			throw new Problem('The selected file changed. Select and include it again.', 409);
		}
		return $n;
	}

	private function bytes(File $f, int $limit): string {
		if ($f->getSize() > $limit) {
			throw new Problem('This file exceeds the supported size limit.', 413);
		}
		$stream = $f->fopen('rb');
		if (!is_resource($stream)) {
			throw new Problem('Could not read the file.', 422);
		}
		try {
			$bytes = stream_get_contents($stream, $limit + 1);
		} finally {
			fclose($stream);
		}
		if ($bytes === false || strlen($bytes) > $limit) {
			throw new Problem('This file exceeds the supported size limit.', 413);
		}
		return $bytes;
	}

	private function text(File $f, int $limit): string {
		if (!preg_match('/\.(txt|md|markdown|csv|json)$/iD', $f->getName())) {
			throw new Problem('Supported documents: UTF-8 TXT, Markdown, CSV, JSON and text PDF. This format is unsupported.', 415);
		}
		$text = $this->bytes($f, $limit);
		if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
			throw new Problem('The document must contain UTF-8 text.', 415);
		}
		return $text;
	}

	public function read(string $contextId): array {
		$n = $this->selected($contextId);
		$this->scope->describeNode($n, 'read');
		if (!$n instanceof File) {
			throw new Problem('Use folder listing for a folder.', 415);
		}
		$text = $this->text($n, self::MAX_TEXT_BYTES);
		$this->selected($contextId); // detect changes during the read
		$s = $this->state();
		$s['contexts'][$contextId]['readEtag'] = $n->getEtag();
		$this->session->set(self::KEY, $s);
		$length = mb_strlen($text, 'UTF-8');
		return ['file' => $this->metadata($n), 'text' => mb_substr($text, 0, 50000, 'UTF-8'),
			'partial' => $length > 50000, 'totalCharacters' => $length, 'processedCharacters' => min($length, 50000)];
	}

	public function pdf(string $contextId): string {
		$n = $this->selected($contextId);
		$this->scope->describeNode($n, 'read');
		if (!$n instanceof File || $n->getMimetype() !== 'application/pdf') {
			throw new Problem('This is not a PDF document.', 415);
		}
		$bytes = $this->bytes($n, self::MAX_PDF_BYTES);
		$this->selected($contextId);
		return $bytes;
	}

	public function listing(string $contextId): array {
		$c = $this->context($contextId);
		$folder = $this->node($c['parentId']);
		if (!$folder instanceof Folder) {
			throw new Problem('The folder is unavailable.', 404);
		}
		$files = [];
		$partial = false;
		foreach ($folder->getDirectoryListing() as $n) {
			if (!$n->isReadable()) {
				continue;
			}
			if (count($files) >= 200) {
				$partial = true;
				break;
			}
			$files[] = $this->metadata($n);
		}
		return ['folder' => $this->metadata($folder), 'files' => $files, 'partial' => $partial];
	}

	public static function filename(string $name): void {
		if ($name !== trim($name) || strlen($name) > 200
			|| preg_match('/[\x00-\x1f\x7f\/\\\\:<>"|?*]/', $name)
			|| $name === '' || $name[0] === '.' || !preg_match('/\.(txt|md)$/iD', $name)) {
			throw new Problem('Use a simple .txt or .md filename without a path or reserved characters.');
		}
	}

	/** Call after flushing a guarded stream, before releasing its native write lock. */
	public static function updateStreamMetadata(File $file): void {
		$storage = $file->getStorage();
		$path = $file->getInternalPath();
		// The updater propagates the actual size delta and parent metadata. Its
		// scanner may reuse an ETag for same-second local writes, so explicitly
		// rescan this one file without reuse while our exclusive lock is held.
		$storage->getUpdater()->update($path);
		if ($storage->getScanner()->scanFile($path, 0, -1, null, false) === null) {
			throw new Problem('The saved metadata could not be confirmed. Inspect the file before retrying.', 409);
		}
	}

	public function propose(string $contextId, string $operation, string $filename, string $content, string $expectedEtag): array {
		if (strlen($content) > self::MAX_WRITE_BYTES || !mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
			throw new Problem('The replacement must be UTF-8 text of at most 256 KiB.', 413);
		}
		$c = $this->context($contextId);
		$n = $this->selected($contextId);
		$folder = $this->node($c['parentId']);
		if (!$folder instanceof Folder) {
			throw new Problem('Invalid destination.', 404);
		}
		$before = null;
		if ($operation === 'create') {
			$this->scope->assertMovement($n, $folder);
			self::filename($filename);
			if (!$folder->isCreatable()) {
				throw new Problem('You cannot create files in this folder.', 403);
			}
			if ($folder->nodeExists($filename)) {
				throw new Problem('A file with this name already exists.', 409);
			}
		} elseif ($operation === 'update') {
			$this->scope->assertMovement($n, $n);
			if (!$n instanceof File || !$n->isUpdateable()) {
				throw new Problem('You cannot update this file.', 403);
			}
			$filename = $n->getName();
			self::filename($filename);
			if (!$expectedEtag || ($c['readEtag'] ?? '') !== $expectedEtag || $n->getEtag() !== $expectedEtag) {
				throw new Problem('Read the current file before proposing an update.', 409);
			}
			$before = $this->text($n, self::MAX_WRITE_BYTES);
			$this->selected($contextId);
		} else {
			throw new Problem('Unsupported operation.');
		}
		$p = ['id' => bin2hex(random_bytes(24)), 'contextId' => $contextId, 'operation' => $operation,
			'filename' => $filename, 'content' => $content, 'before' => $before, 'expectedEtag' => $expectedEtag,
			'folderId' => $folder->getId(), 'target' => rtrim($this->root->getUserFolder($this->uid())->getRelativePath($folder->getPath()), '/') . '/' . $filename,
			'expires' => time() + 600, 'approved' => false];
		$identity = $this->journalIdentity();
		$p['identityScope'] = $identity->scopeHash;
		$p['identitySession'] = $identity->sessionHash;
		$s = $this->state();
		$s['proposals'] = array_filter($s['proposals'], fn ($p) => $p['expires'] > time());
		if (count($s['proposals']) >= 10) {
			throw new Problem('Too many pending proposals. Wait for them to expire.', 429);
		}
		$s['proposals'][$p['id']] = $p;
		$this->session->set(self::KEY, $s);
		return $p;
	}

	private function proposal(string $id): array {
		$p = $this->state()['proposals'][$id] ?? null;
		if (!$p || $p['expires'] < time()) {
			throw new Problem('The proposal expired or was already consumed.', 410);
		}
		return $p;
	}

	public function approve(string $id): array {
		$p = $this->proposal($id);
		$this->assertProposalIdentity($p);
		$this->selected($p['contextId']);
		$s = $this->state();
		$s['proposals'][$id]['approved'] = true;
		$this->session->set(self::KEY, $s);
		return ['approved' => true];
	}

	public function commit(string $id, int $createProtocol = 0): array {
		$p = $this->proposal($id);
		$this->assertProposalIdentity($p);
		if (!$p['approved']) {
			throw new Problem('This operation requires explicit approval.', 403);
		}
		if ($p['operation'] === 'create' && $createProtocol !== 1) {
			throw new Problem('Reload the Nextcloud page to load the updated file-creation client. No file was created.', 409);
		}
		$s = $this->state();
		unset($s['proposals'][$id]); // one attempt, including failed attempts
		$this->session->set(self::KEY, $s);
		$n = $this->selected($p['contextId']);
		$folder = $this->node($p['folderId']);
		if (!$folder instanceof Folder || ($n instanceof File && $n->getParent()->getId() !== $folder->getId())) {
			throw new Problem('The destination changed.', 409);
		}
		if ($p['operation'] === 'create') {
			$this->scope->assertMovement($n, $folder);
			if (!$folder->isCreatable()) {
				throw new Problem('You cannot create files in this folder.', 403);
			}
			if ($folder->nodeExists($p['filename'])) {
				throw new Problem('A file with this name already exists.', 409);
			}
			$journal = $this->prepareJournal($p, $folder, $n);
			$parent = $this->metadata($folder, 'write');
			$base = '/files/' . rawurlencode($this->uid()) . '/' . implode('/', array_map('rawurlencode', explode('/', trim($parent['path'], '/'))));
			$base = rtrim($base, '/') . '/';
			$s = $this->state();
			$s['receipts'] = array_filter($s['receipts'] ?? [], fn ($r) => $r['expires'] > time());
			if (count($s['receipts']) >= 10) {
				throw new Problem('Too many unfinished creations. Wait for them to expire.', 429);
			}
			$s['receipts'][$p['id']] = ['folderId' => $folder->getId(), 'folderPath' => $parent['path'], 'filename' => $p['filename'],
				'hash' => hash('sha256', $p['content']), 'expires' => time() + 600, 'journal' => $journal,
				'identityScope' => $p['identityScope'], 'identitySession' => $p['identitySession']];
			$this->session->set(self::KEY, $s);
			// From this point a client may dispatch the native conditional PUT. Never
			// infer that an interrupted/missing finish call means the file was not saved.
			$this->journal->markDispatched($this->journalIdentity(), $journal['id'], $journal['ticket']);
			// Native DAV owns atomic If-None-Match creation, storage hooks and locks.
			return ['kind' => 'dav-create', 'receiptId' => $p['id'], 'dav_path' => $base . rawurlencode($p['filename']),
				'parent_resource_id' => 'nextcloud:files:' . $parent['id'], 'parent_path' => $parent['path'], 'parent_etag' => $parent['etag'], 'content' => $p['content']];
		} else {
			$this->scope->assertMovement($n, $n);
			if (!$n instanceof File || !$n->isUpdateable()) {
				throw new Problem('You cannot update this file.', 403);
			}
			$journal = $this->prepareJournal($p, $folder, $n);
			$dispatched = false;
			// r+ acquires Nextcloud's exclusive write lock without truncating the file.
			// A separate Node::lock followed by putContent would conflict with its internal lock upgrade.
			try {
				$stream = $n->fopen('r+');
				if (!is_resource($stream)) {
					throw new Problem('This storage does not support guarded text updates.', 422);
				}
				try {
					$etag = $n->getStorage()->getETag($n->getInternalPath());
					if ($etag !== $p['expectedEtag']) {
						throw new Problem('Another user changed the file. Read it again; no changes were saved.', 409);
					}
					if (!stream_get_meta_data($stream)['seekable'] || !rewind($stream)) {
						throw new Problem('This storage does not support guarded text updates.', 422);
					}
					$current = stream_get_contents($stream, self::MAX_WRITE_BYTES + 1);
					if (!is_string($current) || !hash_equals(hash('sha256', $p['before']), hash('sha256', $current))) {
						throw new Problem('Another user changed the file. Read it again; no changes were saved.', 409);
					}
					if (!rewind($stream)) {
						throw new Problem('This storage does not support guarded text updates.', 422);
					}
					$this->journal->markDispatched($this->journalIdentity(), $journal['id'], $journal['ticket']);
					$dispatched = true;
					if (!ftruncate($stream, 0)) {
						throw new Problem('The storage could not complete the write. Inspect the file before retrying.', 500);
					}
					$offset = 0;
					while ($offset < strlen($p['content'])) {
						$written = fwrite($stream, substr($p['content'], $offset));
						if ($written === false || $written === 0) {
							throw new Problem('The storage could not complete the write. Inspect the file before retrying.', 500);
						}
						$offset += $written;
					}
					if (!fflush($stream)) {
						throw new Problem('The storage could not complete the write. Inspect the file before retrying.', 500);
					}
					// fopen's node hooks run before stream changes. Refresh the native
					// cache through its public updater before releasing the write lock.
					self::updateStreamMetadata($n);
				} finally {
					fclose($stream); // Releases Nextcloud's write lock.
				}
			} catch (\Throwable $error) {
				if ($dispatched) {
					$this->journal->finish($this->journalIdentity(), $journal['id'], $journal['ticket'], 'unknown');
				} else {
					$this->journal->abort($this->journalIdentity(), $journal['id'], $journal['ticket']);
				}
				throw $error;
			}
			$result = $this->node($n->getId());
			if (!$result instanceof File || !hash_equals(hash('sha256', $p['content']), hash('sha256', $this->bytes($result, self::MAX_WRITE_BYTES)))) {
				$this->journal->finish($this->journalIdentity(), $journal['id'], $journal['ticket'], 'unknown');
				throw new Problem('The saved result could not be confirmed. Inspect the file before retrying.', 409);
			}
			$this->journal->finish($this->journalIdentity(), $journal['id'], $journal['ticket'], 'verified', new VerifiedReadback($result->getStorage()->getETag($result->getInternalPath())));
		}
		return ['operation' => $p['operation'], 'file' => $this->metadata($this->node($result->getId()), 'write'), 'actionId' => $journal['id']];
	}

	public function finishCreate(string $receiptId): array {
		$s = $this->state();
		$r = $s['receipts'][$receiptId] ?? null;
		if (!$r || $r['expires'] < time()) {
			throw new Problem('Creation receipt expired or was already consumed.', 410);
		}
		$this->assertProposalIdentity($r);
		unset($s['receipts'][$receiptId]);
		$this->session->set(self::KEY, $s);
		$folder = $this->node($r['folderId']);
		if (!$folder instanceof Folder || $this->root->getUserFolder($this->uid())->getRelativePath($folder->getPath()) !== $r['folderPath']) {
			throw new Problem('The destination folder changed. No success is claimed.', 409);
		}
		$file = $folder->get($r['filename']);
		if (!$file instanceof File || !$file->isReadable() || !hash_equals($r['hash'], hash('sha256', $this->bytes($file, self::MAX_WRITE_BYTES)))) {
			$this->journal->finish($this->journalIdentity(), $r['journal']['id'], $r['journal']['ticket'], 'unknown');
			throw new Problem('The created content changed or the write was incomplete. No success is claimed.', 409);
		}
		$resource = new JournalResource('files', 'parent:' . $folder->getId() . '/name:' . $r['filename'], 'file:' . $file->getId());
		$this->journal->finish($this->journalIdentity(), $r['journal']['id'], $r['journal']['ticket'], 'verified', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath()), $resource));
		return ['operation' => 'create', 'file' => $this->metadata($file, 'write'), 'actionId' => $r['journal']['id']];
	}
}
