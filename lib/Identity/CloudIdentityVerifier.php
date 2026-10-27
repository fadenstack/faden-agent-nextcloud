<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Identity;

use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCP\Http\Client\IClientService;

/** Verifies a short Fadenstack agent token on the configured server; never stores it. */
final class CloudIdentityVerifier {
	public const MAX_PROFILE_BYTES = 262144;

	public function __construct(
		private Configuration $configuration,
		private IClientService $clients,
	) {
	}

	public function verify(string $accessToken): VerifiedCloudPrincipal {
		$this->configuration->requireEnabled();
		$config = $this->configuration->get();
		$origin = Configuration::origin($config['serverUrl'], $config['development']);
		if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $config['agent'])) {
			throw new Problem('The configured Fadenstack agent is invalid.', 503);
		}
		if (!preg_match('/^[A-Za-z0-9._~+\/=\-]{16,8192}$/D', $accessToken)) {
			throw new Problem('Sign in to Fadenstack again.', 401);
		}
		try {
			$response = $this->clients->newClient()->get($origin . '/v1/agents/' . rawurlencode($config['agent']) . '/profile', [
				'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json', 'Cache-Control' => 'no-cache'],
				// Preserve the native client's verified administrator CA bundle.
				// Private addresses are allowed only for this fixed admin-configured origin.
				'allow_redirects' => false, 'cookies' => false, 'http_errors' => false,
				'nextcloud' => ['allow_local_address' => true],
				'timeout' => 5, 'connect_timeout' => 3, 'read_timeout' => 5, 'stream' => true,
			]);
		} catch (\Throwable) {
			// Do not expose a client exception/request which may contain the token.
			throw new Problem('The configured Fadenstack identity service is unavailable.', 503);
		}
		$body = null;
		try {
			$body = $response->getBody();
			$status = $response->getStatusCode();
			if (in_array($status, [401, 403], true)) {
				throw new Problem('Sign in to Fadenstack again or check your agent access.', $status);
			}
			if ($status !== 200 || !preg_match('/^application\/json(?:\s*;|$)/iD', $response->getHeader('Content-Type'))) {
				throw new Problem('The configured Fadenstack identity service returned an invalid response.', 503);
			}
			$length = $response->getHeader('Content-Length');
			if ($length !== '' && (!ctype_digit($length) || (float)$length > self::MAX_PROFILE_BYTES)) {
				throw new Problem('The Fadenstack identity response is too large or invalid.', 503);
			}
			$raw = is_resource($body) ? stream_get_contents($body, self::MAX_PROFILE_BYTES + 1) : $body;
			if (!is_string($raw) || strlen($raw) > self::MAX_PROFILE_BYTES) {
				throw new Problem('The Fadenstack identity response is too large or invalid.', 503);
			}
			try {
				$profile = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
			} catch (\Throwable) {
				throw new Problem('The Fadenstack identity response is invalid.', 503);
			}
			if (!is_array($profile)) {
				throw new Problem('The Fadenstack identity response is invalid.', 503);
			}
			if ($this->configuration->get() !== $config) {
				throw new Problem('The Fadenstack configuration changed. Start a new request.', 409);
			}
			return VerifiedCloudPrincipal::fromAuthenticatedProfile($origin, $config['agent'], $profile);
		} catch (Problem $error) {
			throw $error;
		} catch (\Throwable) {
			throw new Problem('The configured Fadenstack identity service returned an invalid response.', 503);
		} finally {
			if (is_resource($body)) {
				fclose($body);
			}
		}
	}
}
