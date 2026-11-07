<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\ContentAuthority;
use OCA\FadenstackAgent\Providers\ContentProvider;
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
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager;
use PHPUnit\Framework\TestCase;

final class ContentDiscoveryTest extends TestCase {

	private function fixture(callable $reply): array {
		$scope = ['revision' => 'content-discovery', 'sources' => [['kind' => 'user-files']], 'destinations' => [],
			'tools' => ScopePolicy::CONTENT_TOOLS, 'sourceClasses' => ['standard'], 'downstreamPolicy' => 'unverified',
			'destinationAudiences' => [], 'movements' => [], 'classificationDefault' => 'standard'];
		$policy = ['version' => 1, 'forms' => ['enabled' => true, 'writesEnabled' => true, 'collections' => ['7'], 'sourceClass' => 'standard'],
			'bookmarks' => ['enabled' => true, 'writesEnabled' => true, 'collections' => ['personal'], 'sourceClass' => 'standard']];
		$settings = ['integration_enabled' => '1', 'development' => '1', 'provider_reads_enabled' => '1', 'provider_writes_enabled' => '1',
			'server_url' => 'https://cloud.test', 'agent' => 'test', 'provider_policy' => json_encode($policy), 'scope_policy' => json_encode($scope)];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default = '') => $settings[$key] ?? $default);
		$config->method('getSystemValueString')->willReturnCallback(static fn ($key, $default = '') => $key === 'overwrite.cli.url' ? 'https://cloud.test' : $default);
		$config->method('getSystemValueBool')->willReturnCallback(static fn ($key, $default = false) => $default);
		$config->method('getSystemValue')->willReturnCallback(static fn ($key, $default = '') => $default);
		$users = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$users->method('getUser')->willReturn($user);
		$session = $this->createMock(ISession::class);
		$session->method('getId')->willReturn('current-native-session');
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn ($key) => $key === 'Cookie' ? 'session=synthetic-only' : 'synthetic-csrf');
		$clients = $this->createMock(IClientService::class);
		$client = $this->createMock(IClient::class);
		$clients->method('newClient')->willReturn($client);
		$trace = (object)['paths' => []];
		$client->method('request')->willReturnCallback(function ($method, $url) use ($reply, $trace) {
			$path = substr($url, strlen('https://cloud.test'));
			$trace->paths[] = $path;
			[$status, $data] = $reply($path);
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			$response->method('getBody')->willReturn(json_encode($data));
			$response->method('getHeader')->willReturnCallback(static fn ($key) => $key === 'Content-Type' ? 'application/json' : '');
			return $response;
		});
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$apps->method('getAppVersion')->willReturnCallback(static fn ($app) => ContentAuthority::VERSIONS[$app]);
		$root = $this->createMock(IRootFolder::class);
		$configuration = new Configuration($config);
		$policies = new ProviderPolicy($config, $configuration, new ScopePolicy($config, $root, $users, $this->createMock(IManager::class)), $users);
		$authority = new ContentAuthority(new NativeApiBridge($config, $clients, $request, $session, $users), $policies, $apps, $users, $config, $this->createMock(IAppConfig::class), $this->createMock(IGroupManager::class), $root);
		return [$authority, $configuration, $trace];
	}

	private static function ocs(array $data): array {
		return [200, ['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => 100], 'data' => $data]]];
	}

	public function testOwnedMembershipDoesNotNeedSharedOrForbiddenMetadataProbes(): void {
		[$authority, , $trace] = $this->fixture(static fn ($path) => self::ocs([['id' => 7]]));
		$authority->assertListedForm(['actor' => 'alice'], 7);
		self::assertSame(['/ocs/v2.php/apps/forms/api/v3/forms?type=owned'], $trace->paths);
	}

	public function testNativeSharedMembershipIsCheckedWithoutGrantingMetadataAuthority(): void {
		[$authority, , $trace] = $this->fixture(static fn ($path) => self::ocs(str_ends_with($path, 'type=shared') ? [['id' => 7]] : []));
		$authority->assertListedForm(['actor' => 'alice'], 7);
		self::assertCount(2, $trace->paths);
		self::assertStringEndsWith('?type=shared', $trace->paths[1]);
	}

	public function testDeniedOrRevokedNativeMembershipNeverProbesTheConfiguredNumericForm(): void {
		[$authority, , $trace] = $this->fixture(static fn ($path) => self::ocs([]));
		try {
			$authority->collection('forms', '7');
			self::fail('An unlisted configured form must be refused.');
		} catch (Problem $error) {
			self::assertSame(403, $error->status);
		}
		self::assertCount(2, $trace->paths);
		self::assertNotContains('/ocs/v2.php/apps/forms/api/v3/forms/7', $trace->paths);
	}

	public function testRateLimitedFormsWithholdsItsToolsWithoutBlockingHealthyBookmarks(): void {
		[$authority, $configuration, $trace] = $this->fixture(static fn ($path) => match ($path) {
			'/ocs/v2.php/apps/forms/api/v3/forms?type=owned' => self::ocs([['id' => 7]]),
			'/ocs/v2.php/apps/forms/api/v3/forms/7' => [429, []],
			'/apps/bookmarks/folder?root=-1&layers=0' => [200, ['status' => 'success', 'data' => []]],
			default => throw new \RuntimeException('Unexpected native route ' . $path),
		});
		$reflection = new \ReflectionClass(ContentProvider::class);
		$provider = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('native')->setValue($provider, $authority);
		$reflection->getProperty('configuration')->setValue($provider, $configuration);
		$caps = array_column($provider->capabilities(), null, 'provider');
		self::assertFalse($caps['forms']['enabled']);
		self::assertSame([], $caps['forms']['tools']);
		self::assertSame(429, $caps['forms']['nativeStatus']);
		self::assertTrue($caps['bookmarks']['enabled']);
		self::assertSame(['nc_bookmarks_read', 'nc_bookmarks_create'], $caps['bookmarks']['tools']);
		self::assertSame(1, count(array_filter($trace->paths, static fn ($path) => $path === '/ocs/v2.php/apps/forms/api/v3/forms/7')));
	}
}
