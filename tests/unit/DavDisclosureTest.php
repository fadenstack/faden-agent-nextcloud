<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\History\AccessResolverRegistry;
use OCA\FadenstackAgent\History\JournalCipher;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\SqlJournalStore;
use OCA\FadenstackAgent\Providers\CalendarProvider;
use OCA\FadenstackAgent\Providers\ContactsProvider;
use OCA\FadenstackAgent\Providers\NativeApiBridge;
use OCA\FadenstackAgent\Providers\ProviderAuthority;
use OCA\FadenstackAgent\Providers\ProviderPolicy;
use OCA\FadenstackAgent\Providers\TasksProvider;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use OCP\Share\IManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Avoid loading native DBAL constants for an unused OCP quote() default.
interface DavDisclosureConnection extends IDBConnection {
	public function quote($input, $type = null);
}

final class DavDisclosureTest extends TestCase {
	private function provider(string $kind): CalendarProvider|TasksProvider|ContactsProvider {
		$providers = ['version' => 1];
		foreach (['calendar', 'tasks', 'contacts'] as $provider) {
			$providers[$provider] = ['enabled' => true, 'writesEnabled' => true, 'collections' => ['work'], 'sourceClass' => 'standard'];
		}
		$settings = ['integration_enabled' => '1', 'development' => '1', 'provider_reads_enabled' => '1',
			'provider_writes_enabled' => '1', 'server_url' => 'https://cloud.test', 'agent' => 'test',
			'provider_policy' => json_encode($providers)];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default = '') => $settings[$key] ?? $default);
		$users = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$users->method('getUser')->willReturn($user);
		$session = $this->createMock(ISession::class);
		$session->expects(self::never())->method('getId');
		$clients = $this->createMock(IClientService::class);
		$clients->expects(self::never())->method('newClient');
		$configuration = new Configuration($config);
		$policy = new ProviderPolicy($config, $configuration,
			new ScopePolicy($config, $this->createMock(IRootFolder::class), $users, $this->createMock(IManager::class)), $users);
		$bridge = new NativeApiBridge($config, $clients, $this->createMock(IRequest::class), $session, $users);
		$db = $this->getMockBuilder(DavDisclosureConnection::class)->disableAutoReturnValueGeneration()->getMock();
		$db->expects(self::never())->method('getQueryBuilder');
		$journal = new JournalService(new SqlJournalStore($db),
			new JournalCipher($this->createMock(ICrypto::class)), new AccessResolverRegistry());
		$type = match ($kind) {
			'calendar' => CalendarProvider::class, 'tasks' => TasksProvider::class, 'contacts' => ContactsProvider::class
		};
		return new $type($bridge, $policy, $configuration, $session, $users, $journal,
			new ProviderAuthority($bridge, $policy));
	}

	public static function blockedWrites(): array {
		$cases = [];
		foreach (['calendar', 'tasks', 'contacts'] as $kind) {
			foreach (['create', 'patch'] as $operation) {
				$cases[$kind . '-' . $operation] = [$kind, $operation];
			}
		}
		return $cases;
	}

	#[DataProvider('blockedWrites')]
	public function testBlockedFinalClassificationIsDeniedBeforeNativeIoOrJournalPreparation(string $kind, string $operation): void {
		$provider = $this->provider($kind);
		$this->expectException(Problem::class);
		$this->expectExceptionMessage('This provider source cannot be processed under the configured policy.');
		$content = $kind === 'contacts' ? "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:private\r\nFN:Private\r\nCLASS:PRIVATE\r\nEND:VCARD\r\n"
			: "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:" . ($kind === 'tasks' ? 'VTODO' : 'VEVENT') . "\r\nUID:private\r\nCLASS:PRIVATE\r\nEND:" . ($kind === 'tasks' ? 'VTODO' : 'VEVENT') . "\r\nEND:VCALENDAR\r\n";
		$provider->propose($operation, 'work', 'private.' . ($kind === 'contacts' ? 'vcf' : 'ics'), $content, $operation === 'patch' ? '"native-revision"' : null);
	}
}
