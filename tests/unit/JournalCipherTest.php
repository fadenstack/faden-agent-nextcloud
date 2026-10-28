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
		return [new JournalCipher($crypto), $mac, ['action_id' => str_repeat('a', 32), 'version' => 2,
			'direction' => 'apply', 'updated_at' => 42, 'payload' => 'synthetic-sealed-content']];
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

	public function testReceiptSealCannotBeReusedAsAHeaderSeal(): void {
		[$cipher, , $row] = $this->fixture();
		$row['metadata_mac'] = $cipher->receipt($row, 'applied')['receipt_mac'];
		$this->expectException(JournalException::class);
		$cipher->verify($row);
	}
}
