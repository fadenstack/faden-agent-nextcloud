<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;
use OCP\Http\Client\IClientService;
use OCP\ISession;
use OCP\IUserSession;

/** Engine credentials stay server-side. No native session crosses this boundary. */
final class EngineBroker {
	public const MAX_BYTES = 524288;
	private bool $effectAttempted = false;
	public function effectAttempted(): bool {
		return $this->effectAttempted;
	}
	public function __construct(
		private EngineEndpoint $endpoints,
		private EngineVault $vault,
		private IUserSession $users,
		private ISession $session,
		private IClientService $clients,
	) {
	}
	public function probe(string $id, string $token, ?string $revision = null): array {
		$actor = $this->users->getUser()?->getUID() ?? throw new Problem('Sign in to Nextcloud.', 401);
		$endpoint = $this->endpoints->get($id);
		$token = EngineVault::token($token);
		if ($revision !== null) {
			$connection = $this->vault->resolve($id);
			if ($connection['revision'] !== $revision || !hash_equals($connection['apiToken'], $token)) {
				throw new Problem('The workflow credential changed before dispatch.', 409);
			}
		}
		$value = $this->send(EngineRequest::probe($actor, $endpoint), $endpoint, $token, $revision);
		if (array_key_exists('error', $value) || array_key_exists('errorCode', $value)) {
			throw new Problem('The workflow engine did not verify this credential.', 503);
		}
		if ($endpoint['provider'] === 'windmill') {
			if (!is_string($value['username'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value['username'])) {
				throw new Problem('The Windmill account could not be verified.', 503);
			}
			return ['authenticated' => true, 'principal' => $value['username']];
		}
		if (array_diff(array_keys($value), ['data', 'nextCursor']) || !isset($value['data']) || !is_array($value['data'])
			|| !array_is_list($value['data']) || count($value['data']) > 1
			|| (isset($value['nextCursor']) && (!is_string($value['nextCursor']) || strlen($value['nextCursor']) > 4096))) {
			throw new Problem('The n8n API connection could not be verified.', 503);
		}
		// A successful list is not proof of a particular user or workflow owner.
		return ['authenticated' => true, 'principal' => null];
	}
	/** Internal native response only; a typed provider must authorize and project it. */
	public function request(EngineRequest $request, string $expectedRevision, ?callable $beforeEffect = null): ?array {
		if ($request->method !== 'GET') {
			$this->effectAttempted = false;
			if ($beforeEffect === null) {
				throw new Problem('A durable native dispatch gate is required.', 403);
			}
		}
		$connection = $this->vault->resolve($request->connection);
		if ($connection['revision'] !== $expectedRevision) {
			throw new Problem('The workflow credential changed before dispatch.', 409);
		}
		return $this->send($request, $connection['endpoint'], $connection['apiToken'], $connection['revision'], $beforeEffect);
	}
	private function send(EngineRequest $request, array $endpoint, string $token, ?string $revision, ?callable $beforeEffect = null): ?array {
		$actor = $this->users->getUser()?->getUID();
		$session = $this->session->getId();
		if ($actor === null || $actor !== $request->actor || $session === '' || $request->provider !== $endpoint['provider']
			|| $request->stamp !== $endpoint['stamp'] || $request->connection !== $endpoint['id']) {
			throw new Problem('The native account or workflow connection changed.', 409);
		}
		$headers = ['Accept' => $request->jobAck ? 'text/plain' : 'application/json', 'Cache-Control' => 'no-cache'];
		if ($endpoint['provider'] === 'windmill') {
			$headers['Authorization'] = 'Bearer ' . $token;
		} else {
			$headers['X-N8N-API-KEY'] = $token;
		}
		if ($request->body !== null) {
			$headers['Content-Type'] = 'application/json';
		}
		$this->session->close();
		$client = $this->clients->newClient();
		if ($request->method !== 'GET') {
			$beforeEffect();
			$this->effectAttempted = true;
		}
		try {
			$response = $client->request($request->method, $endpoint['baseUrl'] . $request->path,
				['headers' => $headers, 'allow_redirects' => false, 'cookies' => false, 'verify' => true, 'http_errors' => false,
					'stream' => true, 'timeout' => 15, 'connect_timeout' => 3, 'read_timeout' => 15,
					...($request->body === null ? [] : ['body' => $request->body]),
					'nextcloud' => ['allow_local_address' => $endpoint['allowLocal']]]);
		} catch (\Throwable) {
			throw new Problem('The workflow engine request could not be confirmed.', 503);
		}
		$body = $response->getBody();
		try {
			$status = $response->getStatusCode();
			if (in_array($status, [401, 403, 404, 409, 412, 423, 429], true)) {
				throw new Problem('The workflow engine refused this request.', $status);
			}
			if ($status < 200 || $status >= 300) {
				throw new Problem('The workflow engine returned an unsuccessful response.', 503);
			}
			$length = $response->getHeader('Content-Length');
			if ($length !== '' && (!ctype_digit($length) || (float)$length > self::MAX_BYTES)) {
				throw new Problem('Narrow the workflow engine request.', 413);
			}
			$contentType = $request->jobAck ? '~^text/plain(?:\s*;|$)~i' : '~^application/(?:[a-z0-9.+-]+\+)?json(?:\s*;|$)~i';
			if (!preg_match($contentType, $response->getHeader('Content-Type'))) {
				throw new Problem('The workflow engine did not return JSON.', 503);
			}
			$raw = is_resource($body) ? stream_get_contents($body, self::MAX_BYTES + 1) : $body;
			if (!is_string($raw) || strlen($raw) > self::MAX_BYTES) {
				throw new Problem('Narrow the workflow engine request.', 413);
			}
			try {
				if ($request->jobAck) {
					if ($status !== 201 || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $raw)) {
						throw new \UnexpectedValueException();
					}
					$value = ['jobId' => $raw];
				} else {
					$value = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
				}
				if ($value instanceof \stdClass) {
					$value = (array)$value;
				}
			} catch (\Throwable) {
				throw new Problem('Malformed workflow engine JSON.', 503);
			}
			if (!is_array($value) && !($value === null && $request->nullable)) {
				throw new Problem('Malformed workflow engine response.', 503);
			}
			if ($value !== null && (array_key_exists('error', $value) || array_key_exists('errorCode', $value))) {
				throw new Problem('The workflow engine refused to return verified data.', 503);
			}
			if ($this->users->getUser()?->getUID() !== $actor || $this->session->getId() !== $session
				|| $this->endpoints->get($endpoint['id'])['stamp'] !== $endpoint['stamp']
				|| ($revision !== null && $this->vault->resolve($endpoint['id'])['revision'] !== $revision)) {
				throw new Problem('The native account or workflow connection changed.', 409);
			}
			return $value;
		} finally {
			if (is_resource($body)) {
				fclose($body);
			}
		}
	}
}
