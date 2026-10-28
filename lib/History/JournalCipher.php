<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCP\Security\ICrypto;

/** Instance-secret encryption and domain-separated authenticated metadata. */
final class JournalCipher {
	/** Frozen released wire contract: schema additions must never enter a v1 seal. */
	private const HEADER_V1 = ['action_id', 'owner_hash', 'scope_hash', 'resource_hash', 'resource_json',
		'operation', 'state', 'direction', 'version', 'resource_seq', 'payload', 'payload_bytes',
		'ticket_hash', 'session_hash', 'created_at', 'updated_at', 'expires_at'];
	private const RECEIPT_V1 = ['action_id', 'version', 'direction', 'outcome', 'reason', 'created_at'];
	private const INTEGERS = ['version', 'resource_seq', 'payload_bytes', 'created_at', 'updated_at', 'expires_at'];
	public function __construct(
		private ICrypto $crypto,
	) {
	}

	public function encrypt(array $row, array $payload): string {
		self::format($row);
		return $this->crypto->encrypt(self::json(['domain' => 'fadenstack-history-payload-v1',
			'binding' => $this->binding($row), 'data' => $payload]));
	}

	public function decrypt(array $row): array {
		self::format($row);
		try {
			$envelope = json_decode($this->crypto->decrypt($row['payload']), true, 32, JSON_THROW_ON_ERROR);
			if (!is_array($envelope) || ($envelope['domain'] ?? '') !== 'fadenstack-history-payload-v1'
				|| ($envelope['binding'] ?? null) !== $this->binding($row) || !is_array($envelope['data'] ?? null)) {
				throw new \UnexpectedValueException();
			}
			return $envelope['data'];
		} catch (\Throwable) {
			throw new JournalException('snapshot_unavailable', 409);
		}
	}

	public function sign(array $row): array {
		self::format($row);
		$row['metadata_mac'] = $this->seal('fadenstack-history-header-v1:', $this->fields($row, self::HEADER_V1));
		return $row;
	}

	public function verify(array $row): void {
		self::format($row);
		$mac = $row['metadata_mac'] ?? '';
		$this->verifySeal('fadenstack-history-header-v1:', $this->fields($row, self::HEADER_V1), $mac);
	}

	public function receipt(array $row, string $outcome, string $reason = ''): array {
		self::format($row);
		if (!preg_match('/^[a-z_]{1,32}$/D', $outcome) || ($reason !== '' && !preg_match('/^[a-z0-9_]{1,64}$/D', $reason))) {
			throw new JournalException('invalid_outcome', 400);
		}
		$receipt = ['action_id' => $row['action_id'], 'version' => (int)$row['version'], 'direction' => $row['direction'],
			'outcome' => $outcome, 'reason' => $reason, 'created_at' => (int)$row['updated_at']];
		$receipt['receipt_mac'] = $this->seal('fadenstack-history-receipt-v1:', $receipt);
		return $receipt;
	}

	public function verifyReceipt(array $receipt): void {
		$mac = $receipt['receipt_mac'] ?? '';
		$this->verifySeal('fadenstack-history-receipt-v1:', $this->fields($receipt, self::RECEIPT_V1), $mac);
	}

	/** Reader-first dispatch. This release neither adds a column nor writes a new format. */
	public static function format(array $row): string {
		$format = $row['format'] ?? 'v1';
		if ($format !== 'v1') {
			throw new JournalException('unsupported_journal_format');
		}
		return $format;
	}

	private function fields(array $row, array $keys): array {
		$value = [];
		foreach ($keys as $key) {
			if (!array_key_exists($key, $row)) {
				throw new JournalException('journal_integrity');
			}
			$value[$key] = in_array($key, self::INTEGERS, true) ? (int)$row[$key] : $row[$key];
		}
		return $value;
	}

	/** ICrypto returns binary bytes; text columns require a portable ASCII envelope. */
	private function seal(string $domain, array $value): string {
		return 'b64:' . base64_encode($this->crypto->calculateHMAC($domain . self::json($value)));
	}

	private function verifySeal(string $domain, array $value, mixed $mac): void {
		try {
			$encoded = self::json($value);
		} catch (\JsonException) {
			// Corrupted SQLite text can contain invalid UTF-8. Keep the same
			// unavailable contract as a bad MAC for every verification caller.
			throw new JournalException('journal_integrity');
		}
		$raw = $this->crypto->calculateHMAC($domain . $encoded);
		// Existing SQLite journals used raw binary seals. Verify those in place;
		// their immutable receipts stay untouched and every new seal is text-safe.
		if (!is_string($mac) || $mac === ''
			|| (!hash_equals('b64:' . base64_encode($raw), $mac) && !hash_equals($raw, $mac))) {
			throw new JournalException('journal_integrity');
		}
	}

	public static function json(array $value): string {
		return json_encode(self::canonical($value, 0), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private static function canonical(mixed $value, int $depth): mixed {
		if ($depth > 20 || is_object($value) || is_resource($value) || (is_float($value) && !is_finite($value))) {
			throw new JournalException('invalid_snapshot', 400);
		}
		if (is_array($value)) {
			if (!array_is_list($value)) {
				ksort($value, SORT_STRING);
			}
			foreach ($value as $key => $item) {
				$value[$key] = self::canonical($item, $depth + 1);
			}
		}
		return $value;
	}

	private function binding(array $row): array {
		return [$row['action_id'], $row['owner_hash'], $row['scope_hash'], $row['resource_hash'], $row['operation'],
			(int)$row['created_at'], (int)$row['expires_at']];
	}
}
