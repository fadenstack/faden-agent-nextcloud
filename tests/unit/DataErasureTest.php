<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\SqlJournalStore;
use OCA\FadenstackAgent\Listener\UserDeletedListener;
use OCA\FadenstackAgent\Migration\EraseOnUninstall;
use OCA\FadenstackAgent\Providers\StructuredTickets;
use OCA\FadenstackAgent\Service\DataErasure;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\Lock\ILockingProvider;
use OCP\Migration\IOutput;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// As in JournalInspectorTest: OCP's unused quote() default references DBAL constants the stubs do not ship.
interface ErasureConnection extends IDBConnection {
	public function quote($input, $type = null);
}

/** The database paths are proven on Nextcloud 31 and 35 by tests/integration/data-erasure.php. */
class DataErasureTest extends TestCase {
	private function erasure(IDBConnection $db, ?IConfig $config = null): DataErasure {
		return new DataErasure($db, new SqlJournalStore($db), new StructuredTickets($db, $this->createMock(ILockingProvider::class)),
			$config ?? $this->createMock(IConfig::class));
	}

	private function connection(bool $installed, bool $inTransaction = false): IDBConnection {
		$db = $this->getMockBuilder(ErasureConnection::class)->disableAutoReturnValueGeneration()->getMock();
		$db->method('tableExists')->willReturn($installed);
		$db->method('inTransaction')->willReturn($inTransaction);
		return $db;
	}

	private function deleted(string $uid): UserDeletedEvent {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return new UserDeletedEvent($user);
	}

	public function testTheDeletedUsersRowsAreFoundByTheSameOwnerKey(): void {
		$identity = new JournalIdentity('alice', hash('sha256', 'scope'), str_repeat('s', 16));
		self::assertSame($identity->ownerHash, JournalIdentity::ownerHashOf('alice'));
		self::assertNotSame($identity->ownerHash, JournalIdentity::ownerHashOf('bob'));
	}

	public function testAFailedErasureNeverBlocksTheAccountDeletion(): void {
		// Deleting inside an open transaction: the journal refuses to nest its mutex.
		$db = $this->connection(true, true);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(self::stringContains('expires within 30 days'));
		$logger->expects(self::never())->method('info');

		(new UserDeletedListener($this->erasure($db), $logger))->handle($this->deleted('alice'));
	}

	public function testNothingToEraseBeforeTheTablesExist(): void {
		$db = $this->connection(false);
		$db->expects(self::never())->method('executeStatement');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with(self::anything(), self::callback(static fn (array $context): bool => $context['changes'] === 0));

		(new UserDeletedListener($this->erasure($db), $logger))->handle($this->deleted('alice'));
	}

	public function testTheUninstallStepAlwaysErasesTheEngineCredentials(): void {
		$db = $this->connection(false);
		$config = $this->createMock(IConfig::class);
		$config->expects(self::once())->method('deleteAppFromAllUsers')->with('fadenstack_agent');
		$config->expects(self::never())->method('deleteAppValues');
		$config->expects(self::never())->method('deleteAppValue');

		(new EraseOnUninstall($this->erasure($db, $config)))->run($this->createMock(IOutput::class));
	}
}
