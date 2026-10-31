<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\Providers\NativeApiBridge;
use OCA\FadenstackAgent\Providers\NativeRequest;
use OCA\FadenstackAgent\Providers\ProviderPolicy;
use OCA\FadenstackAgent\Providers\StructuredAuthority;
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

final class StructuredProviderTest extends TestCase {
	private function fixture(string $body, bool $enabled = true, string $version = '2.4.0'): array {
		$settings = ['integration_enabled' => '1', 'development' => '1', 'provider_reads_enabled' => '1', 'provider_writes_enabled' => '1', 'server_url' => 'https://cloud.test', 'agent' => 'test',
			'provider_policy' => json_encode(['version' => 1, 'tables' => ['enabled' => true, 'writesEnabled' => true, 'collections' => ['5'], 'sourceClass' => 'standard']])];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default = '') => $settings[$key] ?? $default);
		$config->method('getSystemValueString')->willReturn('https://cloud.test');
		$users = $this->createMock(IUserSession::class); $user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice'); $users->method('getUser')->willReturn($user);
		$session = $this->createMock(ISession::class); $session->method('getId')->willReturn('native-session');
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn ($name) => $name === 'Cookie' ? 'session=synthetic-only' : 'synthetic-csrf');
		$clients = $this->createMock(IClientService::class); $client = $this->createMock(IClient::class); $clients->method('newClient')->willReturn($client);
		$response = $this->createMock(IResponse::class); $response->method('getStatusCode')->willReturn(200); $response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturnCallback(static fn ($name) => $name === 'Content-Type' ? 'application/json' : '');
		$client->method('request')->willReturn($response);
		$apps = $this->createMock(IAppManager::class); $apps->method('isEnabledForUser')->willReturn($enabled); $apps->method('getAppVersion')->willReturn($version);
		$configuration = new Configuration($config);
		$policy = new ProviderPolicy($config, $configuration, new ScopePolicy($config, $this->createMock(IRootFolder::class), $users, $this->createMock(IManager::class)), $users);
		return [new StructuredAuthority(new NativeApiBridge($config, $clients, $request, $session, $users), $policy, $apps, $users), $client];
	}
	public function testMoveNamesDestinationInTheNativeRouteBecauseRouteParametersOverrideJson(): void {
		$request = NativeRequest::structured('alice', 'deck', 'move', 2, 7, 5, ['stackId' => 6, 'order' => 0]);
		self::assertSame('/apps/deck/api/v1.0/boards/2/stacks/6/cards/7/reorder', $request->path);
		self::assertSame(['stackId' => 6, 'order' => 0], json_decode($request->body, true));
		self::assertSame('PUT', $request->method);
	}
	public function testClosedFactoryCannotIntroduceGenericRoutesOrInvalidParents(): void {
		foreach ([['other', 'read', 1, 0, 0], ['tables', 'https://other.test', 1, 0, 0], ['tables', 'metadata', 0, 0, 0], ['tables', 'delete_row', 1, 0, 0], ['deck', 'patch', 1, 2, 0], ['deck', 'move', 1, 2, 3]] as $args) {
			try { NativeRequest::structured('alice', ...$args); self::fail('Unsafe native route accepted'); } catch (Problem) { self::assertTrue(true); }
		}
	}
	public function testRowsAlwaysHaveAFixedServerOwnedLimit(): void {
		$request = NativeRequest::structured('alice', 'tables', 'rows', 5);
		self::assertSame('/apps/tables/api/1/tables/5/rows?limit=101&offset=0', $request->path);
		self::assertSame('', $request->body);
		self::assertArrayNotHasKey('Authorization', $request->headers);
	}
	public function testHttp200OcsFailureIsNotNativeSuccess(): void {
		[$authority] = $this->fixture('{"ocs":{"meta":{"status":"failure","statuscode":997},"data":[]}}');
		$this->expectException(Problem::class);
		$authority->request(['actor' => 'alice'], 'tables', 'metadata', 5);
	}
	public function testMalformedJsonIsNotNativeSuccess(): void {
		[$authority] = $this->fixture('<html>login</html>');
		$this->expectException(Problem::class);
		$authority->request(['actor' => 'alice'], 'tables', 'metadata', 5);
	}
	public function testNativeReadOnlyShareCannotGainOwnerMutationRights(): void {
		[$authority] = $this->fixture('{"id":5,"title":"Shared table","ownership":"bob","onSharePermissions":{"read":true,"create":false,"update":false,"delete":false,"manage":false}}');
		$value = $authority->collection('tables', 5);
		self::assertTrue($value['rights']['read']);
		self::assertFalse($value['rights']['create']); self::assertFalse($value['rights']['update']); self::assertFalse($value['rights']['manage']);
		self::assertSame('unknown', $value['audience']);
	}
	public function testMissingNativeAppStopsBeforeTransport(): void {
		[$authority, $client] = $this->fixture('{}', false);
		$client->expects(self::never())->method('request');
		$this->expectException(Problem::class); $authority->collection('tables', 5);
	}
	public function testUncertifiedNativeVersionStopsBeforeTransport(): void {
		[$authority, $client] = $this->fixture('{}', true, '2.5.0');
		$client->expects(self::never())->method('request');
		$this->expectException(Problem::class); $authority->collection('tables', 5);
	}
	public function testARefinedCreationAuthorityKeepsItsImmutableOperationReference(): void {
		[$authority] = $this->fixture('{"id":5,"title":"Table","ownership":"alice"}');
		$value = $authority->resource(new JournalResource('tables', 'collection:5/row:' . str_repeat('a', 48), 'collection:5/row:9'));
		self::assertSame(5, $value['data']['id']);
	}
	public function testRowAuthorityCannotBeReboundToADifferentKnownRow(): void {
		[$authority, $client] = $this->fixture('{}');
		$client->expects(self::never())->method('request');
		$this->expectException(Problem::class);
		$authority->resource(new JournalResource('tables', 'collection:5/row:8', 'collection:5/row:9'));
	}
}
