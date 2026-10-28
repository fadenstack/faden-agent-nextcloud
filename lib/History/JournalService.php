<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Durable write-ahead state only: never executes a native API or a stored recipe.
 * Callers must validate typed payloads, current central policy and exact approval.
 */
final class JournalService {
	private JournalPolicy $policy;
	private const BUSY = ['prepared', 'dispatching', 'undo_prepared', 'undoing', 'redo_prepared', 'redoing', 'needs_reconciliation'];
	private const DISPATCHED = ['dispatching', 'undoing', 'redoing', 'needs_reconciliation'];

	public function __construct(
		private SqlJournalStore $store,
		private JournalCipher $cipher,
		private AccessResolverRegistry $access,
		private ?ITimeFactory $clock = null,
		?JournalPolicy $policy = null,
	) {
		$this->policy = $policy ?? new JournalPolicy();
	}

	/** Persist before any native dispatch; a storage/quota failure must block dispatch. */
	public function prepare(JournalIdentity $identity, JournalResource $resource, string $operation, array $forward, array $inverse, array $preconditions, ?int $ttl = null): array {
		if (!preg_match('/^nc_[a-z0-9_]{1,96}$/D', $operation)) {
			throw new JournalException('invalid_operation', 400);
		}
		$ttl ??= $this->policy->retentionSeconds;
		if ($ttl < 1 || $ttl > $this->policy->retentionSeconds) {
			throw new JournalException('invalid_retention', 400);
		}
		$this->access->assertAccess($identity, $resource, 'prepare');
		$this->expire($identity);
		$now = $this->now();
		$ticket = bin2hex(random_bytes(32));
		$row = ['action_id' => bin2hex(random_bytes(16)), 'owner_hash' => $identity->ownerHash, 'scope_hash' => $identity->scopeHash,
			'resource_hash' => $resource->key($identity), 'resource_json' => JournalCipher::json($resource->toArray()),
			'operation' => $operation, 'state' => 'prepared', 'direction' => 'apply', 'version' => 0, 'resource_seq' => 0,
			'payload' => null, 'payload_bytes' => 0, 'ticket_hash' => hash('sha256', $ticket), 'session_hash' => $identity->sessionHash,
			'created_at' => $now, 'updated_at' => $now, 'expires_at' => $now + $ttl];
		$payload = ['schema' => 1, 'forward' => $forward, 'inverse' => $inverse, 'preconditions' => $preconditions, 'revision' => null];
		$this->setPayload($row, $payload);
		$this->store->ensureQuotaRows($identity->ownerHash, $row['resource_hash']);
		$row = $this->store->atomic(function () use ($row, $resource): array {
			$row['resource_seq'] = $this->store->reserve($row['owner_hash'], $row['resource_hash'], $row['action_id'], $row['payload_bytes'], $this->policy);
			$entries = 0;
			foreach ($this->coordinated($row['owner_hash'], $resource) as $other) {
				if (in_array($other['state'], self::BUSY, true)) {
					throw new JournalException('resource_busy_or_quota');
				}
				$entries += $other['payload'] !== null ? 1 : 0;
			}
			if ($entries >= $this->policy->maxResourceEntries) {
				throw new JournalException('resource_busy_or_quota');
			}
			$row = $this->cipher->sign($row);
			$this->store->insertEntry($row);
			$this->store->appendAttempt($this->cipher->receipt($row, 'prepared'));
			return $row;
		});
		return [...$this->summary($row), 'ticket' => $ticket];
	}

	/** Authorized metadata only; no saved payload is decrypted. */
	public function describe(JournalIdentity $identity, string $id): array {
		return $this->summary($this->authorized($identity, $id, 'list'));
	}

	/** Never return this generic snapshot directly from a browser/model endpoint. */
	public function preview(JournalIdentity $identity, string $id): array {
		$row = $this->authorized($identity, $id, 'preview');
		$this->requireSnapshot($row);
		return [...$this->summary($row), 'snapshot' => $this->cipher->decrypt($row)];
	}

	/** Metadata is filtered through current provider authority before disclosure. */
	public function list(JournalIdentity $identity, ?JournalResource $resource = null): array {
		$result = [];
		foreach ($this->store->owned($identity->ownerHash, $identity->scopeHash, $resource?->key($identity)) as $row) {
			try {
				$this->authorizeRow($identity, $row, 'list');
				$result[] = $this->summary($row);
			} catch (JournalException) {
				// No existence, title or saved bytes from an inaccessible provider/object.
			}
		}
		return $result;
	}

	public function attempts(JournalIdentity $identity, string $id): array {
		$this->authorized($identity, $id, 'list');
		$attempts = $this->store->attempts($id);
		foreach ($attempts as $attempt) {
			$this->cipher->verifyReceipt($attempt);
		}
		return array_map(static function (array $attempt): array {
			unset($attempt['receipt_mac']);
			return $attempt;
		}, $attempts);
	}

	/** Does not authorize native execution; the host must use the governed executor. */
	public function claim(JournalIdentity $identity, string $id, string $direction, int $expectedVersion): array {
		if (!in_array($direction, ['undo', 'redo'], true)) {
			throw new JournalException('invalid_direction', 400);
		}
		$row = $this->authorized($identity, $id, $direction);
		$this->requireSnapshot($row);
		if ($row['version'] !== $expectedVersion || $row['state'] !== ($direction === 'undo' ? 'applied' : 'undone')) {
			throw new JournalException('state_conflict');
		}
		$ticket = bin2hex(random_bytes(32));
		return $this->store->atomic(function () use ($identity, $row, $direction, $ticket): array {
			$this->store->lockOwner($row['owner_hash']);
			$this->store->lockResource($row);
			$this->assertOrder($row, $direction);
			$updated = $row;
			$updated['direction'] = $direction;
			$updated['state'] = $direction . '_prepared';
			$updated['ticket_hash'] = hash('sha256', $ticket);
			$updated['session_hash'] = $identity->sessionHash;
			$updated = $this->transition($row, $updated, 'claimed');
			return [...$this->summary($updated), 'ticket' => $ticket];
		});
	}

	public function markDispatched(JournalIdentity $identity, string $id, string $ticket): array {
		$row = $this->authorized($identity, $id, 'dispatch');
		$this->ticket($identity, $row, $ticket);
		$this->requireSnapshot($row);
		if (!in_array($row['state'], ['prepared', 'undo_prepared', 'redo_prepared'], true)) {
			throw new JournalException('state_conflict');
		}
		$updated = $row;
		$updated['state'] = match ($row['direction']) {
			'apply' => 'dispatching', 'undo' => 'undoing', 'redo' => 'redoing'
		};
		return $this->store->atomic(fn () => $this->summary($this->transition($row, $updated, 'dispatched')));
	}

	/** A successful HTTP status alone is never a verified readback. */
	public function finish(JournalIdentity $identity, string $id, string $ticket, string $outcome, ?VerifiedReadback $readback = null): array {
		$row = $this->owned($identity, $id);
		$this->ticket($identity, $row, $ticket);
		return $this->complete($identity, $row, $outcome, $readback);
	}

	/** Reconcile an interrupted attempt by trusted native readback, never by replay. */
	public function reconcile(JournalIdentity $identity, string $id, int $expectedVersion, string $outcome, VerifiedReadback $readback): array {
		$row = $this->owned($identity, $id);
		if ($row['version'] !== $expectedVersion || !in_array($row['state'], self::DISPATCHED, true) || $outcome === 'unknown') {
			throw new JournalException('state_conflict');
		}
		return $this->complete($identity, $row, $outcome, $readback, true);
	}

	/** Typed internal handler only: update a settled observation, never its state or ordering. */
	public function refreshRevision(JournalIdentity $identity, string $id, int $expectedVersion, string $recordedRevision, VerifiedReadback $readback): array {
		$row = $this->authorized($identity, $id, 'revalidate');
		return $this->store->atomic(function () use ($identity, $row, $expectedVersion, $recordedRevision, $readback): array {
			$this->store->lockOwner($row['owner_hash']);
			$current = $this->authorized($identity, $row['action_id'], 'revalidate');
			$this->requireSnapshot($current);
			$resource = JournalResource::fromArray(json_decode($current['resource_json'], true, 8, JSON_THROW_ON_ERROR));
			if ($current['version'] !== $expectedVersion || !in_array($current['state'], ['applied', 'undone'], true)
				|| $current['operation'] !== 'nc_update_text_file' || $resource->recovery !== JournalResource::PRIVATE_TEXT_RECOVERY) {
				throw new JournalException('state_conflict');
			}
			$payload = $this->cipher->decrypt($current);
			if (($payload['revision'] ?? null) !== $recordedRevision || hash_equals($recordedRevision, $readback->revision)) {
				throw new JournalException('plan_changed');
			}
			$this->store->lockResource($current);
			$this->assertOrder($current, $current['state'] === 'applied' ? 'undo' : 'redo');
			$updated = $current;
			$payload['revision'] = $readback->revision;
			$this->setPayload($updated, $payload);
			$this->store->resizePayload($current, $updated['payload_bytes'], $this->policy);
			$updated = $this->transition($current, $updated, 'revalidated', 'storage_revision');
			$this->store->releaseResource($updated);
			return $this->summary($updated);
		});
	}

	/** Stop before dispatch releases the claim. Stop after dispatch remains unknown. */
	public function abort(JournalIdentity $identity, string $id, string $ticket): array {
		$row = $this->owned($identity, $id);
		$this->ticket($identity, $row, $ticket);
		if (in_array($row['state'], self::DISPATCHED, true)) {
			return $this->complete($identity, $row, 'unknown', null);
		}
		if (!in_array($row['state'], ['prepared', 'undo_prepared', 'redo_prepared'], true)) {
			throw new JournalException('state_conflict');
		}
		return $this->store->atomic(function () use ($row): array {
			$this->store->lockOwner($row['owner_hash']);
			$updated = $row;
			$updated['state'] = match ($row['direction']) {
				'apply' => 'cancelled', 'undo' => 'applied', 'redo' => 'undone'
			};
			$updated['ticket_hash'] = null;
			$updated['session_hash'] = null;
			if ($row['direction'] === 'apply') {
				$this->purgePayload($row, $updated);
			}
			$updated = $this->transition($row, $updated, 'cancelled');
			$this->store->releaseResource($row);
			return $this->summary($updated);
		});
	}

	/** Privacy expiry purges bytes. It never silently retries or clears unknown effects. */
	public function expire(JournalIdentity $identity): int {
		return $this->expireRows($this->store->owned($identity->ownerHash));
	}

	/** Background maintenance deletes expired encrypted bytes without opening a native user's content. */
	public function pruneExpired(int $limit = 100): int {
		return $this->expireRows($this->store->expiryCandidates($this->now(), $this->now() - 2592000, $limit));
	}

	private function expireRows(array $rows): int {
		$count = 0;
		foreach ($rows as $row) {
			$this->cipher->verify($row);
			if ($row['payload'] !== null && $row['expires_at'] <= $this->now()) {
				$changed = $this->store->atomic(function () use ($row): bool {
					$row = $this->maintenanceRow($row);
					if ($row === null) {
						return false;
					}
					$updated = $row;
					$this->purgePayload($row, $updated);
					$updated['state'] = in_array($row['state'], self::DISPATCHED, true) ? 'needs_reconciliation' : 'expired';
					$updated['ticket_hash'] = null;
					$updated['session_hash'] = null;
					$this->transition($row, $updated, 'expired');
					if (in_array($row['state'], ['prepared', 'undo_prepared', 'redo_prepared'], true)) {
						$this->store->releaseResource($row);
					}
					return true;
				});
				$count += $changed ? 1 : 0;
			} elseif ($row['payload'] === null && $row['updated_at'] < $this->now() - 2592000
				&& in_array($row['state'], ['expired', 'failed', 'cancelled', 'redo_invalidated'], true)) {
				$this->store->atomic(function () use ($row): void {
					$current = $this->maintenanceRow($row);
					if ($current !== null) {
						$this->store->purgeTerminal($current);
					}
				});
			}
		}
		return $count;
	}

	/** Called inside atomic(): another worker may have completed this observation. */
	private function maintenanceRow(array $observed): ?array {
		$this->store->lockOwner($observed['owner_hash']);
		$current = $this->store->get($observed['action_id']);
		if ($current === null) {
			return null;
		}
		$this->cipher->verify($current);
		return $current['version'] === $observed['version'] ? $current : null;
	}

	private function complete(JournalIdentity $identity, array $row, string $outcome, ?VerifiedReadback $readback, bool $inspected = false): array {
		if (!in_array($row['state'], self::DISPATCHED, true) || !in_array($outcome, ['verified', 'unchanged', 'unknown'], true)) {
			throw new JournalException('state_conflict');
		}
		$payload = null;
		if ($outcome !== 'unknown') {
			if ($readback === null) {
				throw new JournalException('readback_required');
			}
			$this->authorizeRow($identity, $row, 'reconcile');
			$this->requireSnapshot($row);
			$payload = $this->cipher->decrypt($row);
			if ($readback->resource !== null) {
				$current = JournalResource::fromArray(json_decode($row['resource_json'], true, 8, JSON_THROW_ON_ERROR));
				if ($current->provider !== $readback->resource->provider || $current->reference !== $readback->resource->reference) {
					throw new JournalException('resource_changed');
				}
				$this->access->assertAccess($identity, $readback->resource, 'readback');
			}
		}
		return $this->store->atomic(function () use ($row, $outcome, $readback, $payload, $inspected): array {
			$this->store->lockOwner($row['owner_hash']);
			$updated = $row;
			if ($outcome === 'unknown') {
				$updated['state'] = 'needs_reconciliation';
				return $this->summary($this->transition($row, $updated, 'needs_reconciliation'));
			}
			$updated['state'] = $outcome === 'verified'
				? ($row['direction'] === 'undo' ? 'undone' : 'applied')
				: match ($row['direction']) {
					'apply' => 'failed', 'undo' => 'applied', 'redo' => 'undone'
				};
			$updated['ticket_hash'] = null;
			$updated['session_hash'] = null;
			if ($readback?->resource !== null) {
				$updated['resource_json'] = JournalCipher::json($readback->resource->toArray());
			}
			$payload['revision'] = $readback->revision;
			$this->setPayload($updated, $payload);
			$this->store->resizePayload($row, $updated['payload_bytes'], $this->policy);
			if ($updated['state'] === 'failed') {
				$this->purgePayload($updated, $updated);
			}
			$updated = $this->transition($row, $updated, $inspected ? 'reconciled' : ($outcome === 'verified' ? $updated['state'] : 'failed'), $inspected ? $outcome : '');
			if ($outcome === 'verified' && $row['direction'] === 'apply') {
				$this->invalidateRedo($row);
			}
			$this->store->releaseResource($row);
			return $this->summary($updated);
		});
	}

	private function invalidateRedo(array $new): void {
		foreach ($this->coordinated($new['owner_hash'], JournalResource::fromArray(json_decode($new['resource_json'], true, 8, JSON_THROW_ON_ERROR))) as $row) {
			if ($row['action_id'] !== $new['action_id'] && $row['state'] === 'undone') {
				$updated = $row;
				$updated['state'] = 'redo_invalidated';
				$this->purgePayload($row, $updated);
				$this->transition($row, $updated, 'redo_invalidated');
			}
		}
	}

	/** Under the owner lock: preserve the same dependency ordering for revision verification and reversal. */
	private function assertOrder(array $row, string $direction): void {
		foreach ($this->coordinated($row['owner_hash'], JournalResource::fromArray(json_decode($row['resource_json'], true, 8, JSON_THROW_ON_ERROR))) as $other) {
			if ($other['action_id'] === $row['action_id']) {
				continue;
			}
			// Generation is global for new records; legacy scopes may be incomparable.
			$later = $other['resource_seq'] > $row['resource_seq'] || $other['created_at'] > $row['created_at'];
			$earlier = $other['resource_seq'] < $row['resource_seq'] || $other['created_at'] < $row['created_at'];
			$ambiguous = $other['resource_seq'] === $row['resource_seq'] && $other['created_at'] === $row['created_at'];
			if (in_array($other['state'], self::BUSY, true)
				|| ($direction === 'undo' && ($later || $ambiguous) && $other['state'] === 'applied')
				|| ($direction === 'redo' && ($earlier || $ambiguous) && $other['state'] === 'undone')) {
				throw new JournalException('dependent_action');
			}
		}
	}

	/** Internal coordination only: no other scope's payload is decrypted or returned. */
	private function coordinated(string $owner, JournalResource $resource): array {
		$matching = [];
		foreach ($this->store->owned($owner) as $row) {
			$this->cipher->verify($row);
			$other = JournalResource::fromArray(json_decode($row['resource_json'], true, 8, JSON_THROW_ON_ERROR));
			if ($resource->conflictsWith($other)) {
				$matching[] = $row;
			}
		}
		return $matching;
	}

	private function owned(JournalIdentity $identity, string $id): array {
		if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
			throw new JournalException('action_unavailable', 404);
		}
		$row = $this->store->get($id);
		if ($row === null || !hash_equals($row['owner_hash'], $identity->ownerHash) || !hash_equals($row['scope_hash'], $identity->scopeHash)) {
			throw new JournalException('action_unavailable', 404);
		}
		$this->cipher->verify($row);
		return $row;
	}

	private function authorized(JournalIdentity $identity, string $id, string $purpose): array {
		$row = $this->owned($identity, $id);
		$this->authorizeRow($identity, $row, $purpose);
		return $row;
	}

	private function authorizeRow(JournalIdentity $identity, array $row, string $purpose): void {
		if ($row['owner_hash'] !== $identity->ownerHash || $row['scope_hash'] !== $identity->scopeHash) {
			throw new JournalException('action_unavailable', 404);
		}
		$this->cipher->verify($row);
		$this->access->assertAccess($identity, JournalResource::fromArray(json_decode($row['resource_json'], true, 8, JSON_THROW_ON_ERROR)), $purpose);
	}

	private function ticket(JournalIdentity $identity, array $row, string $ticket): void {
		if (!preg_match('/^[a-f0-9]{64}$/D', $ticket) || !is_string($row['ticket_hash']) || !is_string($row['session_hash'])
			|| !hash_equals($row['ticket_hash'], hash('sha256', $ticket)) || !hash_equals($row['session_hash'], $identity->sessionHash)) {
			throw new JournalException('claim_conflict');
		}
	}

	private function requireSnapshot(array $row): void {
		if ($row['payload'] === null || $row['expires_at'] <= $this->now()) {
			throw new JournalException('expired');
		}
	}

	private function setPayload(array &$row, array $payload): void {
		if (strlen(JournalCipher::json($payload)) > $this->policy->maxPayloadBytes) {
			throw new JournalException('snapshot_too_large', 413);
		}
		$row['payload'] = $this->cipher->encrypt($row, $payload);
		$row['payload_bytes'] = strlen($row['payload']);
	}

	private function purgePayload(array $row, array &$updated): void {
		$this->store->resizePayload($row, 0, $this->policy);
		$updated['payload'] = null;
		$updated['payload_bytes'] = 0;
	}

	private function transition(array $previous, array $row, string $outcome, string $reason = ''): array {
		$row['version'] = $previous['version'] + 1;
		$row['updated_at'] = $this->now();
		$row = $this->cipher->sign($row);
		$this->store->replaceEntry($row, $previous['version']);
		$this->store->appendAttempt($this->cipher->receipt($row, $outcome, $reason));
		return $row;
	}

	private function summary(array $row): array {
		return ['id' => $row['action_id'], 'operation' => $row['operation'], 'direction' => $row['direction'], 'resource' => json_decode($row['resource_json'], true, 8, JSON_THROW_ON_ERROR),
			'state' => $row['state'], 'version' => $row['version'], 'createdAt' => $row['created_at'], 'expiresAt' => $row['expires_at'],
			'snapshotAvailable' => $row['payload'] !== null && $row['expires_at'] > $this->now(),
			'journalEligibility' => $row['payload'] !== null && $row['expires_at'] > $this->now()
				? match ($row['state']) {
					'applied' => 'undo', 'undone' => 'redo', default => 'none'
				} : 'none'];
	}

	private function now(): int {
		return $this->clock?->getTime() ?? time();
	}
}
