<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;

/**
 * Typed whole-text update history only. No endpoint, native write, generic recipe
 * executor or implicit authorization: current governance/approval remains required.
 */
final class FilesHistoryPlanner {
	public function __construct(
		private JournalService $journal,
		private IRootFolder $root,
		private IUserSession $users,
		private ScopePolicy $scope,
	) {
	}

	public function prepare(JournalIdentity $identity, string $actionId, string $direction): FilesHistoryPlan {
		if (!in_array($direction, ['undo', 'redo'], true)) {
			throw new JournalException('invalid_direction', 400);
		}
		if ($this->users->getUser()?->getUID() !== $identity->userId) {
			throw new JournalException('permission_lost', 403);
		}
		// Native ACL/scope and journal ownership are checked before payload decryption.
		$entry = $this->journal->preview($identity, $actionId);
		if ($entry['operation'] !== 'nc_update_text_file') {
			throw new JournalException('typed_inverse_unavailable', 422);
		}
		if ($entry['state'] !== ($direction === 'undo' ? 'applied' : 'undone')) {
			throw new JournalException('state_conflict');
		}
		$snapshot = $entry['snapshot'];
		if (($snapshot['schema'] ?? null) !== 1 || !is_array($snapshot['forward'] ?? null)
			|| !is_array($snapshot['inverse'] ?? null) || !is_array($snapshot['preconditions'] ?? null)) {
			throw new JournalException('invalid_snapshot');
		}
		$forward = $snapshot['forward'];
		$inverse = $snapshot['inverse'];
		$preconditions = $snapshot['preconditions'];
		foreach ([$forward, $inverse] as $recipe) {
			if (($recipe['operation'] ?? null) !== 'update' || !is_string($recipe['content'] ?? null)
				|| strlen($recipe['content']) > FileService::MAX_WRITE_BYTES || !mb_check_encoding($recipe['content'], 'UTF-8')
				|| str_contains($recipe['content'], "\0")) {
				throw new JournalException('invalid_snapshot');
			}
		}
		if (!is_int($preconditions['fileId'] ?? null) || $preconditions['fileId'] < 1
			|| !is_int($preconditions['parentId'] ?? null) || $preconditions['parentId'] < 1
			|| !is_string($preconditions['parentPath'] ?? null) || !is_string($forward['filename'] ?? null)
			|| !is_string($snapshot['revision'] ?? null) || $snapshot['revision'] === ''
			|| ($entry['resource']['provider'] ?? null) !== 'files'
			|| ($entry['resource']['authorityReference'] ?? null) !== 'file:' . $preconditions['fileId']
			|| ($entry['resource']['reference'] ?? null) !== 'parent:' . $preconditions['parentId'] . '/name:' . $forward['filename']) {
			throw new JournalException('invalid_snapshot');
		}
		FileService::filename($forward['filename']);
		if (($preconditions['beforeHash'] ?? null) !== hash('sha256', $inverse['content'])
			|| ($preconditions['afterHash'] ?? null) !== hash('sha256', $forward['content'])) {
			throw new JournalException('invalid_snapshot');
		}
		$home = $this->root->getUserFolder($identity->userId);
		$file = null;
		foreach ($home->getById($preconditions['fileId']) as $candidate) {
			if ($candidate instanceof File && $candidate->getParent()->getId() === $preconditions['parentId']
				&& $candidate->getName() === $forward['filename']
				&& $home->getRelativePath($candidate->getParent()->getPath()) === $preconditions['parentPath']) {
				$file = $candidate;
				break;
			}
		}
		if ($file === null) {
			throw new JournalException('native_conflict');
		}
		$this->scope->assertMovement($file, $file);
		$expected = $direction === 'undo' ? $forward['content'] : $inverse['content'];
		$content = $direction === 'undo' ? $inverse['content'] : $forward['content'];
		if ($file->getSize() > FileService::MAX_WRITE_BYTES || $file->getStorage()->getETag($file->getInternalPath()) !== $snapshot['revision']) {
			throw new JournalException('native_conflict');
		}
		$stream = $file->fopen('rb');
		if (!is_resource($stream)) {
			throw new JournalException('permission_lost', 403);
		}
		try {
			$current = stream_get_contents($stream, FileService::MAX_WRITE_BYTES + 1);
		} finally {
			fclose($stream);
		}
		if (!is_string($current) || !hash_equals(hash('sha256', $expected), hash('sha256', $current))
			|| $file->getStorage()->getETag($file->getInternalPath()) !== $snapshot['revision']) {
			throw new JournalException('native_conflict');
		}
		// This preview cannot exclude later native writers. Commit must repeat these
		// checks under the broker's existing exclusive stream before changing bytes.
		return new FilesHistoryPlan($actionId, $direction, $entry['version'], $identity->scopeHash,
			$file->getId(), $preconditions['parentId'], $home->getRelativePath($file->getPath()), $snapshot['revision'], $current, $content);
	}

	public function revalidate(JournalIdentity $identity, FilesHistoryPlan $plan): FilesHistoryPlan {
		if (!hash_equals($identity->scopeHash, $plan->scopeHash)) {
			throw new JournalException('scope_changed');
		}
		$current = $this->prepare($identity, $plan->actionId, $plan->direction);
		if (!hash_equals($plan->digest, $current->digest)) {
			throw new JournalException('plan_changed');
		}
		return $current;
	}
}
