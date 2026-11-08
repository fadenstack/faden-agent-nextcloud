<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Identity;

use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCP\Http\Client\IClientService;

/** Fixed authenticated agent routes. Never a caller-controlled fetch proxy. */
final class CloudApi {
	public const MAX_RESPONSE_BYTES = 262144;

	public function __construct(
		private Configuration $configuration,
		private IClientService $clients,
	) {
	}

	public function request(#[\SensitiveParameter] string $accessToken, string $route, ?array $payload = null): array {
		$this->configuration->requireEnabled();
		$config = $this->configuration->get();
		$origin = Configuration::origin($config['serverUrl'], $config['development']);
		if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $config['agent'])
			|| !in_array($route, ['profile', 'tools/check', 'provenance/records', 'provenance/anchors'], true) || in_array($route, ['profile', 'provenance/anchors'], true) !== ($payload === null)) {
			throw new Problem('The configured Fadenstack request is invalid.', 503);
		}
		if (!preg_match('/^[A-Za-z0-9._~+\/=\-]{16,8192}$/D', $accessToken)) {
			throw new Problem('Sign in to Fadenstack again.', 401);
		}
		$options = [
			'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json', 'Cache-Control' => 'no-cache'],
			// Preserve Nextcloud's verified administrator CA bundle.
			'allow_redirects' => false, 'cookies' => false, 'http_errors' => false,
			'nextcloud' => ['allow_local_address' => true],
			'timeout' => 5, 'connect_timeout' => 3, 'read_timeout' => 5, 'stream' => true,
		];
		if ($payload !== null) {
			$options['json'] = $payload;
		}
		try {
			$client = $this->clients->newClient();
			$url = $origin . ($route === 'provenance/anchors' ? '/v1/provenance/anchors' : '/v1/agents/' . rawurlencode($config['agent']) . '/' . $route);
			$response = $payload === null ? $client->get($url, $options) : $client->post($url, $options);
		} catch (\Throwable) {
			throw new Problem('The configured Fadenstack service is unavailable.', 503);
		}
		$body = null;
		try {
			$body = $response->getBody();
			$status = $response->getStatusCode();
			if (in_array($status, [401, 403], true)) {
				throw new Problem('Sign in to Fadenstack again or check your agent access.', $status);
			}
			if ($status === 409) {
				throw new Problem('The Fadenstack command or policy changed. Review it again.', 409);
			}
			if ($status !== 200 || !preg_match('/^application\/json(?:\s*;|$)/iD', $response->getHeader('Content-Type'))) {
				throw new Problem('The configured Fadenstack service returned an invalid response.', 503);
			}
			$length = $response->getHeader('Content-Length');
			if ($length !== '' && (!ctype_digit($length) || (float)$length > self::MAX_RESPONSE_BYTES)) {
				throw new Problem('The Fadenstack response is too large or invalid.', 503);
			}
			$raw = is_resource($body) ? stream_get_contents($body, self::MAX_RESPONSE_BYTES + 1) : $body;
			if (!is_string($raw) || strlen($raw) > self::MAX_RESPONSE_BYTES) {
				throw new Problem('The Fadenstack response is too large or invalid.', 503);
			}
			$value = json_decode($raw, $route !== 'provenance/records', 24, JSON_THROW_ON_ERROR);
			if ($value instanceof \stdClass) {
				$value = (array)$value;
			}
			if (!is_array($value)) {
				throw new Problem('The Fadenstack response is invalid.', 503);
			}
			if ($this->configuration->get() !== $config) {
				throw new Problem('The Fadenstack configuration changed. Start a new request.', 409);
			}
			return ['value' => $value, 'origin' => $origin, 'agent' => $config['agent']];
		} catch (Problem $error) {
			throw $error;
		} catch (\Throwable) {
			throw new Problem('The configured Fadenstack service returned an invalid response.', 503);
		} finally {
			if (is_resource($body)) {
				fclose($body);
			}
		}
	}
}
