<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;

/** Server-owned OAuth contract. Never construct this from tool arguments or a manifest. */
final class N8nOAuthBinding {

	private function __construct(
		public readonly array $endpoint,
		public readonly string $issuer,
		public readonly string $clientId,
		public readonly string $callback,
		public readonly string $resource,
	) {
	}

	/** The caller supplies an administrator entry and a server-generated callback. */
	public static function configured(array $endpoint, string $issuer, string $clientId, string $callback, string $path, bool $development = false): self {
		try {
			if (($endpoint['provider'] ?? null) !== 'n8n' || ($endpoint['version'] ?? null) !== EngineEndpoint::VERSIONS['n8n']
				|| !preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $path) || !preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $clientId)) {
				throw new \UnexpectedValueException();
			}
			$issuer = self::url($issuer, $development, (bool)($endpoint['allowLocal'] ?? false));
			$callback = self::url($callback, $development, (bool)($endpoint['allowLocal'] ?? false));
			// Ports do not isolate cookies. The human authorization page must not
			// receive a Nextcloud session cookie on a sibling port of the same host.
			if (strcasecmp((string)parse_url($issuer, PHP_URL_HOST), (string)parse_url($callback, PHP_URL_HOST)) === 0) {
				throw new \UnexpectedValueException();
			}
			return new self($endpoint, $issuer, $clientId, $callback, $issuer . '/webhook/' . $path . '?method=POST');
		} catch (\Throwable) {
			throw new Problem('Use a reviewed n8n OAuth client, exact native callback and separate browser hostname.', 503);
		}
	}

	private static function url(string $url, bool $development, bool $allowLocal): string {
		$p = parse_url($url);
		if (strlen($url) > 2048 || !$p || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass'])
			|| isset($p['query']) || isset($p['fragment']) || str_contains($p['host'], ':')) {
			throw new \UnexpectedValueException();
		}
		$loopback = $p['host'] === 'localhost' || (filter_var($p['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($p['host'], '127.'));
		$authority = $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
		$origin = $development && $allowLocal && $loopback && $p['scheme'] === 'http' ? 'http://' . $authority
			: Configuration::origin($p['scheme'] . '://' . $authority, false);
		$path = rtrim($p['path'] ?? '', '/');
		if (strlen($path) > 255 || ($path !== '' && !preg_match('~^(/[a-zA-Z0-9_-]+)+$~D', $path)) || $url !== $origin . $path) {
			throw new \UnexpectedValueException();
		}
		return $url;
	}
}
