<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Service\FileService;

/** Inspect an interrupted typed update under a native read lock. Never replays bytes. */
final class FilesHistoryReconciler {
	public const STATES = ['dispatching', 'undoing', 'redoing', 'needs_reconciliation'];

	public function __construct(
		private FilesHistorySnapshot $snapshots,
		private JournalService $journal,
	) {
	}

	public function prepare(JournalIdentity $identity, string $actionId): FilesReconciliationPlan {
		return $this->inspect($identity, $actionId, static fn ($plan) => $plan);
	}

	public function revalidate(JournalIdentity $identity, FilesReconciliationPlan $expected): FilesReconciliationPlan {
		$current = $this->prepare($identity, $expected->actionId);
		$this->same($identity, $expected, $current);
		return $current;
	}

	public function execute(JournalIdentity $identity, FilesReconciliationPlan $expected): array {
		return $this->inspect($identity, $expected->actionId, function (FilesReconciliationPlan $current) use ($identity, $expected): array {
			$this->same($identity, $expected, $current);
			// Keep the native read lock through the journal CAS and claim release.
			$result = $this->journal->reconcile($identity, $current->actionId, $current->version, $current->outcome,
				new VerifiedReadback($current->expectedEtag));
			return ['actionId' => $current->actionId, 'direction' => 'reconcile', 'state' => $result['state'],
				'version' => $result['version'], 'fileId' => $current->fileId, 'target' => $current->target,
				'revision' => $current->expectedEtag, 'inspection' => $current->outcome, 'inspectionChangedFile' => false, 'externalEffects' => 'unverified'];
		});
	}

	private function same(JournalIdentity $identity, FilesReconciliationPlan $expected, FilesReconciliationPlan $current): void {
		if (!hash_equals($identity->scopeHash, $expected->scopeHash) || !hash_equals($expected->digest, $current->digest)) {
			throw new JournalException('plan_changed');
		}
	}

	private function inspect(JournalIdentity $identity, string $actionId, callable $finish): mixed {
		$loaded = $this->snapshots->load($identity, $actionId);
		$entry = $loaded['entry'];
		if (!in_array($entry['state'], self::STATES, true) || !in_array($entry['direction'], ['apply', 'undo', 'redo'], true)) {
			throw new JournalException('state_conflict');
		}
		$file = $loaded['file'];
		if ($file->getSize() > FileService::MAX_WRITE_BYTES) {
			throw new JournalException('inspection_inconclusive');
		}
		// Public Files rb holds a shared native lock; an active r+ writer excludes it.
		// No r+ stream is opened, so this operation emits no write-open hook.
		$stream = $file->fopen('rb');
		if (!is_resource($stream)) {
			throw new JournalException('readback_unavailable');
		}
		try {
			if (!stream_get_meta_data($stream)['seekable']) {
				throw new JournalException('guarded_storage_unavailable', 422);
			}
			$revision = $file->getStorage()->getETag($file->getInternalPath());
			$bytes = stream_get_contents($stream, FileService::MAX_WRITE_BYTES + 1);
			if (!is_string($revision) || $revision === '' || !is_string($bytes) || strlen($bytes) > FileService::MAX_WRITE_BYTES
				|| $file->getStorage()->getETag($file->getInternalPath()) !== $revision) {
				throw new JournalException('inspection_inconclusive');
			}
			$snapshot = $entry['snapshot'];
			$wanted = $entry['direction'] === 'undo' ? $snapshot['inverse']['content'] : $snapshot['forward']['content'];
			$previous = $entry['direction'] === 'undo' ? $snapshot['forward']['content'] : $snapshot['inverse']['content'];
			$complete = hash_equals(hash('sha256', $wanted), hash('sha256', $bytes));
			$unchanged = hash_equals(hash('sha256', $previous), hash('sha256', $bytes));
			if ($complete === $unchanged) {
				// Partial bytes, later human content or identical before/after are not proof.
				throw new JournalException('inspection_inconclusive');
			}
			$fresh = $this->snapshots->load($identity, $actionId);
			if ($fresh['entry']['version'] !== $entry['version'] || $fresh['target'] !== $loaded['target']
				|| $fresh['file']->getId() !== $file->getId()) {
				throw new JournalException('plan_changed');
			}
			$state = $complete ? ($entry['direction'] === 'undo' ? 'undone' : 'applied')
				: match ($entry['direction']) {
					'apply' => 'failed', 'undo' => 'applied', 'redo' => 'undone'
				};
			$plan = new FilesReconciliationPlan($actionId, $entry['version'], $identity->scopeHash, $file->getId(), $loaded['target'],
				$revision, $bytes, $complete ? 'verified' : 'unchanged', $state, $entry['direction']);
			return $finish($plan);
		} finally {
			fclose($stream);
		}
	}
}
