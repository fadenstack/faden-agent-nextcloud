<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCA\FadenstackAgent\Identity\CloudApi;
use OCA\FadenstackAgent\Identity\CloudToolPolicy;
use OCA\FadenstackAgent\Identity\VerifiedCloudPrincipal;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CloudPolicyTest extends TestCase {
	private const TOKEN = 'synthetic-agent-device-token';

	private function principal(): VerifiedCloudPrincipal {
		return VerifiedCloudPrincipal::fromAuthenticatedProfile('https://cloud.example.test', 'agent', [
			'object' => 'agent.profile', 'id' => 'agent', 'slug' => 'agent', 'version' => 1, 'draft' => false,
			'principal' => $this->decision()['principal'], 'host_tool_contract' => [], 'tool_modes' => [], 'extensions' => [],
		]);
	}
	private function decision(): array {
		return ['decision' => 'ask', 'tool_class' => 'destructive', 'profile_stamp' => str_repeat('a', 64),
			'arguments_stamp' => str_repeat('b', 64), 'decision_stamp' => str_repeat('c', 64),
			'principal' => ['tenant_id' => 'tenant', 'user_id' => 'user', 'device_id' => 'device', 'agent_id' => 'agent']];
	}
	private function api(IClient $client): CloudApi {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => ['integration_enabled' => '1', 'server_url' => 'https://cloud.example.test', 'agent' => 'agent'][$key] ?? $default);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		return new CloudApi(new Configuration($config), $clients);
	}
	private function response(array $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeader')->willReturnCallback(static fn ($name) => $name === 'Content-Type' ? 'application/json' : '');
		$response->method('getBody')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));
		return $response;
	}
	public function testBothServerChecksUseThePinnedToolAndContentFreeArguments(): void {
		$client = $this->createMock(IClient::class);
		$calls = [];
		$client->expects(self::exactly(2))->method('post')->willReturnCallback(function ($url, $options) use (&$calls) {
			self::assertSame('https://cloud.example.test/v1/agents/agent/tools/check', $url);
			self::assertFalse($options['allow_redirects']);
			self::assertFalse($options['cookies']);
			self::assertArrayNotHasKey('verify', $options);
			self::assertSame('Bearer ' . self::TOKEN, $options['headers']['Authorization']);
			self::assertSame(['action_id' => str_repeat('d', 32)], $options['json']['arguments']);
			self::assertSame(CloudToolPolicy::schemaHash(), $options['json']['schema_sha256']);
			$calls[] = $options['json'];
			return $this->response($this->decision());
		});
		$policy = new CloudToolPolicy($this->api($client));
		$prepared = $policy->check(self::TOKEN, $this->principal(), 'undo', str_repeat('d', 32));
		self::assertSame($prepared, $policy->check(self::TOKEN, $this->principal(), 'undo', str_repeat('d', 32), $prepared));
		self::assertSame(['prepare', 'dispatch'], array_column($calls, 'phase'));
		self::assertSame(str_repeat('a', 64), $calls[1]['prepared_profile']);
		self::assertSame('ask', $calls[1]['tool_mode']);
	}
	public static function invalidDecisions(): array {
		return [['decision', 'deny'], ['decision', 'run'], ['tool_class', 'read'], ['principal', []],
			['principal', ['tenant_id' => 'tenant', 'user_id' => 'other', 'device_id' => 'device', 'agent_id' => 'agent']],
			['profile_stamp', 'forged'], ['arguments_stamp', null], ['decision_stamp', str_repeat('d', 65)]];
	}
	#[DataProvider('invalidDecisions')]
	public function testDenyDowngradeForeignActorAndInvalidStampsAreRefused(string $key, mixed $value): void {
		$body = $this->decision();
		$body[$key] = $value;
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($this->response($body));
		$this->expectException(Problem::class);
		(new CloudToolPolicy($this->api($client)))->check(self::TOKEN, $this->principal(), 'undo', str_repeat('d', 32));
	}
	public function testTheNativeSideAlsoRejectsChangedDispatchStamps(): void {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($this->response($this->decision()));
		$this->expectException(Problem::class);
		(new CloudToolPolicy($this->api($client)))->check(self::TOKEN, $this->principal(), 'redo', str_repeat('d', 32), [
			'profile_stamp' => str_repeat('a', 64), 'arguments_stamp' => str_repeat('b', 64), 'decision_stamp' => str_repeat('f', 64),
		]);
	}
	public function testNoOtherRouteCanReceiveTheToken(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');
		$client->expects(self::never())->method('post');
		$this->expectException(Problem::class);
		$this->api($client)->request(self::TOKEN, '../other-service', []);
	}

	public function testPinnedSchemaMatchesTheIndependentSdkStrictJsonFingerprint(): void {
		self::assertSame('7e36e734beb61c0278907d2bd106c0c92ca23732196553e4b31978bb9760541b', CloudToolPolicy::schemaHash());
	}
}
