<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\ISession;
use OCP\IUserSession;

/** Closed, server-only protocol client for pinned n8n user-bound webhook OAuth.
 *
 * This is not a callback controller or a token-paste API. A caller must persist
 * and consume its user/session-bound attempt before exchange/refresh/revocation.
 * No native editor cookie, native password or engine API key is sent here.
 */
final class N8nOAuthClient {

	private const MAX_BYTES = 16384;
	private bool $attempted = false;

	public function __construct(
		private EngineEndpoint $endpoints,
		private IUserSession $users,
		private ISession $session,
		private IClientService $clients,
		private ?ITimeFactory $clock = null,
	) {
	}

	public function attempted(): bool {
		return $this->attempted;
	}

	/** Discovery never follows provider-supplied URLs. */
	public function discover(N8nOAuthBinding $binding): void {
		$this->attempted = false;
		$m = $this->request($binding, '/.well-known/oauth-authorization-server');
		if (($m['issuer'] ?? null) !== $binding->issuer || ($m['authorization_response_iss_parameter_supported'] ?? null) !== true) {
			throw new Problem('The native OAuth issuer could not be verified.', 503);
		}
		foreach (['authorization_endpoint' => 'authorize', 'token_endpoint' => 'token', 'revocation_endpoint' => 'revoke'] as $key => $path) {
			if (($m[$key] ?? null) !== $binding->issuer . '/mcp-oauth/' . $path) {
				throw new Problem('The native OAuth endpoint contract changed.', 503);
			}
		}
		foreach (['code_challenge_methods_supported' => ['S256'], 'grant_types_supported' => ['authorization_code', 'refresh_token'],
			'token_endpoint_auth_methods_supported' => ['none'], 'response_types_supported' => ['code']] as $key => $values) {
			if (!is_array($m[$key] ?? null) || !array_is_list($m[$key]) || count(array_filter($m[$key], 'is_string')) !== count($m[$key]) || array_diff($values, $m[$key])) {
				throw new Problem('This native OAuth capability is unavailable.', 503);
			}
		}
		$path = substr($binding->resource, strlen($binding->issuer));
		$r = $this->request($binding, '/.well-known/oauth-protected-resource' . $path);
		if (($r['resource'] ?? null) !== $binding->resource || ($r['authorization_servers'] ?? null) !== [$binding->issuer]
			|| ($r['bearer_methods_supported'] ?? null) !== ['header'] || ($r['scopes_supported'] ?? []) !== []) {
			throw new Problem('The exact native OAuth webhook resource could not be verified.', 503);
		}
	}

	/** Public URL only. Persist the verifier/state privately before returning this URL. */
	public static function authorization(N8nOAuthBinding $binding, string $verifier, string $state): string {
		self::nonce($verifier);
		self::nonce($state);
		return $binding->issuer . '/mcp-oauth/authorize?' . http_build_query([
			'client_id' => $binding->clientId, 'response_type' => 'code', 'redirect_uri' => $binding->callback,
			'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
			'code_challenge_method' => 'S256', 'state' => $state, 'resource' => $binding->resource,
		], '', '&', PHP_QUERY_RFC3986);
	}

	/** Server-internal tokens only, obtained from the exact configured native AS. */
	public function exchange(N8nOAuthBinding $binding, string $code, string $verifier, string $principal, callable $beforeAttempt): array {
		$this->attempted = false;
		self::nonce($verifier);
		if ($code === '' || strlen($code) > 2048 || preg_match('/[^\x21-\x7e]/', $code)) {
			throw new Problem('The native OAuth code is invalid.', 400);
		}
		return $this->grant($binding, $principal, ['grant_type' => 'authorization_code', 'redirect_uri' => $binding->callback,
			'code' => $code, 'code_verifier' => $verifier], $beforeAttempt);
	}

	/** No automatic refresh or retry. A lost refresh response requires reconnection. */
	public function refresh(N8nOAuthBinding $binding, string $refreshToken, string $principal, callable $beforeAttempt): array {
		$this->attempted = false;
		return $this->grant($binding, $principal, ['grant_type' => 'refresh_token', 'refresh_token' => EngineVault::token($refreshToken)], $beforeAttempt);
	}

	public function revoke(N8nOAuthBinding $binding, string $token, string $type, callable $beforeAttempt): void {
		$this->attempted = false;
		if (!in_array($type, ['access_token', 'refresh_token'], true)) {
			throw new Problem('Choose one native OAuth token type.', 400);
		}
		$this->request($binding, '/mcp-oauth/revoke', ['client_id' => $binding->clientId, 'token' => EngineVault::token($token), 'token_type_hint' => $type], $beforeAttempt, true);
	}

	private static function nonce(string $value): void {
		if (!preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $value)) {
			throw new Problem('Use a fresh bounded OAuth state and PKCE verifier.', 400);
		}
	}

	private function grant(N8nOAuthBinding $binding, string $principal, array $fields, callable $beforeAttempt): array {
		if (!preg_match('/^[a-f0-9-]{36}$/D', $principal)) {
			throw new Problem('Verify the native API-key owner before OAuth setup.', 403);
		}
		$t = $this->request($binding, '/mcp-oauth/token', ['client_id' => $binding->clientId, 'resource' => $binding->resource, ...$fields], $beforeAttempt);
		try {
			$access = EngineVault::token($t['access_token'] ?? null);
			$refresh = EngineVault::token($t['refresh_token'] ?? null);
			if (($t['token_type'] ?? null) !== 'Bearer' || !is_int($t['expires_in'] ?? null) || $t['expires_in'] < 30 || $t['expires_in'] > 3600
				|| ($t['scope'] ?? '') !== '') {
				throw new \UnexpectedValueException();
			}
			$parts = explode('.', $access);
			if (count($parts) !== 3 || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $parts[2])) {
				throw new \UnexpectedValueException();
			}
			$decode = static function (string $part): array {
				if (!preg_match('/^[A-Za-z0-9_-]+$/D', $part)) {
					throw new \UnexpectedValueException();
				}
				$value = json_decode(base64_decode(strtr($part, '-_', '+/') . str_repeat('=', (4 - strlen($part) % 4) % 4), true), true, 8, JSON_THROW_ON_ERROR);
				if (!is_array($value) || array_is_list($value)) {
					throw new \UnexpectedValueException();
				}
				return $value;
			};
			$header = $decode($parts[0]);
			$c = $decode($parts[1]);
			$now = $this->clock?->getTime() ?? time();
			// The pinned server signs these tokens with HS256, not its published
			// asymmetric JWKS key. Decode only after our own trusted token exchange;
			// never accept a pasted JWT as proof of identity or claim JWKS verification.
			if (($header['typ'] ?? null) !== 'at+jwt' || ($header['alg'] ?? null) !== 'HS256' || isset($header['crit'])
				|| ($c['iss'] ?? null) !== $binding->issuer || ($c['aud'] ?? null) !== $binding->resource || ($c['sub'] ?? null) !== $principal
				|| ($c['client_id'] ?? null) !== $binding->clientId || ($c['scope'] ?? null) !== '' || ($c['meta']['isOAuth'] ?? null) !== true
				|| !is_int($c['iat'] ?? null) || !is_int($c['exp'] ?? null) || abs($c['iat'] - $now) > 30
				|| $c['exp'] !== $c['iat'] + $t['expires_in'] || $c['exp'] < $now + 30) {
				throw new \UnexpectedValueException();
			}
			return ['accessToken' => $access, 'refreshToken' => $refresh, 'expiresAt' => $c['exp'], 'subject' => $principal,
				'issuer' => $binding->issuer, 'clientId' => $binding->clientId, 'resource' => $binding->resource];
		} catch (\Throwable) {
			throw new Problem('The native OAuth grant identity, resource or lifetime could not be verified. Reconnect without retrying the exchange.', 503);
		}
	}

	private function request(N8nOAuthBinding $b, string $path, ?array $form = null, ?callable $beforeAttempt = null, bool $empty = false): array {
		$this->attempted = false;
		$actor = $this->users->getUser()?->getUID();
		$session = $this->session->getId();
		if ($actor === null || $session === '' || $this->endpoints->get($b->endpoint['id']) !== $b->endpoint) {
			throw new Problem('The native account or OAuth endpoint changed.', 409);
		}
		$headers = ['Accept' => 'application/json', 'Cache-Control' => 'no-store'];
		if ($form !== null) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
		}
		$this->session->close();
		if ($form !== null) {
			($beforeAttempt ?? throw new Problem('Persist the native OAuth attempt before dispatch.', 403))();
			if ($this->users->getUser()?->getUID() !== $actor || $this->session->getId() !== $session || $this->endpoints->get($b->endpoint['id']) !== $b->endpoint) {
				throw new Problem('The native account or OAuth endpoint changed before dispatch.', 409);
			}
			$this->attempted = true;
		}
		try {
			$response = $this->clients->newClient()->request($form === null ? 'GET' : 'POST', $b->endpoint['baseUrl'] . $path,
				['headers' => $headers, 'allow_redirects' => false, 'cookies' => false, 'verify' => true, 'http_errors' => false, 'stream' => true,
					'timeout' => 15, 'connect_timeout' => 3, 'read_timeout' => 15, 'nextcloud' => ['allow_local_address' => $b->endpoint['allowLocal']],
					...($form === null ? [] : ['body' => http_build_query($form, '', '&', PHP_QUERY_RFC3986)])]);
		} catch (\Throwable) {
			throw new Problem('The native OAuth response is unconfirmed. Never automatically repeat a token exchange.', 503);
		}
		$body = $response->getBody();
		try {
			if ($response->getStatusCode() !== 200) {
				throw new Problem('The native OAuth server refused this request.', 503);
			}
			$length = $response->getHeader('Content-Length');
			if ($length !== '' && (!ctype_digit($length) || (float)$length > self::MAX_BYTES)) {
				throw new Problem('The native OAuth response exceeds the supported limit.', 413);
			}
			$raw = is_resource($body) ? stream_get_contents($body, self::MAX_BYTES + 1) : $body;
			if (!is_string($raw) || strlen($raw) > self::MAX_BYTES
				|| ((!$empty || $raw !== '') && !preg_match('~^application/json(?:\s*;|$)~i', $response->getHeader('Content-Type')))) {
				throw new Problem('The native OAuth response is outside the supported contract.', 503);
			}
			if ($this->users->getUser()?->getUID() !== $actor || $this->session->getId() !== $session || $this->endpoints->get($b->endpoint['id']) !== $b->endpoint) {
				throw new Problem('The native account or OAuth endpoint changed during the request.', 409);
			}
			if ($empty && ($raw === '' || trim($raw) === '{}')) {
				return [];
			}
			if ($empty) {
				throw new Problem('The native OAuth revocation acknowledgement is invalid.', 503);
			}
			try {
				$value = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
			} catch (\Throwable) {
				throw new Problem('Malformed native OAuth JSON.', 503);
			}
			if (!is_array($value) || array_is_list($value) || array_key_exists('error', $value)) {
				throw new Problem('The native OAuth server returned an invalid response.', 503);
			}
			return $value;
		} finally {
			if (is_resource($body)) {
				fclose($body);
			}
		}
	}
}
