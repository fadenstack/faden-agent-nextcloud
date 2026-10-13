<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Service;

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

	public function __construct(
		private IRootFolder $root,
		private IUserSession $users,
		private ISession $session,
		private IURLGenerator $urls,
		private Configuration $config,
		private IMountManager $mounts,
	) {
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
		return ['userId' => $s['user'], 'binding' => $s['binding'], ...$this->config->get()];
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

	private function metadata(Node $n): array {
		$home = $this->root->getUserFolder($this->uid());
		return ['id' => $n->getId(), 'name' => $n->getName(), 'path' => $home->getRelativePath($n->getPath()),
			'mime' => $n->getMimetype(), 'size' => $n->getSize(), 'etag' => $n->getEtag(),
			'folder' => $n instanceof Folder, 'permissions' => $n->getPermissions(),
			'modified' => $n->getMtime(), 'created' => $n->getCreationTime(),
			'url' => $this->urls->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $n->getId()])];
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
			self::filename($filename);
			if (!$folder->isCreatable()) {
				throw new Problem('You cannot create files in this folder.', 403);
			}
			if ($folder->nodeExists($filename)) {
				throw new Problem('A file with this name already exists.', 409);
			}
		} elseif ($operation === 'update') {
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
			'folderId' => $folder->getId(), 'target' => rtrim($this->metadata($folder)['path'], '/') . '/' . $filename,
			'expires' => time() + 600, 'approved' => false];
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
		$this->selected($p['contextId']);
		$s = $this->state();
		$s['proposals'][$id]['approved'] = true;
		$this->session->set(self::KEY, $s);
		return ['approved' => true];
	}

	public function commit(string $id, int $createProtocol = 0): array {
		$p = $this->proposal($id);
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
			if (!$folder->isCreatable()) {
				throw new Problem('You cannot create files in this folder.', 403);
			}
			if ($folder->nodeExists($p['filename'])) {
				throw new Problem('A file with this name already exists.', 409);
			}
			$parent = $this->metadata($folder);
			$base = '/files/' . rawurlencode($this->uid()) . '/' . implode('/', array_map('rawurlencode', explode('/', trim($parent['path'], '/'))));
			$base = rtrim($base, '/') . '/';
			$s = $this->state();
			$s['receipts'] = array_filter($s['receipts'] ?? [], fn ($r) => $r['expires'] > time());
			if (count($s['receipts']) >= 10) {
				throw new Problem('Too many unfinished creations. Wait for them to expire.', 429);
			}
			$s['receipts'][$p['id']] = ['folderId' => $folder->getId(), 'folderPath' => $parent['path'], 'filename' => $p['filename'],
				'hash' => hash('sha256', $p['content']), 'expires' => time() + 600];
			$this->session->set(self::KEY, $s);
			// Native DAV owns atomic If-None-Match creation, storage hooks and locks.
			return ['kind' => 'dav-create', 'receiptId' => $p['id'], 'dav_path' => $base . rawurlencode($p['filename']),
				'parent_resource_id' => 'nextcloud:files:' . $parent['id'], 'parent_path' => $parent['path'], 'parent_etag' => $parent['etag'], 'content' => $p['content']];
		} else {
			if (!$n instanceof File || !$n->isUpdateable()) {
				throw new Problem('You cannot update this file.', 403);
			}
			// r+ acquires Nextcloud's exclusive write lock without truncating the file.
			// A separate Node::lock followed by putContent would conflict with its internal lock upgrade.
			$stream = $n->fopen('r+');
			if (!is_resource($stream)) {
				throw new Problem('This storage does not support guarded text updates.', 422);
			}
			try {
				$etag = $n->getStorage()->getETag($n->getInternalPath());
				if ($etag !== $p['expectedEtag']) {
					throw new Problem('Another user changed the file. Read it again; no changes were saved.', 409);
				}
				if (!stream_get_meta_data($stream)['seekable'] || !rewind($stream) || !ftruncate($stream, 0)) {
					throw new Problem('This storage does not support guarded text updates.', 422);
				}
				$offset = 0;
				while ($offset < strlen($p['content'])) {
					$written = fwrite($stream, substr($p['content'], $offset));
					if ($written === false || $written === 0) {
						throw new Problem('The storage could not complete the write. Inspect the file before retrying.', 500);
					}
					$offset += $written;
				}
			} finally {
				fclose($stream); // Nextcloud updates metadata, fires hooks and releases its write lock.
			}
			$result = $n;
		}
		return ['operation' => $p['operation'], 'file' => $this->metadata($this->node($result->getId()))];
	}

	public function finishCreate(string $receiptId): array {
		$s = $this->state();
		$r = $s['receipts'][$receiptId] ?? null;
		if (!$r || $r['expires'] < time()) {
			throw new Problem('Creation receipt expired or was already consumed.', 410);
		}
		unset($s['receipts'][$receiptId]);
		$this->session->set(self::KEY, $s);
		$folder = $this->node($r['folderId']);
		if (!$folder instanceof Folder || $this->metadata($folder)['path'] !== $r['folderPath']) {
			throw new Problem('The destination folder changed. No success is claimed.', 409);
		}
		$file = $folder->get($r['filename']);
		if (!$file instanceof File || !$file->isReadable() || !hash_equals($r['hash'], hash('sha256', $this->bytes($file, self::MAX_WRITE_BYTES)))) {
			throw new Problem('The created content changed or the write was incomplete. No success is claimed.', 409);
		}
		return ['operation' => 'create', 'file' => $this->metadata($file)];
	}
}
