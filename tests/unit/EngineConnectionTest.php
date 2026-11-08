<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Controller\EngineConnectionsController;
use OCA\FadenstackAgent\Providers\EngineBroker;
use OCA\FadenstackAgent\Providers\EngineEndpoint;
use OCA\FadenstackAgent\Providers\EngineReads;
use OCA\FadenstackAgent\Providers\EngineRequest;
use OCA\FadenstackAgent\Providers\EngineVault;
use OCA\FadenstackAgent\Providers\ProviderPolicy;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
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
use OCP\Share\IManager;
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
		return [$endpoints, $vault, new EngineBroker($endpoints, $vault, $users, $session, $clients), $state, $config, $users];
	}
	private function reads(string $provider): array {
		[, $vault, $broker, $state, $config, $users] = $this->fixture($provider);
		$state->settings += ['integration_enabled' => '1', 'server_url' => 'https://faden.example.test', 'agent' => 'engine-test',
			'development' => '1', 'provider_reads_enabled' => '1',
			'provider_policy' => json_encode(['version' => 1, $provider => ['enabled' => true, 'collections' => ['engine-test'], 'sourceClass' => 'standard']]),
			'scope_policy' => json_encode(['revision' => 'test-1', 'sources' => [['kind' => 'user-files']], 'tools' => [EngineReads::TOOLS[$provider]],
				'sourceClasses' => ['standard'], 'downstreamPolicy' => 'unverified'])];
		$scope = new ScopePolicy($config, $this->createMock(IRootFolder::class), $users, $this->createMock(IManager::class));
		$policy = new ProviderPolicy($config, new Configuration($config), $scope, $users);
		$this->connected($vault);
		return [new EngineReads($vault, $broker, $policy), $vault, $state];
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
		self::assertStringEndsWith('/flows/get/u/alice/new_flow', EngineRequest::definition('alice', $endpoints->get('engine-test'), 'u/alice/new_flow')->path);
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
	public function testEngineReadDiscoveryRequiresOwnConnectionAndBothNativeGates(): void {
		[$reads, , $state] = $this->reads('windmill');
		self::assertSame(['nc_windmill_read'], $reads->capabilities()[0]['tools']);
		$discovery = $reads->read('windmill', []);
		self::assertSame('engine-test', $discovery['connections'][0]['connectionId']);
		self::assertSame('{}', $discovery['resource']['filename']);
		self::assertStringNotContainsString(self::TOKEN, json_encode($discovery));
		$state->actor = 'bob';
		self::assertSame([], $reads->capabilities());
		$this->refused(fn () => $reads->read('windmill', []), 403);
		$state->actor = 'alice';
		foreach (['provider_reads_enabled', 'development', 'integration_enabled'] as $gate) {
			$state->settings[$gate] = '0';
			self::assertSame([], $reads->capabilities());
			$this->refused(fn () => $reads->read('windmill', []), 403);
			$state->settings[$gate] = '1';
		}
	}
	public function testReadScopeAndMalformedSelectorsRefuseBeforeEngineHttp(): void {
		[$reads, , $state] = $this->reads('windmill');
		foreach ([['url' => 'https://untrusted.test'], ['connectionId' => []], ['reference' => 'u/alice/x'],
			['connectionId' => 'engine-test', 'reference' => '../escape']] as $args) {
			$this->refused(fn () => $reads->read('windmill', $args), 400);
		}
		$this->refused(fn () => $reads->read('windmill', ['connectionId' => 'outside-scope']), 403);
		self::assertSame([], $state->trace);
		$state->settings['scope_policy'] = json_encode(['revision' => 'no-engine', 'sources' => [['kind' => 'user-files']], 'tools' => ['nc_read_file']]);
		$this->refused(fn () => $reads->read('windmill', []), 403);
		self::assertSame([], $state->trace);
	}
	public function testReadDefinitionsPreserveGraphTypesUnknownFieldsAndNativeCredentialsReferences(): void {
		foreach (['windmill', 'n8n'] as $provider) {
			[$reads, , $state] = $this->reads($provider);
			$reference = $provider === 'windmill' ? 'u/alice/synthetic' : 'synthetic-workflow';
			$state->body = $provider === 'windmill'
				? '{"path":"u/alice/synthetic","value":{"modules":[],"future":{}},"schema":{"properties":{},"x-fadenstack-provenance":{"opaque":true}}}'
				: '{"id":"synthetic-workflow","nodes":[{"credentials":{"test":{"id":"native-ref"}}}],"connections":{},"pinData":{"secret":"not-for-model"},"future":{}}';
			$result = $reads->read($provider, ['connectionId' => 'engine-test', 'reference' => $reference]);
			$definition = $result['definition'];
			self::assertInstanceOf(\stdClass::class, $definition);
			self::assertStringNotContainsString(self::TOKEN, json_encode($result));
			self::assertObjectNotHasProperty('pinData', $definition);
			self::assertInstanceOf(\stdClass::class, $provider === 'windmill' ? $definition->schema->properties : $definition->connections);
			self::assertCount(1, $state->trace);
			self::assertSame('GET', $state->trace[0]['method']);
			self::assertSame('unknown', $result['resource']['audience']);
			if ($provider === 'n8n') {
				self::assertSame('native-ref', $definition->nodes[0]->credentials->test->id);
			}
		}
	}
	public function testReadCollectionsAreBoundedAndPaginationDoesNotClaimCompleteness(): void {
		foreach (['windmill', 'n8n'] as $provider) {
			[$reads, , $state] = $this->reads($provider);
			$rows = array_fill(0, 21, $provider === 'windmill' ? ['path' => 'u/alice/example', 'summary' => 'Synthetic'] : ['id' => 'example', 'name' => 'Synthetic']);
			$state->body = json_encode($provider === 'windmill' ? $rows : ['data' => $rows, 'nextCursor' => 'opaque']);
			$result = $reads->read($provider, ['connectionId' => 'engine-test']);
			self::assertCount(20, $result['workflows']);
			self::assertFalse($result['complete']);
			$rows[] = $rows[0];
			$state->body = json_encode($provider === 'windmill' ? $rows : ['data' => $rows]);
			$this->refused(fn () => $reads->read($provider, ['connectionId' => 'engine-test']), 503);
		}
	}
	public function testMalformedDefinitionsAndHttp200ErrorsNeverDiscloseWorkflowData(): void {
		[$reads, , $state] = $this->reads('n8n');
		foreach (['{"id":"other","nodes":[],"connections":{}}', '{"id":"example","nodes":[],"connections":{},"error":"not authorized"}'] as $body) {
			$state->body = $body;
			$this->refused(fn () => $reads->read('n8n', ['connectionId' => 'engine-test', 'reference' => 'example']), 503);
		}
		$state->body = '{"id":"example","nodes":[],"connections":[]}';
		$this->refused(fn () => $reads->read('n8n', ['connectionId' => 'engine-test', 'reference' => 'example']), 413);
		self::assertCount(3, $state->trace);
	}
	public function testRevokedOrRotatedConnectionsCannotReturnAFormerWorkflowRead(): void {
		[$reads, $vault, $state] = $this->reads('n8n');
		$state->body = '{"id":"example","nodes":[],"connections":{}}';
		$state->afterRequest = fn () => $this->connected($vault);
		$this->refused(fn () => $reads->read('n8n', ['connectionId' => 'engine-test', 'reference' => 'example']), 409);
		$state->afterRequest = null;
		$vault->replace('engine-test', $vault->status('engine-test')['revision'], null);
		$this->refused(fn () => $reads->read('n8n', ['connectionId' => 'engine-test', 'reference' => 'example']), 403);
		self::assertCount(1, $state->trace);
	}
	public function testAnEngineCannotEchoTheVaultTokenThroughOpaqueGraphFields(): void {
		[$reads, $vault, $state] = $this->reads('n8n');
		foreach ([self::TOKEN, 'printable-"quoted"-\\-credential'] as $token) {
			$vault->replace('engine-test', $vault->status('engine-test')['revision'], $token);
			$state->body = json_encode(['id' => 'example', 'nodes' => [], 'connections' => new \stdClass(), 'opaque' => ['echo' => 'Bearer ' . $token]]);
			$this->refused(fn () => $reads->read('n8n', ['connectionId' => 'engine-test', 'reference' => 'example']), 403);
		}
	}
}
