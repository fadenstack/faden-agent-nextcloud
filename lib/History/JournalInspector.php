<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCP\AppFramework\Utility\ITimeFactory;

/** Counts only. Never repairs, re-signs, deletes or returns a stored target or payload. */
final class JournalInspector {
	private const PAGE_SIZE = 25;
	private const STATES = ['prepared', 'dispatching', 'applied', 'undo_prepared', 'undoing', 'undone',
		'redo_prepared', 'redoing', 'needs_reconciliation', 'expired', 'failed', 'cancelled', 'redo_invalidated'];

	public function __construct(
		private SqlJournalStore $store,
		private JournalCipher $cipher,
		private ITimeFactory $clock,
	) {
	}

	/** Operator-only: check payload decryption through native ICrypto; emit aggregates only. */
	public function check(): array {
		$result = ['rows' => 0, 'formats' => ['v1' => 0, 'unsupported' => 0], 'states' => [], 'readable' => 0,
			'unavailable' => [], 'due' => ['authenticated' => 0, 'unverified' => 0]];
		$after = null;
		do {
			$rows = $this->store->page($after, self::PAGE_SIZE);
			foreach ($rows as $row) {
				$after = $row['action_id'];
				$result['rows']++;
				$state = in_array($row['state'], self::STATES, true) ? $row['state'] : 'unsupported';
				$result['states'][$state] = ($result['states'][$state] ?? 0) + 1;
				$authenticated = false;
				try {
					JournalCipher::format($row);
					$result['formats']['v1']++;
					$this->cipher->verify($row);
					$authenticated = true;
					if ($row['payload'] !== null) {
						$this->cipher->decrypt($row);
					}
					$result['readable']++;
				} catch (\Throwable $error) {
					if ($error instanceof JournalException && $error->reason === 'unsupported_journal_format') {
						$result['formats']['unsupported']++;
					}
					$reason = $error instanceof JournalException && in_array($error->reason, ['unsupported_journal_format', 'snapshot_unavailable', 'journal_integrity'], true) ? $error->reason : 'could_not_check';
					$result['unavailable'][$reason] = ($result['unavailable'][$reason] ?? 0) + 1;
				}
				$due = $authenticated ? $this->due($row) : JournalRetention::due($row, $this->store->allocation($row['action_id']), $this->clock->getTime());
				if ($due) {
					$result['due'][$authenticated ? 'authenticated' : 'unverified']++;
				}
			}
		} while (count($rows) === self::PAGE_SIZE);
		ksort($result['states']);
		ksort($result['unavailable']);
		$result['retention'] = $this->store->retentionStats();
		return $result;
	}

	/** Native-owner aggregate only: no decryption or claim about an earlier scope's provenance. */
	public function availability(JournalIdentity $identity): array {
		$result = ['unavailableCount' => 0, 'earlierScopeCount' => 0, 'earlierContentExpiresBy' => null];
		foreach ($this->store->owned($identity->ownerHash) as $row) {
			try {
				$this->cipher->verify($row);
			} catch (\Throwable) {
				$result['unavailableCount']++;
				continue;
			}
			if ($row['scope_hash'] !== $identity->scopeHash && $row['scope_hash'] !== JournalIdentity::nativeScope()
				&& $row['payload'] !== null && $row['expires_at'] > $this->clock->getTime()) {
				$result['earlierScopeCount']++;
				$result['earlierContentExpiresBy'] = max($result['earlierContentExpiresBy'] ?? 0, $row['expires_at']);
			}
		}
		return $result;
	}

	/** An observation, not authorization to delete; bad stored times remain explicitly unverified. */
	private function due(array $row): bool {
		$now = $this->clock->getTime();
		return $row['payload'] !== null
			? $row['expires_at'] <= $now || $row['expires_at'] > $now + 604800
			: $row['updated_at'] < $now - 2592000 && in_array($row['state'], ['expired', 'failed', 'cancelled', 'redo_invalidated'], true);
	}
}
