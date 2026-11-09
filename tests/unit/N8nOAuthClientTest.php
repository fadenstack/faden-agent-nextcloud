<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\EngineEndpoint;
use OCA\FadenstackAgent\Providers\N8nOAuthBinding;
use OCA\FadenstackAgent\Providers\N8nOAuthClient;
use OCA\FadenstackAgent\Service\Problem;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class N8nOAuthClientTest extends TestCase {

	private const OWNER = 'cfec15c4-5036-4f30-957c-306f577c19cc';
	private const VERIFIER = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	private const REFRESH = 'synthetic-private-refresh-token';

	private function fixture(): array {
		$state = (object)['row' => ['id' => 'n8n-test', 'provider' => 'n8n', 'baseUrl' => 'https://api.example.test', 'version' => EngineEndpoint::VERSIONS['n8n']],
			'actor' => 'alice', 'session' => 'native-session', 'closed' => false, 'trace' => [], 'queue' => [], 'gate' => false, 'now' => 1000];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default = '') => $key === 'engine_connections' ? json_encode(['version' => 1, 'connections' => [$state->row]]) : $default);
		$endpoints = new EngineEndpoint($config);
		$binding = N8nOAuthBinding::configured($endpoints->get('n8n-test'), 'https://editor.example.test', 'synthetic-client', 'https://cloud.example.test/apps/fadenstack_agent/oauth/callback', 'synthetic-hook');
		$users = $this->createMock(IUserSession::class);
		$principals = [];
		foreach (['alice', 'bob'] as $name) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($name);
			$principals[$name] = $user;
		}
		$users->method('getUser')->willReturnCallback(static fn () => $principals[$state->actor] ?? null);
		$session = $this->createMock(ISession::class);
		$session->method('getId')->willReturnCallback(static fn () => $state->session);
		$session->method('close')->willReturnCallback(static function () use ($state): void {
			$state->closed = true;
		});
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(static fn () => $state->now);
		$clients = $this->createMock(IClientService::class);
		$client = $this->createMock(IClient::class);
		$clients->method('newClient')->willReturn($client);
		$client->method('request')->willReturnCallback(function ($method, $url, $options) use ($state): IResponse {
			self::assertTrue($state->closed);
			self::assertTrue($method === 'GET' || $state->gate, 'Persist a token effect attempt before its HTTP dispatch.');
			$state->trace[] = compact('method', 'url', 'options');
			$next = array_shift($state->queue) ?? [];
			if ($next['throw'] ?? false) {
				throw new \RuntimeException(self::REFRESH);
			}
			if (isset($state->duringRequest)) {
				($state->duringRequest)();
			}
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($next['status'] ?? 200);
			$response->method('getBody')->willReturn($next['body'] ?? json_encode($next['json'] ?? new \stdClass()));
			$response->method('getHeader')->willReturnCallback(static fn ($key) => match ($key) {
				'Content-Type' => $next['type'] ?? 'application/json', 'Content-Length' => $next['length'] ?? '', default => '',
			});
			return $response;
		});
		return [new N8nOAuthClient($endpoints, $users, $session, $clients, $clock), $binding, $state];
	}

	private static function metadata(N8nOAuthBinding $b): array {
		return ['issuer' => $b->issuer, 'authorization_endpoint' => $b->issuer . '/mcp-oauth/authorize', 'token_endpoint' => $b->issuer . '/mcp-oauth/token',
			'revocation_endpoint' => $b->issuer . '/mcp-oauth/revoke', 'authorization_response_iss_parameter_supported' => true,
			'code_challenge_methods_supported' => ['S256'], 'grant_types_supported' => ['authorization_code', 'refresh_token'],
			'token_endpoint_auth_methods_supported' => ['none'], 'response_types_supported' => ['code']];
	}

	private static function token(N8nOAuthBinding $b, array $claims = [], array $header = []): array {
		$encode = static fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
		$payload = ['iss' => $b->issuer, 'sub' => self::OWNER, 'client_id' => $b->clientId, 'aud' => $b->resource,
			'iat' => 1000, 'exp' => 4600, 'scope' => '', 'meta' => ['isOAuth' => true], ...$claims];
		return ['access_token' => $encode(['alg' => 'HS256', 'typ' => 'at+jwt', ...$header]) . '.' . $encode($payload) . '.' . str_repeat('a', 43),
			'refresh_token' => self::REFRESH, 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => ''];
	}

	private static function gate(object $state): \Closure {
		return static function () use ($state): void {
			$state->gate = true;
		};
	}

	private function refused(callable $call, int $status): void {
		try {
			$call();
			self::fail('Expected a closed native OAuth refusal.');
		} catch (Problem $e) {
			self::assertSame($status, $e->status);
			self::assertStringNotContainsString(self::REFRESH, $e->getMessage());
		}
	}

	public function testClosedDiscoveryAndS256AuthorizationUseOnlyConfiguredResource(): void {
		[$client, $b, $s] = $this->fixture();
		$s->queue = [['json' => self::metadata($b)], ['json' => ['resource' => $b->resource, 'authorization_servers' => [$b->issuer], 'bearer_methods_supported' => ['header']]]];
		$client->discover($b);
		self::assertSame(['https://api.example.test/.well-known/oauth-authorization-server', 'https://api.example.test/.well-known/oauth-protected-resource/webhook/synthetic-hook?method=POST'], array_column($s->trace, 'url'));
		self::assertFalse($client->attempted());
		$url = N8nOAuthClient::authorization($b, self::VERIFIER, str_repeat('s', 43));
		parse_str(parse_url($url, PHP_URL_QUERY), $args);
		self::assertSame($b->callback, $args['redirect_uri']);
		self::assertSame($b->resource, $args['resource']);
		self::assertSame('S256', $args['code_challenge_method']);
		self::assertSame(rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '='), $args['code_challenge']);
		self::assertStringNotContainsString(self::VERIFIER, $url);
		self::assertArrayNotHasKey('client_secret', $args);
		$this->refused(fn () => N8nOAuthClient::authorization($b, 'short', str_repeat('s', 43)), 400);
	}

	public function testMetadataCannotRedirectOrDowngradeTheProtocol(): void {
		foreach (['issuer' => 'https://elsewhere.test', 'token_endpoint' => 'https://elsewhere.test/token', 'authorization_endpoint' => 'https://editor.example.test/authorize',
			'authorization_response_iss_parameter_supported' => false, 'code_challenge_methods_supported' => ['plain'], 'grant_types_supported' => ['authorization_code'],
			'token_endpoint_auth_methods_supported' => ['client_secret_post'], 'response_types_supported' => ['token'], 'code_challenge_methods_supported-malformed' => [['S256']]] as $field => $value) {
			[$client, $b, $s] = $this->fixture();
			$m = self::metadata($b);
			$m[str_replace('-malformed', '', $field)] = $value;
			$s->queue = [['json' => $m]];
			$this->refused(fn () => $client->discover($b), 503);
			self::assertCount(1, $s->trace);
		}
	}

	public function testResourceDiscoveryCannotBroadenAudienceOrScopes(): void {
		foreach (['resource' => 'https://editor.example.test/webhook/another?method=POST', 'authorization_servers' => ['https://elsewhere.test'],
			'bearer_methods_supported' => ['query'], 'scopes_supported' => ['admin']] as $field => $value) {
			[$client, $b, $s] = $this->fixture();
			$r = ['resource' => $b->resource, 'authorization_servers' => [$b->issuer], 'bearer_methods_supported' => ['header']];
			$r[$field] = $value;
			$s->queue = [['json' => self::metadata($b)], ['json' => $r]];
			$this->refused(fn () => $client->discover($b), 503);
			self::assertFalse($client->attempted());
		}
	}

	public function testExchangeBindsExactOwnerAndUsesNoCookiesOrApiCredentials(): void {
		[$client, $b, $s] = $this->fixture();
		$s->queue = [['json' => self::token($b)]];
		$grant = $client->exchange($b, 'synthetic-code', self::VERIFIER, self::OWNER, self::gate($s));
		self::assertTrue($client->attempted());
		self::assertSame(self::OWNER, $grant['subject']);
		self::assertSame($b->resource, $grant['resource']);
		self::assertSame(4600, $grant['expiresAt']);
		$request = $s->trace[0];
		self::assertSame('https://api.example.test/mcp-oauth/token', $request['url']);
		self::assertSame('POST', $request['method']);
		$options = $request['options'];
		self::assertFalse($options['allow_redirects']);
		self::assertFalse($options['cookies']);
		self::assertTrue($options['verify']);
		self::assertArrayNotHasKey('Cookie', $options['headers']);
		self::assertArrayNotHasKey('Authorization', $options['headers']);
		self::assertArrayNotHasKey('X-N8N-API-KEY', $options['headers']);
		parse_str($options['body'], $form);
		self::assertSame(['client_id' => $b->clientId, 'resource' => $b->resource, 'grant_type' => 'authorization_code', 'redirect_uri' => $b->callback, 'code' => 'synthetic-code', 'code_verifier' => self::VERIFIER], $form);
	}

	public function testInvalidOrMixedUpClaimsNeverBecomeAStoredGrant(): void {
		foreach (['sub' => 'different-owner', 'iss' => 'https://elsewhere.test', 'aud' => 'https://editor.example.test/webhook/other?method=POST',
			'client_id' => 'another-client', 'scope' => 'admin', 'meta' => ['isOAuth' => false], 'iat' => 900, 'exp' => 4700] as $key => $value) {
			[$client, $b, $s] = $this->fixture();
			$s->queue = [['json' => self::token($b, [$key => $value])]];
			$this->refused(fn () => $client->refresh($b, self::REFRESH, self::OWNER, self::gate($s)), 503);
			self::assertTrue($client->attempted());
			self::assertCount(1, $s->trace);
		}
		foreach ([['alg' => 'none'], ['typ' => 'JWT'], ['crit' => ['unreviewed']]] as $header) {
			[$client, $b, $s] = $this->fixture();
			$s->queue = [['json' => self::token($b, [], $header)]];
			$this->refused(fn () => $client->refresh($b, self::REFRESH, self::OWNER, self::gate($s)), 503);
		}
	}

	public function testMalformedOrUnexpectedLifetimeAndTokenResponsesAreRefused(): void {
		foreach (['access_token' => 'not-a-jwt', 'refresh_token' => "bad\nsecret", 'token_type' => 'MAC', 'expires_in' => '3600',
			'expires_in-long' => 3601, 'expires_in-short' => 1, 'scope' => 'workflow:write'] as $key => $value) {
			[$client, $b, $s] = $this->fixture();
			$t = self::token($b);
			$t[explode('-', $key)[0]] = $value;
			$s->queue = [['json' => $t]];
			$this->refused(fn () => $client->refresh($b, self::REFRESH, self::OWNER, self::gate($s)), 503);
		}
	}

	public function testFailedGateOrChangedIdentityProducesNoHttpDispatch(): void {
		[$client, $b, $s] = $this->fixture();
		$this->refused(fn () => $client->refresh($b, self::REFRESH, self::OWNER, static fn () => throw new Problem('Stopped before native dispatch.', 409)), 409);
		self::assertFalse($client->attempted());
		self::assertSame([], $s->trace);
		$this->refused(fn () => $client->refresh($b, self::REFRESH, self::OWNER, static function () use ($s): void {
			$s->actor = 'bob';
		}), 409);
		self::assertFalse($client->attempted());
		self::assertSame([], $s->trace);
	}

	public function testNativeAccountSessionAndEndpointRecheckedAfterResponse(): void {
		foreach (['actor', 'session', 'endpoint'] as $change) {
			[$client, $b, $s] = $this->fixture();
			$s->queue = [['json' => self::token($b)]];
			$s->duringRequest = static function () use ($s, $change): void {
				match ($change) {
					'actor' => $s->actor = 'bob', 'session' => $s->session = 'another-session', 'endpoint' => $s->row['baseUrl'] = 'https://new-api.example.test',
				};
			};
			$this->refused(fn () => $client->refresh($b, self::REFRESH, self::OWNER, self::gate($s)), 409);
			self::assertTrue($client->attempted());
			self::assertCount(1, $s->trace);
		}
	}

	public function testTimeoutRedirectAndMalformedServerResponsesNeverRetryOrExposeTokens(): void {
		foreach ([['throw' => true], ['status' => 302], ['status' => 400], ['status' => 500], ['type' => 'text/html'], ['length' => '20000'],
			['body' => str_repeat('x', 16385)], ['body' => '{'], ['body' => '[]'], ['json' => ['error' => self::REFRESH]]] as $reply) {
			[$client, $b, $s] = $this->fixture();
			$s->queue = [$reply];
			$this->refused(fn () => $client->refresh($b, self::REFRESH, self::OWNER, self::gate($s)), isset($reply['length']) ? 413 : 503);
			self::assertCount(1, $s->trace);
			self::assertTrue($client->attempted());
		}
	}

	public function testBoundedStreamIsClosedAfterMalformedOrOversizedResponses(): void {
		foreach (['{', str_repeat('x', 16385)] as $raw) {
			[$client, $b, $s] = $this->fixture();
			$stream = fopen('php://temp', 'w+');
			fwrite($stream, $raw);
			rewind($stream);
			$s->queue = [['body' => $stream]];
			$this->refused(fn () => $client->refresh($b, self::REFRESH, self::OWNER, self::gate($s)), 503);
			self::assertFalse(is_resource($stream));
			self::assertCount(1, $s->trace);
		}
	}

	public function testRefreshAndRevokeAreExplicitGatedSingleRequests(): void {
		[$client, $b, $s] = $this->fixture();
		$s->queue = [['json' => self::token($b)], ['body' => '{}']];
		$client->refresh($b, self::REFRESH, self::OWNER, self::gate($s));
		parse_str($s->trace[0]['options']['body'], $refresh);
		self::assertSame('refresh_token', $refresh['grant_type']);
		self::assertSame(self::REFRESH, $refresh['refresh_token']);
		self::assertSame($b->resource, $refresh['resource']);
		$client->revoke($b, self::REFRESH, 'refresh_token', self::gate($s));
		self::assertCount(2, $s->trace);
		self::assertSame('https://api.example.test/mcp-oauth/revoke', $s->trace[1]['url']);
		$this->refused(fn () => $client->revoke($b, self::REFRESH, 'arbitrary', self::gate($s)), 400);
		self::assertFalse($client->attempted());
		self::assertCount(2, $s->trace);
	}

	public function testRevocationAcknowledgementAcceptsOnlyEmptyOrJsonObjectAndRechecksIdentity(): void {
		foreach ([['body' => '', 'type' => ''], ['body' => '{}']] as $reply) {
			[$client, $b, $s] = $this->fixture();
			$s->queue = [$reply];
			$client->revoke($b, self::REFRESH, 'refresh_token', self::gate($s));
			self::assertTrue($client->attempted());
			self::assertCount(1, $s->trace);
		}
		foreach ([['body' => '{}', 'type' => 'text/html'], ['body' => '[]'], ['body' => 'null'], ['body' => '{"error":"denied"}'], ['body' => '{"success":true}']] as $reply) {
			[$client, $b, $s] = $this->fixture();
			$s->queue = [$reply];
			$this->refused(fn () => $client->revoke($b, self::REFRESH, 'refresh_token', self::gate($s)), 503);
		}
		[$client, $b, $s] = $this->fixture();
		$s->queue = [['body' => '{}']];
		$s->duringRequest = static function () use ($s): void {
			$s->actor = 'bob';
		};
		$this->refused(fn () => $client->revoke($b, self::REFRESH, 'refresh_token', self::gate($s)), 409);
	}

	public function testBindingRequiresCanonicalTrustedUrlsAndSeparateCookieHosts(): void {
		[, $b] = $this->fixture();
		foreach (['http://editor.example.test', 'https://editor.example.test/', 'https://user:secret@editor.example.test', 'https://editor.example.test?token=secret',
			'https://editor.example.test/#fragment', 'https://editor.example.test/a/../b', 'https://editor.example.test/%2fadmin', 'https://cloud.example.test:8443'] as $issuer) {
			$this->refused(fn () => N8nOAuthBinding::configured($b->endpoint, $issuer, $b->clientId, $b->callback, 'hook'), 503);
		}
		$this->refused(fn () => N8nOAuthBinding::configured($b->endpoint, $b->issuer, 'client/other', $b->callback, 'hook'), 503);
		$this->refused(fn () => N8nOAuthBinding::configured($b->endpoint, $b->issuer, $b->clientId, $b->callback, '../hook'), 503);
		$local = [...$b->endpoint, 'allowLocal' => true];
		$value = N8nOAuthBinding::configured($local, 'http://127.0.0.2:18422', 'client', 'http://127.0.0.1/apps/fadenstack_agent/oauth/callback', 'hook', true);
		self::assertSame('http://127.0.0.2:18422/webhook/hook?method=POST', $value->resource);
		$this->refused(fn () => N8nOAuthBinding::configured($local, 'http://127.0.0.2:18422', 'client', 'http://127.0.0.1/callback', 'hook', false), 503);
		$this->refused(fn () => N8nOAuthBinding::configured($b->endpoint, 'http://127.0.0.2:18422', 'client', 'http://127.0.0.1/callback', 'hook', true), 503);
	}
}
