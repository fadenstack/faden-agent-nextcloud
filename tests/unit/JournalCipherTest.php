<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCA\FadenstackAgent\History\JournalCipher;
use OCA\FadenstackAgent\History\JournalException;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JournalCipherTest extends TestCase {
	private function fixture(string $key = 'synthetic-instance-secret'): array {
		$mac = static fn (string $message) => hash_hmac('sha512', $message, $key, true);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback($mac);
		return [new JournalCipher($crypto), $mac, ['action_id' => str_repeat('a', 32), 'owner_hash' => str_repeat('b', 64),
			'scope_hash' => str_repeat('c', 64), 'resource_hash' => str_repeat('d', 64), 'resource_json' => '{}',
			'operation' => 'nc_update_text_file', 'state' => 'applied', 'version' => 2, 'resource_seq' => 1,
			'direction' => 'apply', 'created_at' => 40, 'updated_at' => 42, 'expires_at' => 70,
			'payload' => 'synthetic-sealed-content', 'payload_bytes' => 24, 'ticket_hash' => null, 'session_hash' => null]];
	}

	public function testNewHeaderAndReceiptSealsFitTextColumnsAndVerify(): void {
		[$cipher, , $row] = $this->fixture();
		$signed = $cipher->sign($row);
		$receipt = $cipher->receipt($row, 'applied');
		foreach ([$signed['metadata_mac'], $receipt['receipt_mac']] as $seal) {
			self::assertMatchesRegularExpression('/^b64:[A-Za-z0-9+\/]+={0,2}$/D', $seal);
			self::assertLessThanOrEqual(128, strlen($seal));
			self::assertSame(64, strlen(base64_decode(substr($seal, 4), true)));
		}
		$cipher->verify($signed);
		$cipher->verifyReceipt($receipt);
	}

	public function testExistingRawBinaryHeaderAndReceiptRemainReadableWithoutRewriting(): void {
		[$cipher, $mac, $row] = $this->fixture();
		$legacy = $row;
		$legacy['metadata_mac'] = $mac('fadenstack-history-header-v1:' . JournalCipher::json($row));
		$receipt = $cipher->receipt($row, 'applied');
		unset($receipt['receipt_mac']);
		$receipt['receipt_mac'] = $mac('fadenstack-history-receipt-v1:' . JournalCipher::json($receipt));
		$original = $receipt;
		$cipher->verify($legacy);
		$cipher->verifyReceipt($receipt);
		self::assertSame($original, $receipt, 'Verifying immutable legacy receipts never converts them in place.');
		self::assertNotSame($legacy['metadata_mac'], $cipher->sign($row)['metadata_mac']);
		self::assertSame('b64:' . base64_encode($legacy['metadata_mac']), $cipher->sign($row)['metadata_mac']);
	}

	public static function sealVariants(): array {
		return [[false], [true]];
	}

	#[DataProvider('sealVariants')]
	public function testTamperedHeaderIsRefusedForBothEncodings(bool $legacy): void {
		[$cipher, $mac, $row] = $this->fixture();
		$signed = $cipher->sign($row);
		if ($legacy) {
			$signed['metadata_mac'] = $mac('fadenstack-history-header-v1:' . JournalCipher::json($row));
		}
		$signed['version']++;
		$this->expectException(JournalException::class);
		$cipher->verify($signed);
	}

	#[DataProvider('sealVariants')]
	public function testTamperedReceiptIsRefusedForBothEncodings(bool $legacy): void {
		[$cipher, $mac, $row] = $this->fixture();
		$receipt = $cipher->receipt($row, 'applied');
		if ($legacy) {
			unset($receipt['receipt_mac']);
			$receipt['receipt_mac'] = $mac('fadenstack-history-receipt-v1:' . JournalCipher::json($receipt));
		}
		$receipt['outcome'] = 'undone';
		$this->expectException(JournalException::class);
		$cipher->verifyReceipt($receipt);
	}

	public function testChangingTheInstanceKeyDoesNotAuthenticateOldHistory(): void {
		[$cipher, , $row] = $this->fixture();
		[$other] = $this->fixture('another-synthetic-instance-secret');
		$this->expectException(JournalException::class);
		$other->verify($cipher->sign($row));
	}

	public function testMalformedUtf8HeaderUsesTheIntegrityFailureContract(): void {
		[$cipher, , $row] = $this->fixture();
		$signed = $cipher->sign($row);
		$signed['resource_json'] = "\x80";
		$this->expectException(JournalException::class);
		$cipher->verify($signed);
	}

	public function testMalformedUtf8ReceiptUsesTheIntegrityFailureContract(): void {
		[$cipher, , $row] = $this->fixture();
		$receipt = $cipher->receipt($row, 'applied');
		$receipt['reason'] = "\x80";
		$this->expectException(JournalException::class);
		$cipher->verifyReceipt($receipt);
	}

	public function testReceiptSealCannotBeReusedAsAHeaderSeal(): void {
		[$cipher, , $row] = $this->fixture();
		$row['metadata_mac'] = $cipher->receipt($row, 'applied')['receipt_mac'];
		$this->expectException(JournalException::class);
		$cipher->verify($row);
	}

	public function testExtraColumnsAndExplicitV1FormatDoNotChangeReleasedSeals(): void {
		[$cipher, , $row] = $this->fixture();
		$signed = $cipher->sign($row);
		$extended = [...$signed, 'format' => 'v1', 'future_column' => 'not part of v1'];
		$cipher->verify($extended);
		self::assertSame($signed['metadata_mac'], $cipher->sign($extended)['metadata_mac']);
		$receipt = $cipher->receipt($row, 'applied');
		$cipher->verifyReceipt([...$receipt, 'future_column' => 'ignored by released receipt contract']);
		foreach (['version', 'resource_seq', 'payload_bytes', 'created_at', 'updated_at', 'expires_at'] as $field) {
			$signed[$field] = (string)$signed[$field];
		}
		$cipher->verify($signed);
	}

	public static function headerFields(): array {
		return array_map(static fn ($key) => [$key], ['action_id', 'owner_hash', 'scope_hash', 'resource_hash', 'resource_json',
			'operation', 'state', 'direction', 'version', 'resource_seq', 'payload', 'payload_bytes',
			'ticket_hash', 'session_hash', 'created_at', 'updated_at', 'expires_at']);
	}

	#[DataProvider('headerFields')]
	public function testEveryReleasedHeaderFieldRemainsAuthenticated(string $field): void {
		[$cipher, , $row] = $this->fixture();
		$signed = $cipher->sign($row);
		$signed[$field] = is_int($row[$field]) ? $row[$field] + 1 : 'changed';
		$this->expectException(JournalException::class);
		$cipher->verify($signed);
	}

	#[DataProvider('headerFields')]
	public function testMissingReleasedHeaderFieldIsNeverTreatedAsNull(string $field): void {
		[$cipher, , $row] = $this->fixture();
		$signed = $cipher->sign($row);
		unset($signed[$field]);
		$this->expectException(JournalException::class);
		$cipher->verify($signed);
	}

	public function testUnknownFormatRefusesHeaderAndPayloadBeforeUsingCrypto(): void {
		[$cipher, , $row] = $this->fixture();
		foreach (['verify', 'decrypt', 'sign'] as $operation) {
			try {
				$cipher->$operation([...$row, 'format' => 'v2']);
				self::fail('Unknown format accepted');
			} catch (JournalException $error) {
				self::assertSame('unsupported_journal_format', $error->reason);
			}
		}
	}
}
