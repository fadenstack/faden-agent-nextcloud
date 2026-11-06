<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\CommunicationAuthority;
use OCA\FadenstackAgent\Providers\NativeApiBridge;
use OCA\FadenstackAgent\Providers\ProviderPolicy;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager;
use PHPUnit\Framework\TestCase;

final class CommunicationAuthorityTest extends TestCase {
	private function fixture(array $responses, bool $enabled = true, string $version = '5.13.1', string $provider = 'mail', array $override = []): array {
		if (isset($responses['/apps/mail/api/accounts']) && is_array($responses['/apps/mail/api/accounts'])) {
			$responses['/apps/mail/api/accounts/1'] = $responses['/apps/mail/api/accounts'][0] ?? ['status' => 'fail', 'data' => []];
		}
		if (isset($responses['/apps/mail/api/accounts']) && is_string($responses['/apps/mail/api/accounts'])) {
			$responses['/apps/mail/api/accounts/1'] = $responses['/apps/mail/api/accounts'];
		}
		$section = ['enabled' => true, 'writesEnabled' => true, 'collections' => [$provider === 'mail' ? '1' : 'abcd1234'], 'sourceClass' => 'standard'];
		$settings = [...['integration_enabled' => '1', 'development' => '1', 'provider_reads_enabled' => '1', 'provider_writes_enabled' => '1', 'server_url' => 'https://cloud.test', 'agent' => 'test',
			'provider_policy' => json_encode(['version' => 1, $provider => $section])], ...$override];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default = '') => $settings[$key] ?? $default);
		$config->method('getSystemValueString')->willReturn('https://cloud.test');
		$users = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$users->method('getUser')->willReturn($user);
		$session = $this->createMock(ISession::class);
		$session->method('getId')->willReturn('native-session');
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn ($name) => $name === 'Cookie' ? 'session=synthetic' : 'csrf');
		$clients = $this->createMock(IClientService::class);
		$client = $this->createMock(IClient::class);
		$clients->method('newClient')->willReturn($client);
		$client->method('request')->willReturnCallback(function ($method, $url) use ($responses): IResponse {
			$path = substr($url, strlen('https://cloud.test'));
			self::assertArrayHasKey($path, $responses, 'No unexpected native read or write is permitted.');
			$response = $this->createMock(IResponse::class);
			$value = $responses[$path];
			if (is_callable($value)) {
				$value = $value($method);
			}
			$status = is_array($value) && isset($value['_http']) ? $value['_http'] : 200;
			if (is_array($value)) {
				unset($value['_http']);
			}
			$response->method('getStatusCode')->willReturn($status);
			$response->method('getBody')->willReturn(is_string($value) ? $value : json_encode($value));
			$response->method('getHeader')->willReturnCallback(static fn ($name) => $name === 'Content-Type' ? 'application/json' : '');
			return $response;
		});
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($enabled);
		$apps->method('getAppVersion')->willReturn($version);
		$configuration = new Configuration($config);
		$policy = new ProviderPolicy($config, $configuration, new ScopePolicy($config, $this->createMock(IRootFolder::class), $users, $this->createMock(IManager::class)), $users);
		return [new CommunicationAuthority(new NativeApiBridge($config, $clients, $request, $session, $users), $policy, $apps, $users), $client];
	}
	private function account(): array {
		return ['accountId' => 1, 'name' => 'Synthetic account', 'emailAddress' => 'alice@example.invalid', 'isDelegated' => false,
			'draftsMailboxId' => 2, 'sentMailboxId' => 3, 'imapUser' => 'private-native-login', 'imapHost' => 'private-imap', 'smtpHost' => 'private-smtp', 'signature' => 'private native signature', 'aliases' => []];
	}
	private function room(array $changes = []): array {
		return [...['token' => 'abcd1234', 'actorType' => 'users', 'actorId' => 'alice', 'participantType' => 3, 'type' => 2,
			'permissions' => 502, 'displayName' => 'Synthetic room', 'readOnly' => 0, 'lobbyState' => 0, 'isArchived' => false, 'listable' => 0, 'lastPing' => 99, 'unreadMessages' => 3], ...$changes];
	}
	private function talkResponses(array $room): array {
		$ocs = static fn ($data) => ['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => 200], 'data' => $data]];
		return ['/ocs/v2.php/apps/spreed/api/v4/room/abcd1234?format=json' => $ocs($room),
			'/ocs/v2.php/apps/spreed/api/v4/room/abcd1234/participants?format=json&includeStatus=false' => $ocs([
				['roomToken' => 'abcd1234', 'actorId' => 'alice', 'actorType' => 'users', 'displayName' => 'Alice', 'participantType' => 3, 'permissions' => $room['permissions'], 'lastPing' => 100, 'sessionIds' => ['must-stay-native'], 'attendeePin' => 'must-stay-native'],
				['roomToken' => 'abcd1234', 'actorId' => 'bob', 'actorType' => 'users', 'displayName' => 'Bob', 'participantType' => 3, 'permissions' => 502],
			])];
	}
	public function testMailProjectionExcludesConnectionSettingsAndOtherAccounts(): void {
		$account = $this->account();
		[$native] = $this->fixture(['/apps/mail/api/accounts' => [$account, [...$account, 'accountId' => 2]]]);
		$value = $native->mailAccounts();
		self::assertCount(1, $value);
		self::assertStringNotContainsString('private-', json_encode($value));
		self::assertSame('alice@example.invalid', $value[0]['account']['emailAddress']);
	}
	public function testDelegationUsesNativeCurrentUserAccountDiscovery(): void {
		[$native] = $this->fixture(['/apps/mail/api/accounts' => [[...$this->account(), 'isDelegated' => true]]]);
		self::assertTrue($native->mailAccount(1)['account']['isDelegated']);
	}
	public function testNativeMailboxCacheLockRetriesOnlyTheBoundedMessageRead(): void {
		$reads = 0;
		$syncs = 0;
		[$native] = $this->fixture(['/apps/mail/api/accounts' => [$this->account()],
			'/apps/mail/api/mailboxes?accountId=1' => ['id' => 1, 'mailboxes' => [['databaseId' => 2, 'accountId' => 1, 'displayName' => 'Drafts', 'mailboxes' => []]]],
			'/apps/mail/api/mailboxes/2/sync' => static function ($method) use (&$syncs) {
				self::assertSame('POST', $method);
				$syncs++;
				return ['_http' => 409];
			},
			'/apps/mail/api/messages?mailboxId=2&limit=20&view=singleton' => static function ($method) use (&$reads) {
				self::assertSame('GET', $method);
				return ++$reads === 1 ? ['_http' => 409] : [['databaseId' => 9, 'mailboxId' => 2]];
			},
		]);
		self::assertSame(9, $native->mailMessages($native->mailboxes(1), 2)[0]['databaseId']);
		self::assertSame(1, $syncs);
		self::assertSame(2, $reads);
	}
	public function testForeignConfiguredScopeStopsBeforeTransport(): void {
		[$native, $client] = $this->fixture([]);
		$client->expects(self::never())->method('request');
		$this->expectException(Problem::class);
		$native->mailAccount(2);
	}
	public function testRevokedNativeAccountCannotBeResolvedFromConfigurationAlone(): void {
		[$native] = $this->fixture(['/apps/mail/api/accounts' => []]);
		$this->expectException(Problem::class);
		$native->mailAccount(1);
	}
	public function testMessageFromAnotherAccountCannotLeadToABodyRead(): void {
		[$native] = $this->fixture(['/apps/mail/api/accounts' => [$this->account()],
			'/apps/mail/api/mailboxes?accountId=1' => ['id' => 1, 'mailboxes' => [['databaseId' => 2, 'accountId' => 1, 'displayName' => 'Drafts', 'mailboxes' => []]]],
			'/apps/mail/api/messages/7' => ['databaseId' => 7, 'mailboxId' => 99]]);
		$this->expectException(Problem::class);
		$native->mailEnvelope(1, 7);
	}
	public function testNativeMailAppAbsenceStopsBeforeTransport(): void {
		[$native, $client] = $this->fixture([], false);
		$client->expects(self::never())->method('request');
		$this->expectException(Problem::class);
		$native->mailAccount(1);
	}
	public function testUncertifiedVersionStopsBeforeTransport(): void {
		[$native, $client] = $this->fixture([], true, '5.14.0');
		$client->expects(self::never())->method('request');
		$this->expectException(Problem::class);
		$native->mailAccount(1);
	}
	public function testTalkAudienceOmitsTransientSessionsPresenceAndPins(): void {
		[$native] = $this->fixture($this->talkResponses($this->room()), true, '25.0.7', 'talk');
		$value = $native->talkRoom('abcd1234');
		self::assertTrue($value['canWrite']);
		self::assertSame('room-members', $value['audience']);
		self::assertStringNotContainsString('must-stay-native', json_encode($value));
		self::assertStringNotContainsString('lastPing', json_encode($value));
		self::assertStringNotContainsString('unreadMessages', json_encode($value));
	}
	public function testReadOnlyNativeTalkRightsDoNotBecomeAgentWriteRights(): void {
		foreach ([['readOnly' => 1], ['permissions' => 374], ['isArchived' => true], ['lobbyState' => 1]] as $change) {
			[$native] = $this->fixture($this->talkResponses($this->room($change)), true, '25.0.7', 'talk');
			self::assertFalse($native->talkRoom('abcd1234')['canWrite']);
		}
	}
	public function testPublicRoomPreviewDoesNotClaimAPrivateAudience(): void {
		[$native] = $this->fixture($this->talkResponses($this->room(['type' => 3])), true, '25.0.7', 'talk');
		self::assertSame('public-or-discoverable', $native->talkRoom('abcd1234')['audience']);
	}
	public function testNativeTalkActorMustMatchTheCurrentSession(): void {
		[$native] = $this->fixture($this->talkResponses($this->room(['actorId' => 'bob'])), true, '25.0.7', 'talk');
		$this->expectException(Problem::class);
		$native->talkRoom('abcd1234');
	}
	public function testOcsFailureInsideHttp200IsRejected(): void {
		[$native] = $this->fixture(['/ocs/v2.php/apps/spreed/api/v4/room/abcd1234?format=json' => ['ocs' => ['meta' => ['status' => 'failure', 'statuscode' => 997], 'data' => []]]], true, '25.0.7', 'talk');
		$this->expectException(Problem::class);
		$native->talkRoom('abcd1234');
	}
	public function testMalformedNativeBodyIsRejected(): void {
		[$native] = $this->fixture(['/apps/mail/api/accounts' => '<html>login</html>']);
		$this->expectException(Problem::class);
		$native->mailAccount(1);
	}
	public function testNewProvidersDefaultOff(): void {
		[$native, $client] = $this->fixture([], true, '5.13.1', 'mail', ['provider_policy' => '{"version":1}']);
		$client->expects(self::never())->method('request');
		$this->expectException(Problem::class);
		$native->mailAccount(1);
	}
}
