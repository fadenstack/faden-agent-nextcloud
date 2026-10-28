<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCA\FadenstackAgent\History\JournalCipher;
use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalInspector;
use OCA\FadenstackAgent\History\SqlJournalStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

// OCP's unused quote() default references native DBAL constants. Override only
// that default so this read-only unit double needs no runtime DBAL dependency.
interface InspectorConnection extends IDBConnection {
	public function quote($input, $type = null);
}

class JournalInspectorTest extends TestCase {
	private function crypto(): ICrypto {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static fn ($text) => hash_hmac('sha512', $text, 'synthetic-key', true));
		$crypto->method('encrypt')->willReturnArgument(0);
		$crypto->method('decrypt')->willReturnArgument(0);
		return $crypto;
	}

	private function row(JournalCipher $cipher, int $number, string $scope, int $expiry = 900): array {
		$row = ['action_id' => str_pad((string)$number, 32, '0', STR_PAD_LEFT), 'owner_hash' => str_repeat('a', 64),
			'scope_hash' => $scope, 'resource_hash' => str_repeat('b', 64), 'resource_json' => '{"privateTarget":"not for output"}',
			'operation' => 'nc_update_text_file', 'state' => 'applied', 'direction' => 'apply', 'version' => 2,
			'resource_seq' => $number, 'created_at' => 800, 'updated_at' => 850, 'expires_at' => $expiry,
			'payload' => null, 'payload_bytes' => 0, 'ticket_hash' => null, 'session_hash' => null];
		$row['payload'] = $cipher->encrypt($row, ['privateContent' => 'not for output']);
		$row['payload_bytes'] = strlen($row['payload']);
		return $cipher->sign($row);
	}

	private function inspector(array $rows, ICrypto $crypto): JournalInspector {
		$db = $this->getMockBuilder(InspectorConnection::class)->disableAutoReturnValueGeneration()->getMock();
		$db->expects(self::never())->method('executeStatement');
		$db->expects(self::never())->method('beginTransaction');
		$db->method('executeQuery')->willReturnCallback(function ($sql, $args) use ($rows): IResult {
			if (str_contains($sql, 'action_id >')) {
				preg_match('/LIMIT ([0-9]+)$/D', $sql, $limit);
				$data = array_slice(array_values(array_filter($rows, static fn ($row) => $row['action_id'] > $args[0])), 0, (int)$limit[1]);
			} else {
				$data = array_values(array_filter($rows, static fn ($row) => $row['owner_hash'] === $args[0]));
			}
			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->willReturn($data);
			$result->method('closeCursor')->willReturn(true);
			return $result;
		});
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn(1000);
		return new JournalInspector(new SqlJournalStore($db), new JournalCipher($crypto), $clock);
	}

	public function testCheckReachesRowsBeyondBadPageBoundariesWithoutWritingOrDisclosingContent(): void {
		$crypto = $this->crypto();
		$cipher = new JournalCipher($crypto);
		$rows = [];
		for ($i = 1; $i <= 52; $i++) {
			$rows[] = $this->row($cipher, $i, str_repeat('c', 64));
		}
		$rows[0]['metadata_mac'] = 'damaged';
		$rows[25]['payload'] = 'not a readable envelope';
		$rows[25] = $cipher->sign($rows[25]);
		$rows[50]['format'] = 'v2';
		$original = $rows;
		$result = $this->inspector($rows, $crypto)->check();
		self::assertSame(52, $result['rows']);
		self::assertSame(49, $result['readable']);
		self::assertSame(['v1' => 51, 'unsupported' => 1], $result['formats']);
		self::assertSame(['journal_integrity' => 1, 'snapshot_unavailable' => 1, 'unsupported_journal_format' => 1], $result['unavailable']);
		self::assertSame(['authenticated' => 50, 'unverified' => 2], $result['due']);
		self::assertSame($original, $rows);
		$text = json_encode($result, JSON_THROW_ON_ERROR);
		self::assertStringNotContainsString('privateTarget', $text);
		self::assertStringNotContainsString('privateContent', $text);
		self::assertStringNotContainsString($rows[0]['action_id'], $text);
	}

	public function testNativeAvailabilityNeverDecryptsOrIdentifiesAnotherScope(): void {
		$crypto = $this->crypto();
		$cipher = new JournalCipher($crypto);
		$identity = new JournalIdentity('alice', str_repeat('c', 64), 'native-test-session');
		$rows = [];
		foreach ([$identity->scopeHash, str_repeat('e', 64), JournalIdentity::nativeScope(), str_repeat('f', 64)] as $i => $scope) {
			$row = $this->row($cipher, $i + 1, $scope, 1300);
			$row['owner_hash'] = $identity->ownerHash;
			$rows[] = $cipher->sign($row);
		}
		$rows[3]['metadata_mac'] = 'damaged';
		$crypto->expects(self::never())->method('decrypt');
		self::assertSame(['unavailableCount' => 1, 'earlierScopeCount' => 1, 'earlierContentExpiresBy' => 1300], $this->inspector($rows, $crypto)->availability($identity));
	}
}
