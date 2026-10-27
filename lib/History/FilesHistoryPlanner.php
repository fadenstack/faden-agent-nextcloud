<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Service\FileService;

/**
 * Typed whole-text update history only. No endpoint, native write, generic recipe
 * executor or implicit authorization: current governance/approval remains required.
 */
final class FilesHistoryPlanner {
	public function __construct(
		private FilesHistorySnapshot $snapshots,
	) {
	}

	public function prepare(JournalIdentity $identity, string $actionId, string $direction): FilesHistoryPlan {
		if (!in_array($direction, ['undo', 'redo'], true)) {
			throw new JournalException('invalid_direction', 400);
		}
		$loaded = $this->snapshots->load($identity, $actionId);
		$entry = $loaded['entry'];
		if ($entry['state'] !== ($direction === 'undo' ? 'applied' : 'undone')) {
			throw new JournalException('state_conflict');
		}
		$snapshot = $entry['snapshot'];
		if (!is_string($snapshot['revision'] ?? null) || $snapshot['revision'] === '') {
			throw new JournalException('invalid_snapshot');
		}
		$forward = $snapshot['forward'];
		$inverse = $snapshot['inverse'];
		$preconditions = $snapshot['preconditions'];
		$file = $loaded['file'];
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
			$file->getId(), $preconditions['parentId'], $loaded['target'], $snapshot['revision'], $current, $content, $loaded['private']);
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
