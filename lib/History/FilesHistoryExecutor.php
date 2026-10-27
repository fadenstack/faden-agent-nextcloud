<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;

/**
 * Typed whole-text inverse executor. HistoryService obtains current central
 * authorization and individual approval. The plan/digest is not a credential.
 */
final class FilesHistoryExecutor {
	public function __construct(
		private FilesHistoryPlanner $planner,
		private JournalService $journal,
		private IRootFolder $root,
		private IUserSession $users,
		private ScopePolicy $scope,
	) {
	}

	public function execute(JournalIdentity $identity, FilesHistoryPlan $plan): array {
		$this->planner->revalidate($identity, $plan);
		$claim = $this->journal->claim($identity, $plan->actionId, $plan->direction, $plan->version);
		$dispatched = false;
		try {
			$file = $this->file($identity, $plan);
			$this->movement($file, $plan);
			// The same public native stream/lock used by the existing Files broker.
			// Opening must not truncate; check the actual bytes and revision under it.
			$stream = $file->fopen('r+');
			if (!is_resource($stream)) {
				throw new JournalException('guarded_storage_unavailable', 422);
			}
			try {
				if (!stream_get_meta_data($stream)['seekable'] || !rewind($stream)) {
					throw new JournalException('guarded_storage_unavailable', 422);
				}
				$this->file($identity, $plan);
				$this->movement($file, $plan, false);
				$before = stream_get_contents($stream, FileService::MAX_WRITE_BYTES + 1);
				if ($this->revision($file) !== $plan->expectedEtag || !is_string($before)
					|| !hash_equals(hash('sha256', $plan->before), hash('sha256', $before))) {
					throw new JournalException('native_conflict');
				}
				if (!rewind($stream)) {
					throw new JournalException('guarded_storage_unavailable', 422);
				}
				// Persist the intent before the first destructive native operation.
				$this->journal->markDispatched($identity, $plan->actionId, $claim['ticket']);
				$dispatched = true;
				if (!ftruncate($stream, 0)) {
					throw new JournalException('native_write_failed', 500);
				}
				$offset = 0;
				while ($offset < strlen($plan->content)) {
					$written = fwrite($stream, substr($plan->content, $offset));
					if ($written === false || $written === 0) {
						throw new JournalException('native_write_failed', 500);
					}
					$offset += $written;
				}
				if (!fflush($stream) || !rewind($stream)) {
					throw new JournalException('native_write_failed', 500);
				}
				$written = stream_get_contents($stream, FileService::MAX_WRITE_BYTES + 1);
				if (!is_string($written) || !hash_equals(hash('sha256', $plan->content), hash('sha256', $written))) {
					throw new JournalException('native_write_failed', 500);
				}
				// Native fopen emits node hooks when opened, before these bytes exist.
				// Update metadata through the public updater while still holding the lock.
				FileService::updateStreamMetadata($file);
			} finally {
				// Closing releases the native write lock.
				if (!fclose($stream)) {
					throw new JournalException('native_write_failed', 500);
				}
			}
			$file = $this->file($identity, $plan);
			$this->movement($file, $plan);
			$revision = $this->readback($file, $plan->content);
			$result = $this->journal->finish($identity, $plan->actionId, $claim['ticket'], 'verified', new VerifiedReadback($revision));
			return ['actionId' => $plan->actionId, 'direction' => $plan->direction, 'state' => $result['state'],
				'version' => $result['version'], 'fileId' => $plan->fileId, 'target' => $plan->target, 'revision' => $revision];
		} catch (\Throwable $error) {
			try {
				if ($dispatched) {
					$this->journal->finish($identity, $plan->actionId, $claim['ticket'], 'unknown');
				} else {
					$this->journal->abort($identity, $plan->actionId, $claim['ticket']);
				}
			} catch (\Throwable) {
				// A durable dispatch/claim remains unresolved if persistence is down.
				throw new JournalException($dispatched ? 'outcome_unknown' : 'claim_resolution_failed', 500);
			}
			if ($dispatched) {
				throw new JournalException('outcome_unknown');
			}
			throw $error;
		}
	}

	private function movement(File $file, FilesHistoryPlan $plan, bool $probe = true): void {
		$this->scope->assertMovement($file, $file, $probe);
		if ($plan->privateRecovery) {
			$grant = $this->scope->describeNode($file, 'history', $probe);
			if ($grant['sourceClass'] !== 'standard' || $grant['audience'] !== 'private') {
				throw new JournalException('recovery_protection_changed', 403);
			}
		}
	}

	private function file(JournalIdentity $identity, FilesHistoryPlan $plan): File {
		if ($this->users->getUser()?->getUID() !== $identity->userId) {
			throw new JournalException('permission_lost', 403);
		}
		$home = $this->root->getUserFolder($identity->userId);
		foreach ($home->getById($plan->fileId) as $file) {
			if ($file instanceof File && $file->getParent()->getId() === $plan->parentId
				&& $home->getRelativePath($file->getPath()) === $plan->target && $file->isReadable() && $file->isUpdateable()) {
				return $file;
			}
		}
		throw new JournalException('native_conflict');
	}

	private function revision(File $file): string {
		$revision = $file->getStorage()->getETag($file->getInternalPath());
		if (!is_string($revision) || $revision === '') {
			throw new JournalException('native_conflict');
		}
		return $revision;
	}

	private function readback(File $file, string $expected): string {
		$stream = $file->fopen('rb');
		if (!is_resource($stream)) {
			throw new JournalException('readback_unavailable');
		}
		try {
			$revision = $this->revision($file);
			$content = stream_get_contents($stream, FileService::MAX_WRITE_BYTES + 1);
			if (!is_string($content) || !hash_equals(hash('sha256', $expected), hash('sha256', $content))
				|| $this->revision($file) !== $revision) {
				throw new JournalException('native_conflict');
			}
			return $revision;
		} finally {
			fclose($stream);
		}
	}
}
