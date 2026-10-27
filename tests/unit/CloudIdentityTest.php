<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\Identity\CloudIdentityVerifier;
use OCA\FadenstackAgent\Identity\VerifiedCloudPrincipal;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CloudIdentityTest extends TestCase {
	private const TOKEN = 'synthetic-agent-token-only';

	private static function profile(): array {
		return ['object' => 'agent.profile', 'id' => 'agent-id', 'slug' => 'agent-a', 'version' => 7, 'draft' => false,
			'principal' => ['tenant_id' => 'tenant-a', 'user_id' => 'user-a', 'agent_id' => 'agent-id', 'device_id' => 'device-a'],
			'host_tool_contract' => [['name' => 'nc_actions_undo', 'risk' => 'destructive']],
			'tool_modes' => ['default' => 'ask', 'ceiling' => null], 'extensions' => []];
	}

	private function verifier(IClient $client, array $overrides = []): CloudIdentityVerifier {
		$values = [...['integration_enabled' => '1', 'server_url' => 'https://cloud.example.test', 'agent' => 'agent-a', 'development' => '0'], ...$overrides];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => $values[$key] ?? $default);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		return new CloudIdentityVerifier(new Configuration($config), $clients);
	}

	private function response(mixed $body, int $status = 200, array $headers = []): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getHeader')->willReturnCallback(static fn ($name) => $headers[$name] ?? ($name === 'Content-Type' ? 'application/json; charset=utf-8' : ''));
		return $response;
	}

	public function testOnlyTheConfiguredServerGetsTheEphemeralToken(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())->method('get')->with('https://cloud.example.test/v1/agents/agent-a/profile', self::callback(static function (array $options): bool {
			self::assertSame(['Authorization' => 'Bearer ' . self::TOKEN, 'Accept' => 'application/json', 'Cache-Control' => 'no-cache'], $options['headers']);
			self::assertFalse($options['allow_redirects']);
			self::assertArrayNotHasKey('verify', $options, 'Keep Nextcloud verified CA-bundle defaults, including configured private CAs.');
			self::assertSame(['allow_local_address' => true], $options['nextcloud']);
			self::assertFalse($options['cookies']);
			self::assertTrue($options['stream']);
			self::assertLessThanOrEqual(5, $options['timeout']);
			return true;
		}))->willReturn($this->response(json_encode(self::profile(), JSON_THROW_ON_ERROR)));
		$principal = $this->verifier($client)->verify(self::TOKEN);
		self::assertSame('user-a', $principal->userId);
		self::assertSame('device-a', $principal->deviceId);
		self::assertStringNotContainsString(self::TOKEN, serialize($principal));
	}

	public static function rejectedTokens(): array {
		return [[''], ['Bearer ' . self::TOKEN], ["synthetic-token\r\nCookie: other"], [str_repeat('a', 8193)]];
	}

	#[DataProvider('rejectedTokens')]
	public function testInvalidTokensNeverReachTheNetwork(string $token): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');
		$this->expectException(Problem::class);
		$this->verifier($client)->verify($token);
	}

	public static function rejectedConfigurations(): array {
		return [[['integration_enabled' => '0']], [['server_url' => 'http://remote.example.test']],
			[['server_url' => 'https://user:secret@cloud.example.test']], [['server_url' => 'https://cloud.example.test/path']],
			[['agent' => '../another-agent']]];
	}

	#[DataProvider('rejectedConfigurations')]
	public function testInvalidConfigurationNeverDisclosesTheToken(array $configuration): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');
		$this->expectException(Problem::class);
		$this->verifier($client, $configuration)->verify(self::TOKEN);
	}

	public static function rejectedResponses(): array {
		return [[302, '{}', []], [401, '{}', []], [403, '{}', []], [404, '{}', []], [500, '{}', []],
			[200, '{}', ['Content-Type' => 'text/html']], [200, 'null', []], [200, '{', []],
			[200, '{}', ['Content-Length' => '999999999999999999999']], [200, '{}', ['Content-Length' => '-1']],
			[200, str_repeat('x', CloudIdentityVerifier::MAX_PROFILE_BYTES + 1), []]];
	}

	#[DataProvider('rejectedResponses')]
	public function testRedirectErrorsAndMalformedProfilesFailClosed(int $status, string $body, array $headers): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->response($body, $status, $headers));
		$this->expectException(Problem::class);
		$this->verifier($client)->verify(self::TOKEN);
	}

	public function testNativeClientExceptionsDoNotExposeCredentials(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new \RuntimeException('Authorization: Bearer ' . self::TOKEN));
		try {
			$this->verifier($client)->verify(self::TOKEN);
			self::fail('An unavailable verifier must not succeed.');
		} catch (Problem $error) {
			self::assertSame(503, $error->status);
			self::assertStringNotContainsString(self::TOKEN, $error->getMessage());
			self::assertNull($error->getPrevious());
		}
	}

	public function testResponseStreamsAreClosedOnSuccessAndDenial(): void {
		foreach ([200, 401] as $status) {
			$body = fopen('php://temp', 'w+');
			fwrite($body, json_encode(self::profile(), JSON_THROW_ON_ERROR));
			rewind($body);
			$client = $this->createMock(IClient::class);
			$client->method('get')->willReturn($this->response($body, $status));
			try {
				$this->verifier($client)->verify(self::TOKEN);
			} catch (Problem $error) {
				self::assertSame(401, $error->status);
			}
			self::assertFalse(is_resource($body));
		}
	}

	public function testEveryVerificationRechecksTheCurrentDeviceAndGrant(): void {
		$first = self::profile();
		$second = self::profile();
		$second['principal']['user_id'] = 'user-b';
		$client = $this->createMock(IClient::class);
		$client->expects(self::exactly(3))->method('get')->willReturnOnConsecutiveCalls(
			$this->response(json_encode($first, JSON_THROW_ON_ERROR)), $this->response(json_encode($second, JSON_THROW_ON_ERROR)), $this->response('{}', 403));
		$verifier = $this->verifier($client);
		$a = $verifier->verify(self::TOKEN);
		$b = $verifier->verify(self::TOKEN);
		self::assertNotSame($a->namespaceHash, $b->namespaceHash);
		$this->expectException(Problem::class);
		$verifier->verify(self::TOKEN);
	}

	public static function invalidPrincipals(): array {
		return [['principal', null], ['principal', []], ['principal.device_id', null], ['principal.user_id', "user\nforged"],
			['principal.tenant_id', ''], ['principal.agent_id', 'other-agent'], ['version', null], ['version', 0],
			['draft', true], ['id', 'other-agent'], ['slug', 'other-agent'], ['object', 'made-up.profile']];
	}

	#[DataProvider('invalidPrincipals')]
	public function testMissingForgedDraftAndWrongAgentIdentityIsRefused(string $field, mixed $value): void {
		$profile = self::profile();
		if (str_starts_with($field, 'principal.')) {
			$profile['principal'][substr($field, 10)] = $value;
		} else {
			$profile[$field] = $value;
		}
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->response(json_encode($profile, JSON_THROW_ON_ERROR)));
		$this->expectException(Problem::class);
		$this->verifier($client)->verify(self::TOKEN);
	}

	public function testPairNamespaceDoesNotAdoptLegacyOrAnotherCloudUsersJournal(): void {
		$scope = hash('sha256', 'native scope');
		$binding = 'native-session-binding';
		$a = VerifiedCloudPrincipal::fromAuthenticatedProfile('https://cloud.example.test', 'agent-a', self::profile());
		$bProfile = self::profile();
		$bProfile['principal']['user_id'] = 'user-b';
		$b = VerifiedCloudPrincipal::fromAuthenticatedProfile('https://cloud.example.test', 'agent-a', $bProfile);
		$legacy = new JournalIdentity('native-user', $scope, $binding);
		$paired = JournalIdentity::forPrincipal('native-user', $scope, $binding, $a);
		$other = JournalIdentity::forPrincipal('native-user', $scope, $binding, $b);
		self::assertNotSame($legacy->scopeHash, $paired->scopeHash);
		self::assertNotSame($paired->scopeHash, $other->scopeHash);
		self::assertSame($legacy->ownerHash, $paired->ownerHash, 'Quotas remain per native user across cloud identities.');
		self::assertNotSame($paired->ownerHash, JournalIdentity::forPrincipal('another-native-user', $scope, $binding, $a)->ownerHash);
	}

	public function testDeviceAndProfileDriftInvalidateClaimsButKeepTheDurableUserNamespace(): void {
		$a = VerifiedCloudPrincipal::fromAuthenticatedProfile('https://cloud.example.test', 'agent-a', self::profile());
		$profile = self::profile();
		$profile['principal']['device_id'] = 'device-b';
		$profile['version'] = 8;
		$b = VerifiedCloudPrincipal::fromAuthenticatedProfile('https://cloud.example.test', 'agent-a', $profile);
		$first = JournalIdentity::forPrincipal('native-user', hash('sha256', 'scope'), 'native-session-binding', $a);
		$next = JournalIdentity::forPrincipal('native-user', hash('sha256', 'scope'), 'native-session-binding', $b);
		self::assertSame($first->scopeHash, $next->scopeHash);
		self::assertNotSame($first->sessionHash, $next->sessionHash);
	}

	public static function namespaceChanges(): array {
		return [['origin'], ['tenant'], ['user'], ['agent']];
	}

	#[DataProvider('namespaceChanges')]
	public function testCloudAuthorityBoundariesNeverShareAHistoryNamespace(string $change): void {
		$a = VerifiedCloudPrincipal::fromAuthenticatedProfile('https://cloud.example.test', 'agent-a', self::profile());
		$profile = self::profile();
		$origin = 'https://cloud.example.test';
		if ($change === 'origin') {
			$origin = 'https://other.example.test';
		} elseif ($change === 'agent') {
			$profile['principal']['agent_id'] = $profile['id'] = 'other-agent';
		} else {
			$profile['principal'][$change . '_id'] = 'other-' . $change;
		}
		$b = VerifiedCloudPrincipal::fromAuthenticatedProfile($origin, 'agent-a', $profile);
		self::assertNotSame($a->namespaceHash, $b->namespaceHash);
	}

	public function testStreamReadFailuresDoNotExposeTransportDetails(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willThrowException(new \RuntimeException(self::TOKEN));
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		try {
			$this->verifier($client)->verify(self::TOKEN);
			self::fail('A failed body read must not authenticate a principal.');
		} catch (Problem $error) {
			self::assertSame(503, $error->status);
			self::assertStringNotContainsString(self::TOKEN, $error->getMessage());
		}
	}

	public function testConfigurationDriftDuringTheVerificationInvalidatesTheResult(): void {
		$state = (object)['changed' => false];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function ($app, $key, $default) use ($state) {
			$values = ['integration_enabled' => '1', 'server_url' => $state->changed ? 'https://changed.example.test' : 'https://cloud.example.test',
				'agent' => 'agent-a', 'development' => '0'];
			return $values[$key] ?? $default;
		});
		$response = $this->response(json_encode(self::profile(), JSON_THROW_ON_ERROR));
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())->method('get')->willReturnCallback(static function () use ($state, $response) {
			$state->changed = true;
			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		try {
			(new CloudIdentityVerifier(new Configuration($config), $clients))->verify(self::TOKEN);
			self::fail('A changed configured authority must invalidate an in-flight result.');
		} catch (Problem $error) {
			self::assertSame(409, $error->status);
		}
	}
}
