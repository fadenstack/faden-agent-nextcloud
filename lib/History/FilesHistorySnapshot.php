<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;

/** Shared typed target/recipe validation. No write and no execution authorization. */
final class FilesHistorySnapshot {

	public function __construct(
		private JournalService $journal,
		private IRootFolder $root,
		private IUserSession $users,
		private ScopePolicy $scope,
	) {
	}

	public function load(JournalIdentity $identity, string $actionId): array {
		if ($this->users->getUser()?->getUID() !== $identity->userId) {
			throw new JournalException('permission_lost', 403);
		}
		// Ownership, native ACL and scope precede payload decryption.
		$entry = $this->journal->preview($identity, $actionId);
		if ($entry['operation'] !== 'nc_update_text_file') {
			throw new JournalException('typed_inverse_unavailable', 422);
		}
		$snapshot = $entry['snapshot'];
		if (($snapshot['schema'] ?? null) !== 1 || !is_array($snapshot['forward'] ?? null)
			|| !is_array($snapshot['inverse'] ?? null) || !is_array($snapshot['preconditions'] ?? null)) {
			throw new JournalException('invalid_snapshot');
		}
		$forward = $snapshot['forward'];
		$inverse = $snapshot['inverse'];
		$p = $snapshot['preconditions'];
		foreach ([$forward, $inverse] as $recipe) {
			if (($recipe['operation'] ?? null) !== 'update' || !is_string($recipe['content'] ?? null)
				|| strlen($recipe['content']) > FileService::MAX_WRITE_BYTES || !mb_check_encoding($recipe['content'], 'UTF-8')
				|| str_contains($recipe['content'], "\0")) {
				throw new JournalException('invalid_snapshot');
			}
		}
		if (!is_int($p['fileId'] ?? null) || $p['fileId'] < 1 || !is_int($p['parentId'] ?? null) || $p['parentId'] < 1
			|| !is_string($p['parentPath'] ?? null) || !is_string($forward['filename'] ?? null)
			|| ($entry['resource']['provider'] ?? null) !== 'files'
			|| ($entry['resource']['authorityReference'] ?? null) !== 'file:' . $p['fileId']
			|| ($entry['resource']['reference'] ?? null) !== 'parent:' . $p['parentId'] . '/name:' . $forward['filename']
			|| ($p['beforeHash'] ?? null) !== hash('sha256', $inverse['content'])
			|| ($p['afterHash'] ?? null) !== hash('sha256', $forward['content'])) {
			throw new JournalException('invalid_snapshot');
		}
		FileService::filename($forward['filename']);
		$home = $this->root->getUserFolder($identity->userId);
		foreach ($home->getById($p['fileId']) as $file) {
			if ($file instanceof File && $file->getParent()->getId() === $p['parentId'] && $file->getName() === $forward['filename']
				&& $home->getRelativePath($file->getParent()->getPath()) === $p['parentPath']) {
				$this->scope->assertMovement($file, $file);
				$private = ($entry['resource']['recovery'] ?? null) === JournalResource::PRIVATE_TEXT_RECOVERY;
				if ($private) {
					$grant = $this->scope->describeNode($file, 'history');
					if ($grant['sourceClass'] !== 'standard' || $grant['audience'] !== 'private') {
						throw new JournalException('recovery_protection_changed', 403);
					}
				}
				return ['entry' => $entry, 'file' => $file, 'target' => $home->getRelativePath($file->getPath()), 'private' => $private];
			}
		}
		throw new JournalException('native_conflict');
	}
}
