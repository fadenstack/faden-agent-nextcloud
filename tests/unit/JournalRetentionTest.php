<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCA\FadenstackAgent\History\JournalRetention;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JournalRetentionTest extends TestCase {
	public static function deadlines(): array {
		return [
			'unexpired independent deadline survives forged past time' => ['ciphertext', 1, 1, ['payload_bytes' => 20, 'expires_at' => 1100], false],
			'expired independent deadline beats forged future time' => ['ciphertext', 99999999, 99999999, ['payload_bytes' => 20, 'expires_at' => 900], true],
			'unexpired independent snapshot survives forged null payload' => [null, 1, -9999999, ['payload_bytes' => 20, 'expires_at' => 1100], false],
			'legacy overdue snapshot' => ['ciphertext', 900, 900, null, true],
			'legacy deadline outside allowed future window' => ['ciphertext', 605801, 900, null, true],
			'legacy unexpired snapshot' => ['ciphertext', 1100, 900, null, false],
			'unreadable old metadata ignores unauthenticated state' => [null, 1100, -2591001, ['payload_bytes' => 0, 'expires_at' => 1100], true],
			'unreadable recent metadata is preserved' => [null, 1100, 900, null, false],
			'impossible future metadata date cannot pin retention' => [null, 1100, 2593001, null, true],
		];
	}

	#[DataProvider('deadlines')]
	public function testIndependentOrExplicitlyHeuristicDeadline(?string $payload, int $expires, int $updated, ?array $allocation, bool $due): void {
		$row = ['payload' => $payload, 'expires_at' => $expires, 'updated_at' => $updated, 'state' => 'not trusted'];
		self::assertSame($due, JournalRetention::due($row, $allocation, 1000));
	}
}
