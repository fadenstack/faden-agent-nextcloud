<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

/** App data only. Unverifiable history is never re-signed or interpreted as a recipe. */
final class JournalRetention {
	public function __construct(
		private SqlJournalStore $store,
		private JournalCipher $cipher,
	) {
	}

	/** Independent snapshot deadlines win. Legacy stored times are a privacy heuristic only. */
	public static function due(array $row, ?array $allocation, int $now): bool {
		if ($allocation !== null && ((int)$allocation['payload_bytes'] > 0 || $row['payload'] !== null)) {
			return (int)$allocation['expires_at'] <= $now;
		}
		if ($row['payload'] !== null) {
			return (int)$row['expires_at'] <= $now || (int)$row['expires_at'] > $now + 604800;
		}
		return (int)$row['updated_at'] < $now - 2592000 || (int)$row['updated_at'] > $now + 2592000;
	}

	public function expireUnreadable(array $observed, int $now): bool {
		return $this->store->atomic(function () use ($observed, $now): bool {
			$row = $this->store->get($observed['action_id']);
			if ($row === null || $row !== $observed) {
				return false;
			}
			try {
				$this->cipher->verify($row);
				return false;
			} catch (JournalException) {
				// Classification is repeated inside the dispatch/maintenance mutex.
			}
			$allocation = $this->store->allocation($row['action_id']);
			if (!self::due($row, $allocation, $now)) {
				return false;
			}
			$claims = $this->store->claimsFor($row['action_id']);
			if ($allocation === null) {
				$this->store->deny('*', 'unattributed_history', $now);
			} elseif ($claims !== []) {
				$this->store->deny($allocation['owner_hash'], 'unreadable_claim', $now);
			}
			foreach ($claims as $claim) {
				$this->store->deny($claim['owner_hash'], 'unreadable_claim', $now);
				$this->store->retireClaim($claim);
			}
			$this->store->eraseUnreadable($row['action_id']);
			$this->repairAccounting($allocation['owner_hash'] ?? null, $now);
			return true;
		});
	}

	/** Under atomic(). A rare legacy fallback may scan globally; it never guesses attribution. */
	public function repairAccounting(?string $owner, int $now): void {
		$missing = $this->store->unallocatedPage();
		if ($missing !== []) {
			$after = null;
			do {
				$rows = $this->store->unallocatedPage($after);
				foreach ($rows as $row) {
					$after = $row['action_id'];
					try {
						$this->cipher->verify($row);
						$this->store->captureAllocation($row);
					} catch (JournalException) {
						// No ownership or byte claim is copied from failed metadata.
					}
				}
			} while (count($rows) === 25);
			if ($this->store->unallocatedPage(null, 1) !== []) {
				$this->store->deny('*', 'unattributed_history', $now);
			}
			$owner = null;
		}
		$this->store->recount($owner);
	}

	public function pruneOrphans(int $now, int $limit): int {
		$count = 0;
		foreach ($this->store->auxiliaryPage('claims', $limit) as $observed) {
			$this->store->atomic(function () use ($observed, $now, &$count): void {
				$claims = $this->store->claimsFor($observed['active_id']);
				foreach ($claims as $claim) {
					if ($claim['resource_hash'] !== $observed['resource_hash'] || $claim['owner_hash'] !== $observed['owner_hash']
						|| $this->store->get($claim['active_id']) !== null) {
						continue;
					}
					$this->store->deny($claim['owner_hash'], 'orphan_claim', $now);
					$this->store->retireClaim($claim);
					$this->repairAccounting($claim['owner_hash'], $now);
					$count++;
				}
			});
		}
		foreach ($this->store->auxiliaryPage('allocations', $limit) as $observed) {
			$this->store->atomic(function () use ($observed, $now, &$count): void {
				$allocation = $this->store->allocation($observed['action_id']);
				if ($allocation !== $observed) {
					return;
				}
				$row = $this->store->get($observed['action_id']);
				if ($row !== null) {
					if ($row['owner_hash'] !== $allocation['owner_hash'] || $row['resource_hash'] !== $allocation['resource_hash']) {
						$this->store->deny($allocation['owner_hash'], 'identity_mismatch', $now);
					}
					return;
				}
				$this->store->deny($allocation['owner_hash'], 'orphan_history', $now);
				if ($now < $allocation['expires_at'] + 2592000) {
					return;
				}
				foreach ($this->store->claimsFor($observed['action_id']) as $claim) {
					$this->store->deny($claim['owner_hash'], 'orphan_claim', $now);
					$this->store->retireClaim($claim);
				}
				$this->store->eraseOrphanAllocation($observed['action_id']);
				$this->repairAccounting($observed['owner_hash'], $now);
				$count++;
			});
		}
		return $count;
	}
}
