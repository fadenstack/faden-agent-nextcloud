<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Service\FileService;

/** Rebind a proven settled text state to its current native revision. Never writes a file. */
final class FilesRevisionRevalidator {
	public function __construct(
		private FilesHistorySnapshot $snapshots,
		private JournalService $journal,
	) {
	}

	/** Provisional metadata decision only. Exact bytes are checked during prepare/execute. */
	public function needed(JournalIdentity $identity, string $actionId): bool {
		$loaded = $this->load($identity, $actionId);
		return $loaded['file']->getStorage()->getETag($loaded['file']->getInternalPath()) !== $loaded['entry']['snapshot']['revision'];
	}

	public function prepare(JournalIdentity $identity, string $actionId): FilesRevalidationPlan {
		return $this->inspect($identity, $actionId, static fn ($plan) => $plan);
	}

	public function revalidate(JournalIdentity $identity, FilesRevalidationPlan $expected): FilesRevalidationPlan {
		$current = $this->prepare($identity, $expected->actionId);
		$this->same($identity, $expected, $current);
		return $current;
	}

	public function execute(JournalIdentity $identity, FilesRevalidationPlan $expected): array {
		return $this->inspect($identity, $expected->actionId, function (FilesRevalidationPlan $current) use ($identity, $expected): array {
			$this->same($identity, $expected, $current);
			// The native read lock remains held through quota/ordering checks and the journal CAS.
			$result = $this->journal->refreshRevision($identity, $current->actionId, $current->version,
				$current->recordedRevision, new VerifiedReadback($current->expectedEtag));
			return ['actionId' => $current->actionId, 'direction' => 'revalidate', 'state' => $result['state'],
				'version' => $result['version'], 'fileId' => $current->fileId, 'target' => $current->target,
				'revision' => $current->expectedEtag, 'recordedRevision' => $current->recordedRevision,
				'revalidationChangedFile' => false, 'externalEffects' => 'unverified'];
		});
	}

	private function same(JournalIdentity $identity, FilesRevalidationPlan $expected, FilesRevalidationPlan $current): void {
		if (!hash_equals($identity->scopeHash, $expected->scopeHash) || !hash_equals($expected->digest, $current->digest)) {
			throw new JournalException('plan_changed');
		}
	}

	private function load(JournalIdentity $identity, string $actionId): array {
		$loaded = $this->snapshots->load($identity, $actionId);
		$entry = $loaded['entry'];
		if (!$loaded['private'] || !in_array($entry['state'], ['applied', 'undone'], true)) {
			throw new JournalException('revalidation_unavailable', 422);
		}
		if (!is_string($entry['snapshot']['revision']) || $entry['snapshot']['revision'] === '') {
			throw new JournalException('invalid_snapshot');
		}
		$file = $loaded['file'];
		if (!$file->isUpdateable() || !$file->getStorage()->isLocal() || $file->isEncrypted()) {
			throw new JournalException('guarded_storage_unavailable', 422);
		}
		return $loaded;
	}

	private function inspect(JournalIdentity $identity, string $actionId, callable $finish): mixed {
		$loaded = $this->load($identity, $actionId);
		$entry = $loaded['entry'];
		$file = $loaded['file'];
		if ($file->getSize() > FileService::MAX_WRITE_BYTES) {
			throw new JournalException('revalidation_content_changed');
		}
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
			$snapshot = $entry['snapshot'];
			$expected = $entry['state'] === 'applied' ? $snapshot['forward']['content'] : $snapshot['inverse']['content'];
			if (!is_string($revision) || $revision === '' || !is_string($bytes) || strlen($bytes) > FileService::MAX_WRITE_BYTES
				|| $file->getStorage()->getETag($file->getInternalPath()) !== $revision
				|| hash_equals(hash('sha256', $snapshot['forward']['content']), hash('sha256', $snapshot['inverse']['content']))
				|| !hash_equals(hash('sha256', $expected), hash('sha256', $bytes))) {
				throw new JournalException('revalidation_content_changed');
			}
			if (hash_equals($snapshot['revision'], $revision)) {
				throw new JournalException('revalidation_not_needed');
			}
			$fresh = $this->load($identity, $actionId);
			if ($fresh['entry']['version'] !== $entry['version'] || $fresh['target'] !== $loaded['target'] || $fresh['file']->getId() !== $file->getId()) {
				throw new JournalException('plan_changed');
			}
			return $finish(new FilesRevalidationPlan($actionId, $entry['version'], $identity->scopeHash, $file->getId(),
				$file->getParent()->getId(), $loaded['target'], $snapshot['revision'], $revision, $bytes, $entry['state']));
		} finally {
			fclose($stream);
		}
	}
}
