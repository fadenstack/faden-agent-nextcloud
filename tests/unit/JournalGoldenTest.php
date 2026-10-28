<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCA\FadenstackAgent\History\JournalCipher;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

class JournalGoldenTest extends TestCase {
	public function testRealHistoricalNativeSealsRemainReadableWithoutRewriting(): void {
		$goldens = json_decode(file_get_contents(__DIR__ . '/../fixtures/history-v1-golden.json'), true, 32, JSON_THROW_ON_ERROR);
		self::assertCount(5, $goldens['fixtures']);
		$crypto = $this->createMock(ICrypto::class);
		// Mirror the native ICrypto HMAC key separation, verified against actual native fixtures.
		$crypto->method('calculateHMAC')->willReturnCallback(static fn ($message) => hash_hmac('sha512', $message, hash('sha512', $goldens['testSecret'] . 'a'), true));
		$crypto->expects(self::never())->method('decrypt');
		$cipher = new JournalCipher($crypto);
		foreach ($goldens['fixtures'] as $fixture) {
			$row = $fixture['row'];
			$row['metadata_mac'] = base64_decode($row['metadata_mac'], true);
			$receipt = $fixture['receipt'];
			$receipt['receipt_mac'] = base64_decode($receipt['receipt_mac'], true);
			$original = $row;
			$cipher->verify([...$row, 'format' => 'v1', 'prospective_column' => 'not signed by v1']);
			$cipher->verifyReceipt([...$receipt, 'prospective_column' => 'not signed by v1']);
			self::assertSame($original, $row);
			self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $fixture['producer']['sourceSha256']);
		}
	}
}
