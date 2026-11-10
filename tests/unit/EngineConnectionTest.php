<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Controller\EngineConnectionsController;
use OCA\FadenstackAgent\Controller\EngineOauthController;
use OCA\FadenstackAgent\History\EngineAccessResolver;
use OCA\FadenstackAgent\History\JournalException;
use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\Providers\EngineAuthority;
use OCA\FadenstackAgent\Providers\EngineBroker;
use OCA\FadenstackAgent\Providers\EngineDraftPlan;
use OCA\FadenstackAgent\Providers\EngineEndpoint;
use OCA\FadenstackAgent\Providers\EngineReads;
use OCA\FadenstackAgent\Providers\EngineRequest;
use OCA\FadenstackAgent\Providers\EngineRunPlan;
use OCA\FadenstackAgent\Providers\EngineVault;
use OCA\FadenstackAgent\Providers\EngineWebhookTargets;
use OCA\FadenstackAgent\Providers\EngineWorkspace;
use OCA\FadenstackAgent\Providers\N8nOAuthClient;
use OCA\FadenstackAgent\Providers\N8nOAuthConfiguration;
use OCA\FadenstackAgent\Providers\N8nOAuthService;
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
use OCP\IURLGenerator;
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
		$config->method('getUserValueForUsers')->willReturnCallback(static function ($app, $key, $actors) use ($state): array {
			$result = [];
			foreach ($actors as $actor) {
				if (isset($state->values[$actor][$key])) {
					$result[$actor] = $state->values[$actor][$key];
				}
			}
			return $result;
		});
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
			if (isset($state->beforeResponse)) {
				($state->beforeResponse)($url);
			}
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
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example.test/index.php/apps/fadenstack_agent/engines/oauth/callback/engine-test');
		$oauth = new N8nOAuthConfiguration($config, $endpoints, new EngineWebhookTargets($config), $urls, new Configuration($config));
		return [$endpoints, $vault, new EngineBroker($endpoints, $vault, $users, $session, $clients, new EngineWebhookTargets($config), $oauth), $state, $config, $users, $session, $oauth, $clients];
	}
	private function copyFixture(string $provider, string $kind = 'saved'): array {
		[$reads, $vault, $s, $policy, $broker, $config] = $this->reads($provider);
		$config->method('getSystemValueBool')->willReturn(true);
		$config->method('getSystemValueString')->willReturn('');
		$s->settings['provider_writes_enabled'] = '1';
		$s->settings['provider_policy'] = json_encode(['version' => 1, $provider => ['enabled' => true, 'writesEnabled' => true, 'collections' => ['engine-test'], 'sourceClass' => 'standard']]);
		$s->settings['scope_policy'] = json_encode(['revision' => 'copy-1', 'sources' => [['kind' => 'user-files']], 'tools' => ['nc_' . $provider . '_read', 'nc_' . $provider . '_draft'], 'sourceClasses' => ['standard'], 'destinationAudiences' => ['private'], 'movements' => [['source' => 'standard', 'destination' => 'private']], 'downstreamPolicy' => 'unverified']);
		if ($provider === 'n8n') {
			$encode = static fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
			$vault->replace('engine-test', $vault->status('engine-test')['revision'], $encode(['alg' => 'HS256']) . '.' . $encode(['iss' => 'n8n', 'aud' => 'public-api', 'sub' => 'cfec15c4-5036-4f30-957c-306f577c19cc']) . '.' . str_repeat('a', 43));
		}
		$s->reference = $provider === 'windmill' ? 'u/alice/source' : 'source';
		$s->source = $provider === 'windmill'
			? (object)['path' => $s->reference, 'summary' => 'Source', 'description' => 'original', 'value' => (object)['modules' => []], 'schema' => (object)['x-fadenstack-provenance' => (object)['native' => 'prior']]]
			: (object)['id' => 'source', 'name' => 'Source', 'description' => 'original', 'nodes' => [(object)['id' => 'a', 'parameters' => new \stdClass()]], 'connections' => new \stdClass(), 'settings' => new \stdClass(), 'shared' => [(object)['role' => 'workflow:owner', 'project' => (object)['type' => 'personal', 'creatorId' => 'cfec15c4-5036-4f30-957c-306f577c19cc']]]];
		$s->beforeResponse = static function ($url) use ($s, $kind): void {
			$s->status = 200;
			$s->body = json_encode(match (true) {
				str_contains($url, '/whoami') => ['username' => 'alice'],
				str_contains($url, '/drafts/get_own/flow/' . $s->reference) => ['value' => $s->source, 'updated_at' => 'stable'],
				str_contains($url, '/flows/get/' . $s->reference), str_contains($url, '/api/v1/workflows/source?') => $s->source,
				str_contains($url, '/drafts/get_own/') => null,
				default => ['data' => []],
			});
			if (str_contains($url, '/flows/get/') && !str_contains($url, '/flows/get/' . $s->reference)) {
				$s->status = 404;
				$s->body = '{}';
			}
		};
		$plan = new EngineDraftPlan(new EngineAuthority($vault, $broker, $policy), $reads, new Configuration($config));
		return [$plan, $reads, $vault, $s];
	}
	public function testSourceBoundPlanPreservesNativeOriginAndRefusesStaleOrRevokedSources(): void {
		foreach ([['windmill', 'saved'], ['windmill', 'own-draft'], ['n8n', 'saved']] as [$provider, $kind]) {
			[$plan, $reads, $vault, $s] = $this->copyFixture($provider, $kind);
			$read = $reads->read($provider, ['connectionId' => 'engine-test', 'reference' => $s->reference, 'sourceKind' => $kind]);
			self::assertSame($kind, $read['copySource']['kind']);
			$args = ['connectionId' => 'engine-test', 'sourceReference' => $s->reference, 'sourceKind' => $kind, 'sourceRevision' => $read['copySource']['revision'], 'definitionJson' => $provider === 'windmill' ? '{"summary":"Copy"}' : '{"name":"Copy"}'];
			$p = $plan->prepare('nc_' . $provider . '_draft', $args, str_repeat('a', 48));
			self::assertSame($read['copySource'], $p['copySource']);
			self::assertSame($read['resource']['revision'], $p['resource']['revision']);
			if ($provider === 'windmill') {
				self::assertSame('prior', $p['sourceOrigin']->native);
			}
			$s->source->description = 'changed';
			$this->refused(fn () => $plan->prepare('nc_' . $provider . '_draft', $args, str_repeat('b', 48)), 409);
			$vault->replace('engine-test', $vault->status('engine-test')['revision'], null);
			$this->refused(fn () => $plan->prepare('nc_' . $provider . '_draft', $args, str_repeat('c', 48)), 403);
			self::assertSame(['GET'], array_values(array_unique(array_column($s->trace, 'method'))));
		}
	}
	public function testCopyBindingRequiresBothReferenceAndDigestAndExplicitSupportedKind(): void {
		[$plan] = $this->copyFixture('n8n');
		foreach ([['sourceRevision' => str_repeat('a', 64)], ['sourceReference' => 'source'], ['sourceKind' => 'saved'], ['sourceReference' => 'source', 'sourceRevision' => 'bad'], ['sourceReference' => 'source', 'sourceRevision' => str_repeat('a', 64), 'sourceKind' => 'own-draft']] as $extra) {
			$this->refused(fn () => $plan->prepare('nc_n8n_draft', ['connectionId' => 'engine-test', 'definitionJson' => '{}', ...$extra], str_repeat('a', 48)), 400);
		}
	}
	public function testOwnDraftSourceBindingDoesNotDuplicateTheBoundedGraph(): void {
		[, $reads, , $s] = $this->copyFixture('windmill', 'own-draft');
		$s->source->value->modules = [(object)['id' => 'a', 'value' => (object)['content' => str_repeat('x', 70000)]]];
		$r = $reads->read('windmill', ['connectionId' => 'engine-test', 'reference' => $s->reference, 'sourceKind' => 'own-draft']);
		self::assertSame(70000, strlen($r['definition']->value->modules[0]->value->content));
		self::assertArrayNotHasKey('draftRevision', $r);
		self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r['copySource']['revision']);
	}
	public function testDecodedInheritedOriginCannotContainTheConnectionCredential(): void {
		[$plan, $reads, $vault, $s] = $this->copyFixture('n8n');
		$s->source->nodes[0]->notes = '<!-- fadenstack-provenance:' . base64_encode(json_encode(['echo' => $vault->resolve('engine-test')['apiToken']])) . ' -->';
		$read = $reads->read('n8n', ['connectionId' => 'engine-test', 'reference' => 'source']);
		$this->refused(fn () => $plan->prepare('nc_n8n_draft', ['connectionId' => 'engine-test', 'sourceReference' => 'source', 'sourceRevision' => $read['copySource']['revision'], 'definitionJson' => '{}'], str_repeat('a', 48)), 403);
		self::assertSame(['GET'], array_values(array_unique(array_column($s->trace, 'method'))));
	}
	private function oauthFixture(): array {
		[$endpoints, $vault, $broker, $s, $config, $users, $session, $oauth, $clients] = $this->fixture('n8n');
		$owner = 'cfec15c4-5036-4f30-957c-306f577c19cc';
		$encode = static fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
		$token = $encode(['alg' => 'HS256']) . '.' . $encode(['iss' => 'n8n', 'aud' => 'public-api', 'sub' => $owner]) . '.' . str_repeat('a', 43);
		$s->settings += ['integration_enabled' => '1', 'server_url' => 'https://faden.example.test', 'agent' => 'engine-test',
			'development' => '1', 'provider_reads_enabled' => '1', 'provider_writes_enabled' => '1',
			'provider_policy' => json_encode(['version' => 1, 'n8n' => ['enabled' => true, 'writesEnabled' => true, 'collections' => ['engine-test'], 'sourceClass' => 'standard']]),
			'scope_policy' => json_encode(['revision' => 'oauth-test', 'sources' => [['kind' => 'user-files']], 'tools' => ['nc_n8n_read', 'nc_n8n_execute'],
				'sourceClasses' => ['standard'], 'destinationAudiences' => ['unknown'], 'movements' => [['source' => 'standard', 'destination' => 'unknown']], 'downstreamPolicy' => 'unverified'])];
		$t = ['id' => 'own-oauth', 'connectionId' => 'engine-test', 'workflowId' => 'flow1', 'path' => 'own-oauth', 'nodeId' => 'node1', 'authentication' => 'oauth2'];
		$s->settings['engine_webhook_targets'] = json_encode([$t]);
		$s->settings['engine_oauth'] = json_encode(['version' => 1, 'clients' => [['targetId' => 'own-oauth', 'issuer' => 'https://editor.example.test', 'clientId' => 'own-client']]]);
		$vault->replace('engine-test', $vault->status('engine-test')['revision'], $token);
		$node = ['id' => 'node1', 'name' => 'Webhook', 'type' => 'n8n-nodes-base.webhook', 'typeVersion' => 2.2,
			'parameters' => ['httpMethod' => 'POST', 'path' => 'own-oauth', 'authentication' => 'n8nOAuth2', 'responseMode' => 'lastNode', 'requireExecuteAccess' => true, 'options' => ['oauthClient' => 'bearer']]];
		$version = 'cde5ac9c-0f51-4d0e-b1b5-9ee4d1ac7078';
		$s->flow = ['id' => 'flow1', 'name' => 'Owned OAuth workflow', 'active' => true, 'isArchived' => false, 'versionId' => $version, 'activeVersionId' => $version,
			'nodes' => [$node], 'connections' => new \stdClass(), 'activeVersion' => ['versionId' => $version, 'workflowId' => 'flow1', 'nodes' => [$node], 'connections' => new \stdClass()],
			'shared' => [['role' => 'workflow:owner', 'project' => ['type' => 'personal', 'creatorId' => $owner]]]];
		$s->beforeResponse = static function ($url) use ($s, $owner, $encode): void {
			$value = match (true) {
				str_contains($url, 'workflows?limit=1') => ['data' => []],
				str_contains($url, '/api/v1/workflows/flow1') => $s->flow,
				str_contains($url, 'oauth-authorization-server') => ['issuer' => 'https://editor.example.test', 'authorization_response_iss_parameter_supported' => true,
					'authorization_endpoint' => 'https://editor.example.test/mcp-oauth/authorize', 'token_endpoint' => 'https://editor.example.test/mcp-oauth/token', 'revocation_endpoint' => 'https://editor.example.test/mcp-oauth/revoke',
					'code_challenge_methods_supported' => ['S256'], 'grant_types_supported' => ['authorization_code', 'refresh_token'], 'token_endpoint_auth_methods_supported' => ['none'], 'response_types_supported' => ['code']],
				str_contains($url, 'oauth-protected-resource') => ['resource' => 'https://editor.example.test/webhook/own-oauth?method=POST', 'authorization_servers' => ['https://editor.example.test'], 'bearer_methods_supported' => ['header']],
				str_contains($url, '/mcp-oauth/token') => ['access_token' => $encode(['alg' => 'HS256', 'typ' => 'at+jwt']) . '.' . $encode(['iss' => 'https://editor.example.test', 'aud' => 'https://editor.example.test/webhook/own-oauth?method=POST',
					'sub' => $s->tokenSubject ?? $owner, 'client_id' => 'own-client', 'iat' => time(), 'exp' => time() + 3600, 'scope' => '', 'meta' => ['isOAuth' => true], 'jti' => bin2hex(random_bytes(8))]) . '.' . str_repeat('a', 43),
					'refresh_token' => 'synthetic-refresh-token-' . bin2hex(random_bytes(8)), 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => ''],
				str_contains($url, '/webhook/') => ['executionId' => '1', 'correlation' => 'synthetic'],
				default => ['data' => []],
			};
			if (str_contains($url, '/mcp-oauth/token') && ($s->failToken ?? false)) {
				throw new \RuntimeException('Synthetic lost response');
			}
			$s->body = json_encode($value);
		};
		$scope = new ScopePolicy($config, $this->createMock(IRootFolder::class), $users, $this->createMock(IManager::class));
		$policies = new ProviderPolicy($config, new Configuration($config), $scope, $users);
		$authority = new EngineAuthority($vault, $broker, $policies);
		$service = new N8nOAuthService($oauth, new N8nOAuthClient($endpoints, $users, $session, $clients), $vault, $authority, $session);
		return [$service, $vault, $s, $oauth, $broker, $endpoints, $t, $owner, $authority, $config, $policies];
	}
	private function oauthComplete(N8nOAuthService $service, EngineVault $vault): void {
		$p = $vault->resolve('engine-test')['oauthPending'];
		$service->complete('engine-test', $p['state'], 'https://editor.example.test', 'synthetic-code', null);
	}
	public function testOAuthConsentIsOneUseEncryptedAndBoundToTheCurrentSession(): void {
		[$service, $vault, $s] = $this->oauthFixture();
		$before = $vault->status('engine-test')['revision'];
		$url = $service->start('own-oauth', $before)['authorizationUrl'];
		$c = $vault->resolve('engine-test');
		self::assertNotSame($before, $c['revision']);
		self::assertStringNotContainsString($c['oauthPending']['verifier'], $url);
		self::assertSame('pending', $service->listing()[0]['state']);
		$this->refused(fn () => $service->complete('engine-test', str_repeat('0', 64), 'https://editor.example.test', 'code', null), 410);
		$this->refused(fn () => $service->complete('engine-test', $c['oauthPending']['state'], 'https://evil.example.test', 'code', null), 409);
		self::assertSame([], array_filter($s->trace, static fn ($r) => $r['method'] === 'POST'));
		$this->oauthComplete($service, $vault);
		self::assertSame('connected', $service->listing()[0]['state']);
		self::assertNull($vault->resolve('engine-test')['oauthPending']);
		$this->refused(fn () => $service->complete('engine-test', $c['oauthPending']['state'], 'https://editor.example.test', 'code', null), 410);
		self::assertCount(1, array_filter($s->trace, static fn ($r) => $r['method'] === 'POST'));
		self::assertStringNotContainsString($vault->resolve('engine-test')['oauth']['own-oauth']['refreshToken'], json_encode($service->listing()));
	}
	public function testOAuthLostExchangeAndRefreshAreConsumedAndNeverRetried(): void {
		foreach (['exchange', 'refresh'] as $phase) {
			[$service, $vault, $s] = $this->oauthFixture();
			$service->start('own-oauth', $vault->status('engine-test')['revision']);
			if ($phase === 'refresh') {
				$this->oauthComplete($service, $vault);
			}
			$s->failToken = true;
			$revision = $vault->status('engine-test')['revision'];
			$call = fn () => $phase === 'exchange' ? $this->oauthComplete($service, $vault) : $service->refresh('own-oauth', $revision);
			$this->refused($call, 503);
			self::assertSame('reconnect', $service->listing()[0]['state']);
			$count = count($s->trace);
			$this->refused($call, $phase === 'exchange' ? 410 : 409);
			self::assertSame(count(array_filter(array_slice($s->trace, $count), static fn ($r) => $r['method'] === 'POST')), 0);
			$s->settings['provider_writes_enabled'] = '0';
			$service->remove('engine-test', 'own-oauth', $vault->status('engine-test')['revision']);
			self::assertSame('disconnected', $service->listing()[0]['state']);
		}
	}
	public function testOAuthRefreshInvalidatesPriorApprovalsAndBrokerSendsBearerOnly(): void {
		[$service, $vault, $s, $oauth, $broker, $endpoints, $target, $owner] = $this->oauthFixture();
		$service->start('own-oauth', $vault->status('engine-test')['revision']);
		$this->oauthComplete($service, $vault);
		$old = $vault->resolve('engine-test');
		$service->refresh('own-oauth', $old['revision']);
		$c = $vault->resolve('engine-test');
		self::assertNotSame($old['revision'], $c['revision']);
		$t = $oauth->get('own-oauth')['target'];
		$r = EngineRequest::n8nWebhook('alice', $endpoints->get('engine-test'), $t, '{}', $owner);
		$this->refused(fn () => $broker->request($r, $old['revision'], static function (): void {
		}), 409);
		$broker->request($r, $c['revision'], static function (): void {
		});
		$last = end($s->trace);
		self::assertSame('Bearer ' . $c['oauth']['own-oauth']['accessToken'], $last['options']['headers']['Authorization']);
		self::assertArrayNotHasKey('X-N8N-API-KEY', $last['options']['headers']);
		self::assertArrayNotHasKey('Cookie', $last['options']['headers']);
		self::assertArrayNotHasKey('X-Fadenstack-Workflow-Key', $last['options']['headers']);
		$service->remove('engine-test', 'own-oauth', $c['revision']);
		$this->refused(fn () => $broker->request($r, $c['revision'], static function (): void {
		}), 409);
	}
	public function testOAuthWrongOwnerAndExpiredOrDeniedConsentNeverStoreAGrant(): void {
		[$service, $vault, $s] = $this->oauthFixture();
		$service->start('own-oauth', $vault->status('engine-test')['revision']);
		$p = $vault->resolve('engine-test')['oauthPending'];
		$this->refused(fn () => $service->complete('engine-test', $p['state'], 'https://editor.example.test', null, 'access_denied'), 403);
		self::assertNull($vault->resolve('engine-test')['oauthPending']);
		$service->start('own-oauth', $vault->status('engine-test')['revision']);
		$c = $vault->resolve('engine-test');
		$p = $c['oauthPending'];
		$p['expiresAt'] = time() - 1;
		$vault->changeOAuth('engine-test', $c['revision'], [], $p);
		$this->refused(fn () => $this->oauthComplete($service, $vault), 410);
		$service->start('own-oauth', $vault->status('engine-test')['revision']);
		$s->tokenSubject = 'ba1c56a0-d843-4386-a9e8-0d6cc9c0d5e8';
		$this->refused(fn () => $this->oauthComplete($service, $vault), 503);
		self::assertSame([], $vault->resolve('engine-test')['oauth']);
	}
	public function testOAuthControllerIsRoutableAndRejectsCsrfAndSessionBypasses(): void {
		[$service, $vault, $s] = $this->oauthFixture();
		foreach ([[false, '', 'native-binding', 403], [true, 'true', 'native-binding', 403], [true, '', 'wrong-binding', 401]] as [$csrf, $ocs, $binding, $status]) {
			$request = $this->createMock(IRequest::class);
			$request->method('passesCSRFCheck')->willReturn($csrf);
			$request->method('getHeader')->willReturnCallback(static fn ($name) => match ($name) {
				'OCS-APIRequest' => $ocs, 'X-Fadenstack-Session' => $binding, default => '',
			});
			$files = $this->createMock(FileService::class);
			$check = static function ($actual): void {
				if ($actual !== 'native-binding') {
					throw new Problem('Wrong native session.', 401);
				}
			};
			$files->method('check')->willReturnCallback($check);
			$files->method('checkSession')->willReturnCallback($check);
			$controller = new EngineOauthController('fadenstack_agent', $request, $service, $files, $this->createMock(ISession::class), $this->createMock(IURLGenerator::class));
			self::assertSame($status, $controller->start('own-oauth', $vault->status('engine-test')['revision'])->getStatus());
			self::assertSame($status, $controller->remove('engine-test', 'own-oauth', $vault->status('engine-test')['revision'])->getStatus());
		}
		self::assertSame([], $s->trace);
		self::assertNull($vault->resolve('engine-test')['oauthPending']);
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
		return [new EngineReads($vault, $broker, $policy, new EngineWebhookTargets($config)), $vault, $state, $policy, $broker, $config];
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
	private function workspace(string $provider = 'windmill'): array {
		[, $vault, $state, $policy, $broker, $config] = $this->reads($provider);
		$state->settings['engine_workspaces'] = json_encode(['version' => 1, 'workspaces' => [['connectionId' => 'engine-test', 'browserUrl' => 'https://editor.example.test/native']]]);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://cloud.example.test/');
		$urls->method('linkToRoute')->willReturn('/apps/fadenstack_agent/engines/workspace/engine-test');
		return [new EngineWorkspace($config, new Configuration($config), new EngineEndpoint($config), $vault, $broker, $policy, $urls), $state, $vault, $policy, $broker, $config];
	}
	public function testWorkspaceUsesOnlyApprovedBrowserDestinationAndNoTokenHandoff(): void {
		[$workspace, $state] = $this->workspace();
		$value = $workspace->resolve('engine-test');
		self::assertSame('https://editor.example.test/native?workspace=sandbox', $value['url']);
		self::assertSame('https://editor.example.test', $value['origin']);
		self::assertStringNotContainsString(self::TOKEN, json_encode($value));
		self::assertSame([['connectionId' => 'engine-test', 'href' => '/apps/fadenstack_agent/engines/workspace/engine-test']], $workspace->listing());
		self::assertSame(['GET', 'GET'], array_column($state->trace, 'method'));
		unset($state->settings['engine_workspaces']);
		self::assertSame([], $workspace->listing());
		$this->refused(fn () => $workspace->resolve('engine-test'), 403);
	}
	public function testWorkspaceRefusesUnknownConfigurationAndUnsafeBrowserUrls(): void {
		[$workspace, $state] = $this->workspace();
		foreach (['http://editor.example.test', 'javascript:alert(1)', 'https://user:secret@editor.test', 'https://editor.test/?token=secret', 'https://editor.test/#frag', 'https://editor.test/a/../b', 'https://editor.test/%2fadmin', 'http://[::1]:8080'] as $url) {
			$state->settings['engine_workspaces'] = json_encode(['version' => 1, 'workspaces' => [['connectionId' => 'engine-test', 'browserUrl' => $url]]]);
			$this->refused(fn () => $workspace->configured(), 503);
		}
		$state->settings['engine_workspaces'] = '{"version":1,"workspaces":[{"connectionId":"outside","browserUrl":"https://editor.test"}]}';
		$this->refused(fn () => $workspace->configured(), 503);
		self::assertSame([], $state->trace);
	}
	public function testWorkspaceLoopbackBrowserAddressRequiresDevelopmentMode(): void {
		[$workspace, $state] = $this->workspace();
		$state->settings['engine_workspaces'] = '{"version":1,"workspaces":[{"connectionId":"engine-test","browserUrl":"http://127.0.0.2:18422"}]}';
		self::assertSame('http://127.0.0.2:18422', $workspace->configured()['engine-test']['origin']);
		$state->settings['development'] = '0';
		$this->refused(fn () => $workspace->configured(), 503);
	}
	public function testSameSiteLocalhostBrowserDomainsAreDevelopmentOnly(): void {
		[$workspace, $state] = $this->workspace();
		$entry = ['connectionId' => 'engine-test', 'browserUrl' => 'http://wm.faden.localhost:18421/user/login'];
		$state->settings['engine_workspaces'] = json_encode(['version' => 1, 'workspaces' => [$entry]]);
		self::assertSame('http://wm.faden.localhost:18421', $workspace->configured()['engine-test']['origin']);
		$state->settings['development'] = '0';
		$this->refused(fn () => $workspace->configured(), 503);
		$state->settings['development'] = '1';
		$entry['browserUrl'] = 'http://wm.faden.localhost.example.test';
		$state->settings['engine_workspaces'] = json_encode(['version' => 1, 'workspaces' => [$entry]]);
		$this->refused(fn () => $workspace->configured(), 503);
	}
	public function testWorkspaceRequiresOwnCurrentCredentialAndScopeAndKeepsN8nBrowserAuthSeparate(): void {
		[$workspace, $state] = $this->workspace('n8n');
		self::assertSame('https://editor.example.test/native', $workspace->resolve('engine-test')['url']);
		$state->status = 401;
		$this->refused(fn () => $workspace->resolve('engine-test'), 401);
		self::assertSame([], $workspace->listing());
		$state->status = 200;
		$state->actor = 'bob';
		$this->refused(fn () => $workspace->resolve('engine-test'), 403);
		$state->actor = 'alice';
		$state->settings['provider_reads_enabled'] = '0';
		$this->refused(fn () => $workspace->resolve('engine-test'), 403);
	}
	public function testWorkspaceDestinationChangedDuringProbeIsRefused(): void {
		[$workspace, $state] = $this->workspace();
		$state->afterRequest = static function () use ($state): void {
			$state->settings['engine_workspaces'] = '{"version":1,"workspaces":[]}';
		};
		$this->refused(fn () => $workspace->resolve('engine-test'), 409);
	}
	public function testWorkspaceDoesNotTreatPortsAsCookieIsolation(): void {
		[$workspace, $state] = $this->workspace();
		foreach (['https://cloud.example.test:8443/', 'https://cloud.example.test/native'] as $url) {
			$state->settings['engine_workspaces'] = json_encode(['version' => 1, 'workspaces' => [['connectionId' => 'engine-test', 'browserUrl' => $url]]]);
			$this->refused(fn () => $workspace->resolve('engine-test'), 403);
		}
		self::assertSame([], $state->trace);
	}
	public function testDifferentEngineProvidersCannotShareBrowserCookieHost(): void {
		[$workspace, $state] = $this->workspace();
		$connections = json_decode($state->settings['engine_connections'], true);
		$connections['connections'][] = ['id' => 'other-engine', 'provider' => 'n8n', 'baseUrl' => 'https://n8n-api.example.test', 'version' => EngineEndpoint::VERSIONS['n8n']];
		$state->settings['engine_connections'] = json_encode($connections);
		$entries = [['connectionId' => 'engine-test', 'browserUrl' => 'https://editor.example.test'], ['connectionId' => 'other-engine', 'browserUrl' => 'https://editor.example.test:8443']];
		$state->settings['engine_workspaces'] = json_encode(['version' => 1, 'workspaces' => $entries]);
		$this->refused(fn () => $workspace->configured(), 503);
		$entries[1]['browserUrl'] = 'https://n8n-editor.example.test';
		$state->settings['engine_workspaces'] = json_encode(['version' => 1, 'workspaces' => $entries]);
		self::assertCount(2, $workspace->configured());
		self::assertSame([], $state->trace);
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
		$this->refused(fn () => $broker->request($request, $vault->status('engine-test')['revision']), 409);
		$state->actor = 'alice';
		$state->afterRequest = fn () => $this->connected($vault);
		$this->refused(fn () => $broker->request($request, $vault->status('engine-test')['revision']), 409);
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
		return new EngineConnectionsController('fadenstack_agent', $request, $files, $vault, $broker, new EngineWebhookTargets($this->createMock(IConfig::class)));
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
	public function testCredentialRotationBeforeHttpRefusesTheCapturedGenerationWithoutAnyRequest(): void {
		[$endpoints, $vault, $broker, $state] = $this->fixture();
		$old = $this->connected($vault);
		$this->connected($vault);
		$this->refused(fn () => $broker->request(EngineRequest::list('alice', $endpoints->get('engine-test')), $old['revision']), 409);
		$this->refused(fn () => $broker->probe('engine-test', self::TOKEN, $old['revision']), 409);
		self::assertSame([], $state->trace);
	}
	public function testNewDraftPostsRequireAnImmediateDurableGateAndNeverAddNativeSessionHeaders(): void {
		[$endpoints, $vault, $broker, $state] = $this->fixture();
		$connected = $this->connected($vault);
		$request = EngineRequest::saveOwnDraft('alice', $endpoints->get('engine-test'), 'u/alice/new', '{"value":{"modules":[]},"schema":{}}');
		$this->refused(fn () => $broker->request($request, $connected['revision']), 403);
		$this->refused(fn () => $broker->request($request, $connected['revision'], static function (): void {
			throw new Problem('Stopped.', 410);
		}), 410);
		self::assertSame([], $state->trace);
		self::assertFalse($broker->effectAttempted());
		$state->body = '{"status":"saved","path":"u/alice/new"}';
		$broker->request($request, $connected['revision'], static function () use ($state): void {
			self::assertTrue($state->closed);
		});
		self::assertTrue($broker->effectAttempted());
		self::assertCount(1, $state->trace);
		$sent = $state->trace[0];
		self::assertSame('POST', $sent['method']);
		$body = json_decode($sent['options']['body'], true);
		self::assertSame('1970-01-01T00:00:00Z', $body['last_sync']);
		self::assertFalse($body['force']);
		self::assertSame(['Accept', 'Cache-Control', 'Authorization', 'Content-Type'], array_keys($sent['options']['headers']));
	}
	public function testOnlyTheNativeOwnDraftReadCanReturnJsonNull(): void {
		[$endpoints, $vault, $broker, $state] = $this->fixture();
		$connected = $this->connected($vault);
		$state->body = 'null';
		self::assertNull($broker->request(EngineRequest::ownDraft('alice', $endpoints->get('engine-test'), 'u/alice/new'), $connected['revision']));
		$this->refused(fn () => $broker->request(EngineRequest::list('alice', $endpoints->get('engine-test')), $connected['revision']), 503);
		self::assertFalse($broker->effectAttempted());
	}
	public function testMissingDraftMovementGrantRefusesBeforeAnyEngineRequest(): void {
		[$reads, $vault, $state, $policy, $broker, $config] = $this->reads('windmill');
		$config->method('getSystemValueBool')->willReturn(true);
		$config->method('getSystemValueString')->willReturn('');
		$state->settings['provider_writes_enabled'] = '1';
		$providerPolicy = json_decode($state->settings['provider_policy'], true);
		$providerPolicy['windmill']['writesEnabled'] = true;
		$state->settings['provider_policy'] = json_encode($providerPolicy);
		$scope = json_decode($state->settings['scope_policy'], true);
		$scope['tools'][] = 'nc_windmill_draft';
		$state->settings['scope_policy'] = json_encode($scope);
		$plan = new EngineDraftPlan(new EngineAuthority($vault, $broker, $policy), $reads, new Configuration($config));
		$this->refused(fn () => $plan->prepare('nc_windmill_draft', ['connectionId' => 'engine-test', 'definitionJson' => '{"summary":"New","value":{"modules":[]}}'], str_repeat('a', 48)), 403);
		self::assertSame([], $state->trace);
		self::assertFalse(EngineDraftPlan::canMove($policy->engine('windmill')));
		$scope['movements'] = [['destination' => 'private', 'source' => 'standard']];
		$state->settings['scope_policy'] = json_encode($scope);
		self::assertTrue(EngineDraftPlan::canMove($policy->engine('windmill')), 'JSON member order does not change the movement grant.');
	}
	public function testRunUsesOneVersionBoundPostAndAcceptsOnlyTheNativePlainUuidAck(): void {
		[$endpoints, $vault, $broker, $state] = $this->fixture();
		$connected = $this->connected($vault);
		$job = EngineRunPlan::jobId(str_repeat('a', 48));
		$request = EngineRequest::windmillRun('alice', $endpoints->get('engine-test'), '7', $job, '{}');
		$this->refused(fn () => $broker->request($request, $connected['revision']), 403);
		self::assertSame([], $state->trace);
		$state->contentType = 'text/plain; charset=utf-8';
		$state->status = 201;
		$state->body = $job;
		self::assertSame(['jobId' => $job], $broker->request($request, $connected['revision'], static function (): void {
		}));
		self::assertStringEndsWith('/jobs/run/fv/7?job_id=' . $job, $state->trace[0]['url']);
		self::assertSame('{}', $state->trace[0]['options']['body']);
		foreach ([['text/plain', 200, $job], ['application/json', 201, '"' . $job . '"'], ['text/plain', 201, 'unverified']] as [$type, $status, $body]) {
			$state->contentType = $type;
			$state->status = $status;
			$state->body = $body;
			$this->refused(fn () => $broker->request($request, $connected['revision'], static function (): void {
			}), 503);
		}
		self::assertCount(4, $state->trace, 'No retry within any request.');
	}
	public function testRunPolicyRequiresExplicitTargetAndUnknownAudienceGrantBeforeHttp(): void {
		[$reads, $vault, $state, $policy, $broker, $config] = $this->reads('windmill');
		$config->method('getSystemValueBool')->willReturn(true);
		$config->method('getSystemValueString')->willReturn('');
		$state->settings['provider_writes_enabled'] = '1';
		$provider = json_decode($state->settings['provider_policy'], true);
		$provider['windmill']['writesEnabled'] = true;
		$state->settings['provider_policy'] = json_encode($provider);
		$scope = json_decode($state->settings['scope_policy'], true);
		$scope['tools'][] = EngineRunPlan::TOOL;
		$scope['destinationAudiences'] = ['private'];
		$scope['movements'] = [['source' => 'standard', 'destination' => 'private']];
		$state->settings['scope_policy'] = json_encode($scope);
		$plan = new EngineRunPlan(new EngineAuthority($vault, $broker, $policy), $reads, new Configuration($config), $config);
		$args = ['connectionId' => 'engine-test', 'reference' => 'u/alice/test', 'argsJson' => '{}'];
		$this->refused(fn () => $plan->prepare($args, str_repeat('a', 48)), 403);
		$scope['destinationAudiences'][] = 'unknown';
		$scope['movements'][] = ['destination' => 'unknown', 'source' => 'standard'];
		$state->settings['scope_policy'] = json_encode($scope);
		$this->refused(fn () => $plan->prepare($args, str_repeat('a', 48)), 403);
		self::assertSame([], $state->trace);
		$state->settings['engine_run_targets'] = json_encode([['connectionId' => 'engine-test', 'reference' => 'u/alice/test']]);
		$state->beforeResponse = static function (string $url) use ($state): void {
			$state->body = str_ends_with($url, '/users/whoami') ? '{"username":"alice"}'
				: '{"path":"u/alice/test","version_id":7,"archived":false,"is_draft":false,"value":{"modules":[]},"schema":{}}';
		};
		$p = $plan->prepare($args, str_repeat('a', 48));
		self::assertSame('7', $p['payload']['version']);
		self::assertSame('unknown', $p['destinationAudience']);
		self::assertStringNotContainsString(self::TOKEN, json_encode($p));
		$state->settings['engine_run_targets'] = json_encode([['connectionId' => 'engine-test', 'reference' => 'u/bob/test']]);
		$this->refused(fn () => $plan->prepare([...$args, 'reference' => 'u/bob/test'], str_repeat('a', 48)), 403);
		foreach (['[]', 'null', '{broken', str_repeat(' ', 32769)] as $json) {
			$this->refused(fn () => $plan->prepare([...$args, 'argsJson' => $json], str_repeat('a', 48)), 400);
		}
	}
	public function testPerTargetWebhookSecretsStayEncryptedAndCannotBecomeApiAuthentication(): void {
		[$endpoints, $vault, $broker, $state, $config] = $this->fixture('n8n');
		$target = ['id' => 'own-hook', 'connectionId' => 'engine-test', 'workflowId' => 'flow1', 'path' => 'own-hook', 'nodeId' => 'node1', 'credentialId' => 'cred1'];
		$state->settings['engine_webhook_targets'] = json_encode([$target]);
		$t = (new EngineWebhookTargets($config))->get('own-hook');
		$connected = $this->connected($vault);
		$hook = $vault->replaceWebhook($t, $connected['revision'], 'synthetic-webhook-secret');
		self::assertTrue($vault->webhookStatus($t)['secretStored']);
		self::assertStringNotContainsString('synthetic-webhook-secret', json_encode($vault->listing()));
		self::assertStringNotContainsString('synthetic-webhook-secret', json_encode($state->values));
		$this->refused(fn () => $vault->replaceWebhook($t, $connected['revision'], 'other-synthetic-secret'), 409);
		$state->body = '{"executionId":"1","correlation":"synthetic"}';
		$broker->request(EngineRequest::n8nWebhook('alice', $endpoints->get('engine-test'), $t, '{}'), $hook['revision'], static function (): void {
		});
		self::assertSame('synthetic-webhook-secret', $state->trace[0]['options']['headers']['X-Fadenstack-Workflow-Key']);
		self::assertArrayNotHasKey('X-N8N-API-KEY', $state->trace[0]['options']['headers']);
		self::assertArrayNotHasKey('Cookie', $state->trace[0]['options']['headers']);
		$state->settings['engine_webhook_targets'] = json_encode([[...$target, 'path' => 'changed']]);
		$this->refused(fn () => $broker->request(EngineRequest::n8nWebhook('alice', $endpoints->get('engine-test'), $t, '{}'), $hook['revision'], static function (): void {
		}), 409);
		self::assertCount(1, $state->trace);
		$this->connected($vault);
		self::assertFalse($vault->webhookStatus($t)['secretStored'], 'Replacing the API account removes prior webhook secrets.');
		$state->actor = 'bob';
		self::assertFalse($vault->webhookStatus($t)['secretStored']);
	}
	public function testExecutionReaderSupportsPriorRecordsAndRefusesUnknownFormatMarkers(): void {
		[, $vault, $state, $policy, $broker] = $this->reads('n8n');
		$claims = ['iss' => 'n8n', 'aud' => 'public-api', 'sub' => '11111111-1111-4111-8111-111111111111'];
		$token = 'synthetic.' . rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=') . '.signature';
		$vault->replace('engine-test', $vault->status('engine-test')['revision'], $token);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$users = $this->createMock(IUserSession::class);
		$users->method('getUser')->willReturn($user);
		$resolver = new EngineAccessResolver($users, new EngineAuthority($vault, $broker, $policy));
		$identity = new JournalIdentity('alice', JournalIdentity::nativeScope(), hash('sha256', 'native-session'));
		$parts = ['engine-test', $vault->resolve('engine-test')['endpoint']['stamp'], $claims['sub']];
		foreach ([$parts, [...$parts, 'n8n-execution-v1']] as $binding) {
			$resolver->assertAccess($identity, new JournalResource('n8n', 'action:' . str_repeat('a', 48), json_encode($binding)), 'inspect');
			self::assertTrue(true);
		}
		$before = count($state->trace);
		try {
			$resolver->assertAccess($identity, new JournalResource('n8n', 'action:' . str_repeat('a', 48), json_encode([...$parts, 'future-unreviewed-format'])), 'inspect');
			self::fail('Unknown readers must refuse rather than reinterpret execution as a draft.');
		} catch (JournalException) {
			self::assertCount($before, $state->trace);
		}
	}
	public function testN8nWorkspaceDisclosesVerifiedUserJwtPrincipalAndOpaqueKeyLimitation(): void {
		[$workspace, $state, $vault] = $this->workspace('n8n');
		$opaque = $workspace->resolve('engine-test');
		self::assertNull($opaque['apiPrincipal']);
		self::assertStringContainsString('Opaque n8n API key connected', $vault->status('engine-test')['keyAccess']);
		$encode = static fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
		$subA = '11111111-2222-4333-8444-555555555555';
		$subB = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
		$tokenA = $encode(['alg' => 'HS256']) . '.' . $encode(['iss' => 'n8n', 'aud' => 'public-api', 'sub' => $subA]) . '.' . str_repeat('a', 43);
		$vault->replace('engine-test', $vault->status('engine-test')['revision'], $tokenA);
		$resolvedA = $workspace->resolve('engine-test');
		self::assertSame($subA, $resolvedA['apiPrincipal']);
		self::assertNotSame($opaque['revision'], $resolvedA['revision']);
		self::assertSame('Non-Enterprise API keys can access all resources and capabilities of the connected n8n account.', $vault->status('engine-test')['keyAccess']);
		$tokenB = $encode(['alg' => 'HS256']) . '.' . $encode(['iss' => 'n8n', 'aud' => 'public-api', 'sub' => $subB]) . '.' . str_repeat('b', 43);
		$vault->replace('engine-test', $vault->status('engine-test')['revision'], $tokenB);
		$resolvedB = $workspace->resolve('engine-test');
		self::assertSame($subB, $resolvedB['apiPrincipal']);
		self::assertNotSame($resolvedA['revision'], $resolvedB['revision']);
		unset($state);
	}
	public function testEngineReadsIncludeCurrentWorkspaceRevisionAndTrackConnectionRotation(): void {
		[$workspace, $state, $vault, $policy, $broker, $config] = $this->workspace('windmill');
		$reads = new EngineReads($vault, $broker, $policy, new EngineWebhookTargets($config), $workspace);
		$state->beforeResponse = static function (string $url) use ($state): void {
			$state->body = str_ends_with($url, '/users/whoami') ? '{"username":"alice"}' : '[{"path":"u/alice/triage","summary":"Triage"}]';
		};
		$initialWs = $workspace->resolve('engine-test');
		$firstRead = $reads->read('windmill', ['connectionId' => 'engine-test']);
		self::assertSame($initialWs['revision'], $firstRead['workspaceRevision']);
		$vault->replace('engine-test', $vault->status('engine-test')['revision'], self::TOKEN . '-rotated');
		$rotatedRead = $reads->read('windmill', ['connectionId' => 'engine-test']);
		self::assertNotSame($initialWs['revision'], $rotatedRead['workspaceRevision']);
		self::assertSame($workspace->resolve('engine-test')['revision'], $rotatedRead['workspaceRevision']);
	}
	public function testOAuthListingMarksGrantReconnectRequiredWhenApiPrincipalChanges(): void {
		[$service, $vault] = $this->oauthFixture();
		$service->start('own-oauth', $vault->status('engine-test')['revision']);
		$this->oauthComplete($service, $vault);
		self::assertSame('connected', $service->listing()[0]['state']);
		$c = $vault->resolve('engine-test');
		$encode = static fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
		$otherToken = $encode(['alg' => 'HS256']) . '.' . $encode(['iss' => 'n8n', 'aud' => 'public-api', 'sub' => '99999999-8888-4777-8666-555555555555']) . '.' . str_repeat('c', 43);
		$vault->replace('engine-test', $c['revision'], $otherToken, $c['webhooks'], $c['oauth'], null, $c['expiresAt']);
		self::assertSame('reconnect', $service->listing()[0]['state']);
		$service->remove('engine-test', 'own-oauth', $vault->status('engine-test')['revision']);
		self::assertSame('disconnected', $service->listing()[0]['state']);
	}
}
