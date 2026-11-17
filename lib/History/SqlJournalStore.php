<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCP\IDBConnection;

/**
 * Portable public IDBConnection operations; all values are bound parameters.
 * Own quota rows serialize reservation across tabs without locking native data.
 */
final class SqlJournalStore {
	private const ENTRY_COLUMNS = ['action_id', 'owner_hash', 'scope_hash', 'resource_hash', 'resource_json',
		'operation', 'state', 'direction', 'version', 'resource_seq', 'payload', 'payload_bytes',
		'ticket_hash', 'session_hash', 'created_at', 'updated_at', 'expires_at', 'metadata_mac'];
	private const ATTEMPT_COLUMNS = ['action_id', 'version', 'direction', 'outcome', 'reason', 'created_at', 'receipt_mac'];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function atomic(callable $work): mixed {
		if ($this->db->inTransaction()) {
			throw new JournalException('nested_transaction', 503);
		}
		// Nextcloud's fresh-install fast path can create migration schemas without
		// postSchemaChange seed data. Duplicate recovery stays outside a PG tx.
		$this->ensureSafetyControl();
		$this->db->beginTransaction();
		try {
			// A short app-metadata mutex also orders a future global denial against
			// dispatch. Native I/O is never performed inside this transaction.
			if ($this->db->executeStatement('UPDATE *PREFIX*fdn_jr_control SET generation = CASE WHEN generation = 2147483647 THEN 0 ELSE generation + 1 END WHERE control_name = ?', ['safety']) !== 1) {
				throw new JournalException('journal_owner_unavailable', 503);
			}
			$result = $work();
			$this->db->commit();
			return $result;
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
	}

	private function ensureSafetyControl(): void {
		if ($this->one('SELECT control_name FROM *PREFIX*fdn_jr_control WHERE control_name = ?', ['safety']) !== null) {
			return;
		}
		try {
			$this->insert('fdn_jr_control', ['control_name' => 'safety', 'cursor_id' => '', 'generation' => 0], ['control_name', 'cursor_id', 'generation']);
		} catch (\Throwable $error) {
			if ($this->one('SELECT control_name FROM *PREFIX*fdn_jr_control WHERE control_name = ?', ['safety']) === null) {
				throw $error;
			}
		}
	}

	public function ensureQuotaRows(string $owner, string $resource): void {
		// Deliberately outside a transaction: a duplicate insert must not leave
		// PostgreSQL's current transaction aborted before the reservation starts.
		if ($this->db->inTransaction()) {
			throw new JournalException('nested_transaction', 503);
		}
		foreach ([['fdn_jr_owners', 'owner_hash', $owner, ['owner_hash' => $owner, 'used_bytes' => 0, 'entries' => 0]],
			['fdn_jr_resources', 'resource_hash', $resource, ['resource_hash' => $resource, 'owner_hash' => $owner,
				'active_id' => null, 'resource_seq' => 0, 'entries' => 0]]] as [$table, $key, $value, $row]) {
			if ($this->one('SELECT ' . $key . ' FROM *PREFIX*' . $table . ' WHERE ' . $key . ' = ?', [$value]) !== null) {
				continue;
			}
			try {
				$this->insert($table, $row, array_keys($row));
			} catch (\Throwable $error) {
				if ($this->one('SELECT ' . $key . ' FROM *PREFIX*' . $table . ' WHERE ' . $key . ' = ?', [$value]) === null) {
					throw $error;
				}
			}
		}
	}

	/** Call in atomic(). Returns the next native-owner generation across private scopes. */
	public function reserve(string $owner, string $resource, string $id, int $bytes, JournalPolicy $policy): int {
		if ($bytes > $policy->maxUserBytes || $this->db->executeStatement(
			'UPDATE *PREFIX*fdn_jr_owners SET used_bytes = used_bytes + ?, entries = entries + 1, action_seq = action_seq + 1 WHERE owner_hash = ? AND used_bytes <= ? AND entries < ? AND action_seq < 2147483647',
			[$bytes, $owner, $policy->maxUserBytes - $bytes, $policy->maxUserEntries]) !== 1) {
			throw new JournalException('user_quota', 507);
		}
		$sequence = (int)$this->one('SELECT action_seq FROM *PREFIX*fdn_jr_owners WHERE owner_hash = ?', [$owner])['action_seq'];
		if ($this->db->executeStatement('UPDATE *PREFIX*fdn_jr_resources SET active_id = ?, resource_seq = ?, entries = entries + 1 WHERE resource_hash = ? AND owner_hash = ? AND active_id IS NULL AND entries < ?',
			[$id, $sequence, $resource, $owner, $policy->maxResourceEntries]) !== 1) {
			throw new JournalException('resource_busy_or_quota');
		}
		return $sequence;
	}

	/** A real value change takes the owner lock without relying on no-op row-count semantics. */
	public function lockOwner(string $owner): void {
		if ($this->db->executeStatement('UPDATE *PREFIX*fdn_jr_owners SET action_seq = action_seq + 1 WHERE owner_hash = ? AND action_seq < 2147483647', [$owner]) !== 1) {
			throw new JournalException('journal_owner_unavailable', 503);
		}
	}

	public function lockResource(array $row): void {
		if ($this->db->executeStatement('UPDATE *PREFIX*fdn_jr_resources SET active_id = ? WHERE resource_hash = ? AND owner_hash = ? AND active_id IS NULL',
			[$row['action_id'], $row['resource_hash'], $row['owner_hash']]) !== 1) {
			throw new JournalException('resource_busy');
		}
	}

	public function releaseResource(array $row): void {
		if ($this->db->executeStatement('UPDATE *PREFIX*fdn_jr_resources SET active_id = NULL WHERE resource_hash = ? AND active_id = ?',
			[$row['resource_hash'], $row['action_id']]) !== 1) {
			throw new JournalException('claim_conflict');
		}
	}

	public function get(string $id): ?array {
		return $this->one('SELECT * FROM *PREFIX*fdn_journal WHERE action_id = ?', [$id]);
	}

	/** Denial only; these operational records never authorize native content access. */
	public function blocked(string $owner): bool {
		return $this->one('SELECT owner_hash FROM *PREFIX*fdn_jr_barriers WHERE owner_hash = ? OR owner_hash = ?', [$owner, '*']) !== null;
	}

	public function activeClaims(string $owner): array {
		return $this->all('SELECT resource_hash, owner_hash, active_id FROM *PREFIX*fdn_jr_resources WHERE owner_hash = ? AND active_id IS NOT NULL', [$owner]);
	}

	public function allocation(string $id): ?array {
		return $this->one('SELECT * FROM *PREFIX*fdn_jr_alloc WHERE action_id = ?', [$id]);
	}

	/** Call only after header verification, or from an authenticated producer. */
	public function captureAllocation(array $row): void {
		if ($this->allocation($row['action_id']) === null) {
			$this->insert('fdn_jr_alloc', $row, ['action_id', 'owner_hash', 'resource_hash', 'created_at', 'expires_at', 'payload_bytes']);
		}
	}

	/** Under the metadata mutex: denial is monotonic and contains no target or ticket. */
	public function deny(string $owner, string $reason, int $now): void {
		if ($this->one('SELECT owner_hash FROM *PREFIX*fdn_jr_barriers WHERE owner_hash = ?', [$owner]) === null) {
			$this->insert('fdn_jr_barriers', ['owner_hash' => $owner, 'reason' => $reason, 'created_at' => $now], ['owner_hash', 'reason', 'created_at']);
		}
	}

	public function claimsFor(string $id): array {
		return $this->all('SELECT resource_hash, owner_hash, active_id FROM *PREFIX*fdn_jr_resources WHERE active_id = ?', [$id]);
	}

	public function retireClaim(array $claim): void {
		$this->db->executeStatement('UPDATE *PREFIX*fdn_jr_resources SET active_id = NULL WHERE resource_hash = ? AND owner_hash = ? AND active_id = ?',
			[$claim['resource_hash'], $claim['owner_hash'], $claim['active_id']]);
	}

	/** Caller reloads/classifies under atomic(); no failed header field selects a quota owner. */
	public function eraseUnreadable(string $id): void {
		$this->db->executeStatement('DELETE FROM *PREFIX*fdn_journal WHERE action_id = ?', [$id]);
		$this->db->executeStatement('DELETE FROM *PREFIX*fdn_jr_attempts WHERE action_id = ?', [$id]);
		$this->db->executeStatement('DELETE FROM *PREFIX*fdn_jr_alloc WHERE action_id = ?', [$id]);
	}

	/** Expired accounting facts with no journal do not retain content or authority. */
	public function eraseOrphanAllocation(string $id): void {
		$this->db->executeStatement('DELETE FROM *PREFIX*fdn_jr_attempts WHERE action_id = ?', [$id]);
		$this->db->executeStatement('DELETE FROM *PREFIX*fdn_jr_alloc WHERE action_id = ?', [$id]);
	}

	/**
	 * Call in atomic(). Erases every action the owner wrote or was charged for, including one whose header moved,
	 * with its snapshots, receipts, claims, quota and pauses. Bounded by the per-user entry quota.
	 */
	public function eraseOwner(string $owner): int {
		$ids = array_column($this->all('SELECT action_id FROM *PREFIX*fdn_journal WHERE owner_hash = ?'
			. ' UNION SELECT action_id FROM *PREFIX*fdn_jr_alloc WHERE owner_hash = ?', [$owner, $owner]), 'action_id');
		foreach (array_chunk($ids, 100) as $chunk) {
			$in = implode(', ', array_fill(0, count($chunk), '?'));
			$this->db->executeStatement('UPDATE *PREFIX*fdn_jr_resources SET active_id = NULL WHERE active_id IN (' . $in . ')', $chunk);
			foreach (['fdn_jr_attempts', 'fdn_journal', 'fdn_jr_alloc'] as $table) {
				$this->db->executeStatement('DELETE FROM *PREFIX*' . $table . ' WHERE action_id IN (' . $in . ')', $chunk);
			}
		}
		foreach (['fdn_jr_resources', 'fdn_jr_owners', 'fdn_jr_barriers'] as $table) {
			$this->db->executeStatement('DELETE FROM *PREFIX*' . $table . ' WHERE owner_hash = ?', [$owner]);
		}
		return count($ids);
	}

	/** Call in atomic(). Erases the whole journal and restarts the maintenance cursors; the mutex row stays. */
	public function eraseAll(): void {
		foreach (['fdn_jr_attempts', 'fdn_journal', 'fdn_jr_alloc', 'fdn_jr_resources', 'fdn_jr_owners', 'fdn_jr_barriers'] as $table) {
			$this->db->executeStatement('DELETE FROM *PREFIX*' . $table);
		}
		$this->db->executeStatement('UPDATE *PREFIX*fdn_jr_control SET cursor_id = ?, generation = 0 WHERE control_name <> ?', ['', 'safety']);
	}

	public function unallocatedPage(?string $after = null, int $limit = 25): array {
		$limit = max(1, min(1000, $limit));
		return $this->all('SELECT j.* FROM *PREFIX*fdn_journal j LEFT JOIN *PREFIX*fdn_jr_alloc a ON a.action_id = j.action_id WHERE a.action_id IS NULL'
			. ($after === null ? '' : ' AND j.action_id > ?') . ' ORDER BY j.action_id ASC LIMIT ' . $limit, $after === null ? [] : [$after]);
	}

	/** Actual byte length, including corrupt multibyte text; never the failed declared charge. */
	private function payloadLength(string $column): string {
		return match ($this->db->getDatabaseProvider()) {
			IDBConnection::PLATFORM_SQLITE => 'LENGTH(CAST(' . $column . ' AS BLOB))',
			IDBConnection::PLATFORM_POSTGRES, IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_MARIADB => 'OCTET_LENGTH(' . $column . ')',
			default => throw new JournalException('database_unavailable', 503),
		};
	}

	/** Known attribution only. The unattributed pool is calculated, never assigned to a guessed user. */
	public function recount(?string $owner = null): void {
		$bytes = $this->payloadLength('j.payload');
		$owners = ' FROM *PREFIX*fdn_jr_alloc a JOIN *PREFIX*fdn_journal j ON j.action_id = a.action_id WHERE a.owner_hash = *PREFIX*fdn_jr_owners.owner_hash';
		$this->db->executeStatement('UPDATE *PREFIX*fdn_jr_owners SET used_bytes = (SELECT COALESCE(SUM(' . $bytes . '), 0)' . $owners . '), entries = (SELECT COUNT(*)' . $owners . ')'
			. ($owner === null ? '' : ' WHERE owner_hash = ?'), $owner === null ? [] : [$owner]);
		$this->db->executeStatement('UPDATE *PREFIX*fdn_jr_resources SET entries = (SELECT COUNT(*) FROM *PREFIX*fdn_jr_alloc a JOIN *PREFIX*fdn_journal j ON j.action_id = a.action_id WHERE a.resource_hash = *PREFIX*fdn_jr_resources.resource_hash AND j.payload IS NOT NULL)'
			. ($owner === null ? '' : ' WHERE owner_hash = ?'), $owner === null ? [] : [$owner]);
	}

	/** Read-only aggregate; no owner, action or target identifiers leave this boundary. */
	public function retentionStats(): array {
		$barriers = $this->one('SELECT COUNT(*) AS owners FROM *PREFIX*fdn_jr_barriers WHERE owner_hash <> ?', ['*']);
		$global = $this->one('SELECT COUNT(*) AS total FROM *PREFIX*fdn_jr_barriers WHERE owner_hash = ?', ['*']);
		$pool = $this->one('SELECT COUNT(*) AS entries, COALESCE(SUM(' . $this->payloadLength('j.payload') . '), 0) AS used_bytes FROM *PREFIX*fdn_journal j LEFT JOIN *PREFIX*fdn_jr_alloc a ON a.action_id = j.action_id WHERE a.action_id IS NULL', []);
		return ['ownerPauses' => (int)$barriers['owners'], 'globalPauses' => (int)$global['total'],
			'unattributedEntries' => (int)$pool['entries'], 'unattributedBytes' => (int)$pool['used_bytes']];
	}

	/** Reserve a scan window before processing it; failures cannot pin the cursor. */
	public function maintenancePage(int $limit): array {
		return $this->atomic(function () use ($limit): array {
			$limit = max(1, min(1000, $limit));
			$control = $this->one('SELECT cursor_id, generation FROM *PREFIX*fdn_jr_control WHERE control_name = ?', ['retention']);
			if ($control === null) {
				$this->insert('fdn_jr_control', ['control_name' => 'retention', 'cursor_id' => '', 'generation' => 0], ['control_name', 'cursor_id', 'generation']);
				$control = ['cursor_id' => '', 'generation' => 0];
			}
			$rows = $this->page((int)$control['generation'] === 0 ? null : $control['cursor_id'], $limit);
			$after = count($rows) < $limit ? '' : $rows[array_key_last($rows)]['action_id'];
			$this->db->executeStatement('UPDATE *PREFIX*fdn_jr_control SET cursor_id = ?, generation = ? WHERE control_name = ?', [$after, count($rows) < $limit ? 0 : 1, 'retention']);
			return $rows;
		});
	}

	/** Separate persisted windows also reach claims/allocations whose journal row vanished. */
	public function auxiliaryPage(string $kind, int $limit): array {
		return $this->atomic(function () use ($kind, $limit): array {
			[$table, $key, $where] = match ($kind) {
				'claims' => ['fdn_jr_resources', 'resource_hash', 'active_id IS NOT NULL AND '],
				'allocations' => ['fdn_jr_alloc', 'action_id', ''],
				default => throw new JournalException('invalid_maintenance', 400),
			};
			$limit = max(1, min(1000, $limit));
			$control = $this->one('SELECT cursor_id FROM *PREFIX*fdn_jr_control WHERE control_name = ?', [$kind]);
			if ($control === null) {
				$this->insert('fdn_jr_control', ['control_name' => $kind, 'cursor_id' => '', 'generation' => 0], ['control_name', 'cursor_id', 'generation']);
			}
			$rows = $this->all('SELECT * FROM *PREFIX*' . $table . ' WHERE ' . $where . $key . ' > ? ORDER BY ' . $key . ' ASC LIMIT ' . $limit, [$control['cursor_id'] ?? '']);
			$after = count($rows) < $limit ? '' : $rows[array_key_last($rows)][$key];
			$this->db->executeStatement('UPDATE *PREFIX*fdn_jr_control SET cursor_id = ? WHERE control_name = ?', [$after, $kind]);
			return $rows;
		});
	}

	/** Read-only keyset scan; unhealthy rows cannot starve later diagnostic pages. */
	public function page(?string $after = null, int $limit = 100): array {
		$limit = max(1, min(1000, $limit));
		return $this->all('SELECT * FROM *PREFIX*fdn_journal' . ($after === null ? '' : ' WHERE action_id > ?') . ' ORDER BY action_id ASC LIMIT ' . $limit, $after === null ? [] : [$after]);
	}

	/** Bounded by the per-user metadata quota. */
	public function owned(string $owner, ?string $scope = null, ?string $resource = null): array {
		$sql = 'SELECT * FROM *PREFIX*fdn_journal WHERE owner_hash = ?';
		$args = [$owner];
		foreach (['scope_hash' => $scope, 'resource_hash' => $resource] as $column => $value) {
			if ($value !== null) {
				$sql .= ' AND ' . $column . ' = ?';
				$args[] = $value;
			}
		}
		return $this->all($sql . ' ORDER BY resource_seq DESC, created_at DESC, action_id DESC', $args);
	}

	/** Denial/ordering lookup includes original allocation ownership if a raw header moves. */
	public function ownerSafetyRows(string $owner): array {
		return $this->all('SELECT j.* FROM *PREFIX*fdn_journal j JOIN (SELECT action_id FROM *PREFIX*fdn_journal WHERE owner_hash = ? UNION SELECT action_id FROM *PREFIX*fdn_jr_alloc WHERE owner_hash = ?) owned_ids ON owned_ids.action_id = j.action_id', [$owner, $owner]);
	}

	public function hasOrphanAllocation(string $owner): bool {
		return $this->one('SELECT a.action_id FROM *PREFIX*fdn_jr_alloc a LEFT JOIN *PREFIX*fdn_journal j ON j.action_id = a.action_id WHERE a.owner_hash = ? AND j.action_id IS NULL LIMIT 1', [$owner]) !== null;
	}

	public function insertEntry(array $row): void {
		$this->insert('fdn_journal', $row, self::ENTRY_COLUMNS);
		$this->insert('fdn_jr_alloc', $row, ['action_id', 'owner_hash', 'resource_hash', 'created_at', 'expires_at', 'payload_bytes']);
	}

	/** Content-free maintenance query; bounded independently of inactive users signing in. */
	public function expiryCandidates(int $now, int $terminalBefore, int $limit): array {
		$limit = max(1, min(1000, $limit));
		return $this->all('SELECT * FROM *PREFIX*fdn_journal WHERE (payload IS NOT NULL AND expires_at <= ?)'
			. " OR (payload IS NULL AND updated_at < ? AND state IN ('expired', 'failed', 'cancelled', 'redo_invalidated'))"
			. ' ORDER BY expires_at ASC, action_id ASC LIMIT ' . $limit, [$now, $terminalBefore]);
	}

	public function replaceEntry(array $row, int $expectedVersion): void {
		$columns = array_values(array_filter(self::ENTRY_COLUMNS, static fn ($key) => $key !== 'action_id'));
		$sql = 'UPDATE *PREFIX*fdn_journal SET ' . implode(', ', array_map(static fn ($key) => $key . ' = ?', $columns)) . ' WHERE action_id = ? AND version = ?';
		$args = array_map(static fn ($key) => $row[$key], $columns);
		$args[] = $row['action_id'];
		$args[] = $expectedVersion;
		if ($this->db->executeStatement($sql, $args) !== 1) {
			throw new JournalException('state_conflict');
		}
		// A verified legacy row may have been unavailable during migration. Its
		// first authenticated transition can establish accounting without resealing it.
		if ($this->allocation($row['action_id']) === null) {
			$this->insert('fdn_jr_alloc', $row, ['action_id', 'owner_hash', 'resource_hash', 'created_at', 'expires_at', 'payload_bytes']);
		} else {
			$this->db->executeStatement('UPDATE *PREFIX*fdn_jr_alloc SET payload_bytes = ? WHERE action_id = ?', [$row['payload_bytes'], $row['action_id']]);
		}
	}

	public function appendAttempt(array $receipt): void {
		$this->insert('fdn_jr_attempts', $receipt, self::ATTEMPT_COLUMNS);
	}

	public function attempts(string $id): array {
		return $this->all('SELECT * FROM *PREFIX*fdn_jr_attempts WHERE action_id = ? ORDER BY version ASC', [$id]);
	}

	public function resizePayload(array $row, int $newBytes, JournalPolicy $policy): void {
		$delta = $newBytes - (int)$row['payload_bytes'];
		$arguments = [$delta, $row['owner_hash'], max(0, -$delta)];
		if ($delta > 0) {
			$arguments[] = $policy->maxUserBytes - $delta;
		}
		if ($delta !== 0 && $this->db->executeStatement('UPDATE *PREFIX*fdn_jr_owners SET used_bytes = used_bytes + ? WHERE owner_hash = ? AND used_bytes >= ?' . ($delta > 0 ? ' AND used_bytes <= ?' : ''), $arguments) !== 1) {
			throw new JournalException('user_quota', 507);
		}
		if ($newBytes === 0 && (int)$row['payload_bytes'] > 0) {
			$this->db->executeStatement('UPDATE *PREFIX*fdn_jr_resources SET entries = entries - 1 WHERE resource_hash = ? AND entries > 0', [$row['resource_hash']]);
		}
	}

	/** Terminal expired metadata and its receipt history have a bounded retention. */
	public function purgeTerminal(array $row): void {
		if ($row['payload'] !== null || !in_array($row['state'], ['expired', 'failed', 'cancelled', 'redo_invalidated'], true)) {
			throw new JournalException('not_purgeable');
		}
		if ($this->db->executeStatement('DELETE FROM *PREFIX*fdn_journal WHERE action_id = ? AND version = ?', [$row['action_id'], $row['version']]) !== 1) {
			throw new JournalException('state_conflict');
		}
		$this->db->executeStatement('DELETE FROM *PREFIX*fdn_jr_attempts WHERE action_id = ?', [$row['action_id']]);
		$this->db->executeStatement('DELETE FROM *PREFIX*fdn_jr_alloc WHERE action_id = ?', [$row['action_id']]);
		$this->db->executeStatement('UPDATE *PREFIX*fdn_jr_owners SET entries = entries - 1 WHERE owner_hash = ? AND entries > 0', [$row['owner_hash']]);
	}

	private function insert(string $table, array $row, array $columns): void {
		$sql = 'INSERT INTO *PREFIX*' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
		$this->db->executeStatement($sql, array_map(static fn ($key) => $row[$key], $columns));
	}

	private function one(string $sql, array $args): ?array {
		$result = $this->db->executeQuery($sql, $args);
		try {
			$row = $result->fetch();
			return $row === false ? null : $this->normalize($row);
		} finally {
			$result->closeCursor();
		}
	}

	private function all(string $sql, array $args): array {
		$result = $this->db->executeQuery($sql, $args);
		try {
			return array_map($this->normalize(...), $result->fetchAll());
		} finally {
			$result->closeCursor();
		}
	}

	private function normalize(array $row): array {
		foreach (['version', 'resource_seq', 'action_seq', 'payload_bytes', 'created_at', 'updated_at', 'expires_at', 'used_bytes', 'entries'] as $column) {
			if (isset($row[$column])) {
				$row[$column] = (int)$row[$column];
			}
		}
		return $row;
	}
}
