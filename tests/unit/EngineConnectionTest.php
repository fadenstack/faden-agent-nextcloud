<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Controller\EngineConnectionsController;
use OCA\FadenstackAgent\Providers\EngineBroker;
use OCA\FadenstackAgent\Providers\EngineEndpoint;
use OCA\FadenstackAgent\Providers\EngineRequest;
use OCA\FadenstackAgent\Providers\EngineVault;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

final class EngineConnectionTest extends TestCase {
	private const TOKEN = 'synthetic-engine-api-token';
	private function fixture(string $provider = 'windmill'): array {
		$entry = ['id' => 'engine-test', 'provider' => $provider, 'label' => 'My workflow engine', 'baseUrl' => 'https://engine.example.test', 'version' => EngineEndpoint::VERSIONS[$provider]];
		if ($provider === 'windmill') {
			$entry['workspace'] = 'sandbox';
		}
		$state = (object)['settings' => ['engine_connections' => json_encode(['version' => 1, 'connections' => [$entry]])],
			'values' => [], 'actor' => 'alice', 'time' => 1000, 'keyLost' => false, 'trace' => [], 'closed' => false,
			'status' => 200, 'body' => $provider === 'windmill' ? '{"username":"alice"}' : '{"data":[]}', 'length' => '',
			'contentType' => 'application/json', 'afterRequest' => null];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default = '') => $state->settings[$key] ?? $default);
		$config->method('getUserValue')->willReturnCallback(static fn ($actor, $app, $key, $default = '') => $state->values[$actor][$key] ?? $default);
		$config->method('getUserKeys')->willReturnCallback(static fn ($actor) => array_keys($state->values[$actor] ?? []));
		$config->method('setUserValue')->willReturnCallback(static function ($actor, $app, $key, $value) use ($state): void {
			$state->values[$actor][$key] = $value;
		});
		$users = $this->createMock(IUserSession::class);
		$principals = [];
		foreach (['alice', 'bob'] as $name) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($name);
			$principals[$name] = $user;
		}
		$users->method('getUser')->willReturnCallback(static fn () => $principals[$state->actor] ?? null);
		$crypto = $this->createMock(ICrypto::class);
		// Unit envelope stub only. Native ICrypto encryption is a separate fixture check.
		$crypto->method('encrypt')->willReturnCallback(static fn ($text) => 'test-seal:' . base64_encode($text));
		$crypto->method('decrypt')->willReturnCallback(static function ($text) use ($state): string {
			if ($state->keyLost || !str_starts_with($text, 'test-seal:')) {
				throw new \RuntimeException('Synthetic key loss');
			}
			return base64_decode(substr($text, 10), true);
		});
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(static fn () => $state->time);
		$endpoints = new EngineEndpoint($config);
		$vault = new EngineVault($config, $users, $crypto, $endpoints, $this->createMock(ILockingProvider::class), $clock);
		$session = $this->createMock(ISession::class);
		$session->method('getId')->willReturn('native-session');
		$session->method('close')->willReturnCallback(static function () use ($state): void {
			$state->closed = true;
		});
		$clients = $this->createMock(IClientService::class);
		$client = $this->createMock(IClient::class);
		$clients->method('newClient')->willReturn($client);
		$client->method('request')->willReturnCallback(function ($method, $url, $options) use ($state): IResponse {
			self::assertTrue($state->closed, 'Release the native session before engine HTTP.');
			$state->trace[] = compact('method', 'url', 'options');
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($state->status);
			$response->method('getBody')->willReturn($state->body);
			$response->method('getHeader')->willReturnCallback(static fn ($key) => match ($key) {
				'Content-Length' => $state->length, 'Content-Type' => $state->contentType, default => '',
			});
			if ($state->afterRequest) {
				($state->afterRequest)();
			}
			return $response;
		});
		return [$endpoints, $vault, new EngineBroker($endpoints, $vault, $users, $session, $clients), $state];
	}
	private function connected(EngineVault $vault): array {
		return $vault->replace('engine-test', $vault->status('engine-test')['revision'], self::TOKEN);
	}
	private function refused(callable $call, int $status): void {
		try {
			$call();
			self::fail('Expected native refusal.');
		} catch (Problem $error) {
			self::assertSame($status, $error->status);
			self::assertStringNotContainsString(self::TOKEN, $error->getMessage());
		}
	}
	public function testMissingConfigurationAdvertisesNoEndpoint(): void {
		[$endpoints, , , $state] = $this->fixture();
		unset($state->settings['engine_connections']);
		self::assertSame([], $endpoints->all());
		$this->refused(fn () => $endpoints->get('engine-test'), 403);
	}
	public function testUntrustedEndpointSyntaxAndUncertifiedVersionsFailClosed(): void {
		[$endpoints, , , $state] = $this->fixture();
		$original = json_decode($state->settings['engine_connections'], true);
		foreach (['http://engine.example.test', 'https://user:pass@engine.test', 'https://engine.test/?token=secret',
			'https://engine.test/#fragment', 'https://engine.test/%2e%2e/admin', 'https://engine.test/a/../b', 'https://engine.test:99999'] as $url) {
			$value = $original;
			$value['connections'][0]['baseUrl'] = $url;
			$state->settings['engine_connections'] = json_encode($value);
			$this->refused(fn () => $endpoints->all(), 503);
		}
		$original['connections'][0]['version'] = 'untested';
		$state->settings['engine_connections'] = json_encode($original);
		$this->refused(fn () => $endpoints->all(), 503);
	}
	public function testVaultUsesOnlyCurrentNativeUserAndReturnsNoCredential(): void {
		[, $vault, , $state] = $this->fixture();
		$summary = $this->connected($vault);
		self::assertTrue($summary['connected']);
		self::assertStringNotContainsString(self::TOKEN, json_encode([$summary, $state->values]));
		self::assertSame(self::TOKEN, $vault->resolve('engine-test')['apiToken']);
		$state->actor = 'bob';
		self::assertFalse($vault->status('engine-test')['connected']);
		$state->values['bob'] = $state->values['alice'];
		$this->refused(fn () => $vault->resolve('engine-test'), 403);
	}
	public function testDisconnectAndReconnectNeverReuseAnEarlierGeneration(): void {
		[, $vault] = $this->fixture();
		$initial = $vault->status('engine-test')['revision'];
		$first = $this->connected($vault);
		$stopped = $vault->replace('engine-test', $first['revision'], null);
		self::assertFalse($stopped['connected']);
		self::assertNotSame($initial, $stopped['revision']);
		$this->refused(fn () => $vault->replace('engine-test', $initial, self::TOKEN), 409);
		$next = $this->connected($vault);
		self::assertNotSame($first['revision'], $next['revision']);
	}
	public function testConcurrentRotationRefusesStaleReplacementWithoutChangingIt(): void {
		[, $vault] = $this->fixture();
		$first = $this->connected($vault);
		$next = $this->connected($vault);
		$this->refused(fn () => $vault->replace('engine-test', $first['revision'], null), 409);
		self::assertSame($next['revision'], $vault->status('engine-test')['revision']);
	}
	public function testRemovedEndpointCanStillBeDisconnectedWithoutExposingItsCredential(): void {
		[, $vault, , $state] = $this->fixture();
		$first = $this->connected($vault);
		unset($state->settings['engine_connections']);
		$items = $vault->listing();
		self::assertCount(1, $items);
		self::assertFalse($items[0]['setupAllowed']);
		self::assertStringNotContainsString(self::TOKEN, json_encode($items));
		$this->refused(fn () => $vault->resolve('engine-test'), 403);
		$stopped = $vault->replace('engine-test', $first['revision'], null);
		self::assertFalse($stopped['connected']);
		self::assertNotSame($first['revision'], $stopped['revision']);
	}
	public function testExpiryAndKeyLossDenyCredentialAccess(): void {
		[, $vault, , $state] = $this->fixture();
		$this->connected($vault);
		$state->time += EngineVault::MAX_AGE;
		$this->refused(fn () => $vault->resolve('engine-test'), 403);
		self::assertFalse($vault->status('engine-test')['connected']);
		$this->connected($vault);
		$state->keyLost = true;
		$this->refused(fn () => $vault->resolve('engine-test'), 403);
		self::assertFalse($vault->status('engine-test')['connected']);
	}
	public function testEndpointChangeRequiresExplicitReconnect(): void {
		[$endpoints, $vault, , $state] = $this->fixture();
		$this->connected($vault);
		$settings = json_decode($state->settings['engine_connections'], true);
		$settings['connections'][0]['baseUrl'] = 'https://replacement.example.test';
		$state->settings['engine_connections'] = json_encode($settings);
		self::assertSame('https://replacement.example.test', $endpoints->get('engine-test')['baseUrl']);
		$this->refused(fn () => $vault->resolve('engine-test'), 403);
	}
	public function testHeaderInjectionAndTokensOutsideBoundsNeverReachStorage(): void {
		[, $vault, , $state] = $this->fixture();
		foreach (["bearer\r\nInjected: value", 'short', str_repeat('x', 8193), 'token with spaces', 'token-é-nonascii'] as $token) {
			$this->refused(fn () => $vault->replace('engine-test', $vault->status('engine-test')['revision'], $token), 400);
		}
		self::assertSame([], $state->values);
	}
	public function testProbeSendsOnlyEngineCredentialWithTlsAndNoRedirectsOrNativeSession(): void {
		[, , $broker, $state] = $this->fixture();
		self::assertSame(['authenticated' => true, 'principal' => 'alice'], $broker->probe('engine-test', self::TOKEN));
		$sent = $state->trace[0];
		self::assertSame('GET', $sent['method']);
		self::assertSame('https://engine.example.test/api/w/sandbox/users/whoami', $sent['url']);
		self::assertSame(['Accept', 'Cache-Control', 'Authorization'], array_keys($sent['options']['headers']));
		self::assertFalse($sent['options']['allow_redirects']);
		self::assertFalse($sent['options']['cookies']);
		self::assertTrue($sent['options']['verify']);
		self::assertFalse($sent['options']['nextcloud']['allow_local_address']);
	}
	public function testN8nProbeDoesNotInventAnOwnerIdentityFromListAccess(): void {
		[, , $broker, $state] = $this->fixture('n8n');
		self::assertSame(['authenticated' => true, 'principal' => null], $broker->probe('engine-test', self::TOKEN));
		self::assertSame('https://engine.example.test/api/v1/workflows?limit=1', $state->trace[0]['url']);
		self::assertArrayNotHasKey('Authorization', $state->trace[0]['options']['headers']);
		self::assertSame(self::TOKEN, $state->trace[0]['options']['headers']['X-N8N-API-KEY']);
	}
	public function testErrorsAndRedirectsNeverExposeUpstreamBodyOrRepeatTheRequest(): void {
		[, , $broker, $state] = $this->fixture();
		$state->body = '{"error":"' . self::TOKEN . '"}';
		foreach ([401, 403, 404, 423, 429, 302, 500] as $status) {
			$state->status = $status;
			$this->refused(fn () => $broker->probe('engine-test', self::TOKEN), $status >= 400 && $status < 500 ? $status : 503);
		}
		self::assertCount(7, $state->trace);
	}
	public function testMalformedJsonAndDeclaredOversizeAreRefused(): void {
		[, , $broker, $state] = $this->fixture();
		$state->body = '{broken';
		$this->refused(fn () => $broker->probe('engine-test', self::TOKEN), 503);
		$state->length = (string)(EngineBroker::MAX_BYTES + 1);
		$this->refused(fn () => $broker->probe('engine-test', self::TOKEN), 413);
	}
	public function testHttp200EngineErrorNeverBecomesAConnectedCredential(): void {
		foreach (['windmill', 'n8n'] as $provider) {
			[, , $broker, $state] = $this->fixture($provider);
			$state->body = $provider === 'windmill' ? '{"username":"alice","error":"refused"}' : '{"data":[],"error":"refused"}';
			$this->refused(fn () => $broker->probe('engine-test', self::TOKEN), 503);
		}
	}
	public function testOversizedChunkedStreamIsClosedAfterRefusal(): void {
		[, , $broker, $state] = $this->fixture();
		$stream = fopen('php://temp', 'w+');
		fwrite($stream, str_repeat('x', EngineBroker::MAX_BYTES + 1));
		rewind($stream);
		$state->body = $stream;
		$this->refused(fn () => $broker->probe('engine-test', self::TOKEN), 413);
		self::assertFalse(is_resource($stream));
	}
	public function testActorAndConnectionChangesDuringHttpRefuseTheResponse(): void {
		[$endpoints, $vault, $broker, $state] = $this->fixture();
		$this->connected($vault);
		$request = EngineRequest::list('alice', $endpoints->get('engine-test'));
		$state->afterRequest = static function () use ($state): void {
			$state->actor = 'bob';
		};
		$this->refused(fn () => $broker->request($request), 409);
		$state->actor = 'alice';
		$state->afterRequest = fn () => $this->connected($vault);
		$this->refused(fn () => $broker->request($request), 409);
	}
	public function testDefinitionReferencesCannotEscapeTypedRoutes(): void {
		[$endpoints] = $this->fixture();
		foreach (['../admin', 'u/alice/a?token=x', 'u/alice/%2fsecret', 'https://other.test', 'u/alice/a/../../b'] as $path) {
			$this->refused(fn () => EngineRequest::definition('alice', $endpoints->get('engine-test'), $path), 400);
		}
		self::assertStringEndsWith('/flows/get/p/u/alice/new_flow', EngineRequest::definition('alice', $endpoints->get('engine-test'), 'u/alice/new_flow')->path);
	}
	private function controller(EngineVault $vault, EngineBroker $broker, bool $csrf = true, string $ocs = '', string $binding = 'native-binding', bool $enabled = true): EngineConnectionsController {
		$request = $this->createMock(IRequest::class);
		$request->method('passesCSRFCheck')->willReturn($csrf);
		$request->method('getHeader')->willReturnCallback(static fn ($name) => match ($name) {
			'OCS-APIRequest' => $ocs, 'X-Fadenstack-Session' => $binding, default => '',
		});
		$files = $this->createMock(FileService::class);
		$check = static function ($actual): void {
			if ($actual !== 'native-binding') {
				throw new Problem('The native session changed.', 401);
			}
		};
		$files->method('checkSession')->willReturnCallback($check);
		$files->method('check')->willReturnCallback(static function ($actual) use ($check, $enabled): void {
			$check($actual);
			if (!$enabled) {
				throw new Problem('The integration is disabled.', 403);
			}
		});
		return new EngineConnectionsController('fadenstack_agent', $request, $files, $vault, $broker);
	}
	public function testHumanConnectionRoutesRejectNativeCsrfAndSessionBypassesBeforeHttp(): void {
		[, $vault, $broker, $state] = $this->fixture();
		foreach ([[false, '', 'native-binding', 403], [true, 'true', 'native-binding', 403], [true, '', 'other-binding', 401]] as [$csrf, $ocs, $binding, $status]) {
			$controller = $this->controller($vault, $broker, $csrf, $ocs, $binding);
			self::assertSame($status, $controller->connect('engine-test', hash('sha256', ''), self::TOKEN)->getStatus());
		}
		self::assertSame([], $state->trace);
		self::assertSame([], $state->values);
	}
	public function testFailedHumanProbeCannotPersistAnUnverifiedToken(): void {
		[, $vault, $broker, $state] = $this->fixture();
		$state->status = 401;
		$response = $this->controller($vault, $broker)->connect('engine-test', hash('sha256', ''), self::TOKEN);
		self::assertSame(401, $response->getStatus());
		self::assertStringNotContainsString(self::TOKEN, json_encode($response->getData()));
		self::assertSame([], $state->values);
	}
	public function testDisablingIntegrationStillAllowsOwnCredentialRevocation(): void {
		[, $vault, $broker, $state] = $this->fixture();
		$connected = $this->connected($vault);
		$controller = $this->controller($vault, $broker, enabled: false);
		self::assertSame(403, $controller->connect('engine-test', $connected['revision'], self::TOKEN)->getStatus());
		$removed = $controller->disconnect('engine-test', $connected['revision']);
		self::assertSame(200, $removed->getStatus());
		self::assertFalse($removed->getData()['connected']);
		self::assertSame([], $state->trace);
	}
	public function testSignedOutNativeUserCannotListOrResolveAConnection(): void {
		[, $vault, $broker, $state] = $this->fixture();
		$this->connected($vault);
		$state->actor = '';
		self::assertSame(401, $this->controller($vault, $broker)->listing()->getStatus());
		$this->refused(fn () => $vault->resolve('engine-test'), 401);
	}
}
