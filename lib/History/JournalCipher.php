<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCP\Security\ICrypto;

/** Instance-secret encryption and domain-separated authenticated metadata. */
final class JournalCipher {
	public function __construct(
		private ICrypto $crypto,
	) {
	}

	public function encrypt(array $row, array $payload): string {
		return $this->crypto->encrypt(self::json(['domain' => 'fadenstack-history-payload-v1',
			'binding' => $this->binding($row), 'data' => $payload]));
	}

	public function decrypt(array $row): array {
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
		unset($row['metadata_mac']);
		$row['metadata_mac'] = $this->crypto->calculateHMAC('fadenstack-history-header-v1:' . self::json($row));
		return $row;
	}

	public function verify(array $row): void {
		$mac = $row['metadata_mac'] ?? '';
		if (!is_string($mac) || $mac === '' || !hash_equals($this->sign($row)['metadata_mac'], $mac)) {
			throw new JournalException('journal_integrity', 409);
		}
	}

	public function receipt(array $row, string $outcome, string $reason = ''): array {
		if (!preg_match('/^[a-z_]{1,32}$/D', $outcome) || ($reason !== '' && !preg_match('/^[a-z0-9_]{1,64}$/D', $reason))) {
			throw new JournalException('invalid_outcome', 400);
		}
		$receipt = ['action_id' => $row['action_id'], 'version' => (int)$row['version'], 'direction' => $row['direction'],
			'outcome' => $outcome, 'reason' => $reason, 'created_at' => (int)$row['updated_at']];
		$receipt['receipt_mac'] = $this->crypto->calculateHMAC('fadenstack-history-receipt-v1:' . self::json($receipt));
		return $receipt;
	}

	public function verifyReceipt(array $receipt): void {
		$mac = $receipt['receipt_mac'] ?? '';
		unset($receipt['receipt_mac']);
		if (!is_string($mac) || !hash_equals($this->crypto->calculateHMAC('fadenstack-history-receipt-v1:' . self::json($receipt)), $mac)) {
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
