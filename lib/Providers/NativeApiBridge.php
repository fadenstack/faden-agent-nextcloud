<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\AppInfo\Application;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;

/** Request-local native session delegation to this administrator-configured instance only. */
final class NativeApiBridge {
	public const MAX_BYTES = 524288;

	public function __construct(
		private IConfig $config,
		private IClientService $clients,
		private IRequest $request,
		private ISession $session,
		private IUserSession $users,
	) {
	}

	public function base(): string {
		$canonical = rtrim($this->config->getSystemValueString('overwrite.cli.url', ''), '/');
		$raw = rtrim($this->config->getAppValue(Application::APP_ID, 'native_api_base', $canonical), '/');
		$parts = parse_url($raw);
		$path = $parts['path'] ?? '';
		if ($raw === '' || $raw !== $canonical || !$parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
			|| strlen($path) > 255 || preg_match('#[^/a-zA-Z0-9_.~-]#', $path) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
			throw new Problem('The native provider connection is not configured.', 503);
		}
		$development = $this->config->getAppValue(Application::APP_ID, 'development', '0') === '1';
		$origin = Configuration::origin(($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''), $development);
		return $origin . rtrim($path, '/');
	}

	/** Only code-owned NativeRequest factories can introduce a route. Never store or log these cookies. */
	public function send(NativeRequest $request): array {
		$actor = $this->users->getUser()?->getUID();
		if ($actor === null || $actor !== $request->actor) {
			throw new Problem('The native account changed.', 401);
		}
		$cookie = $this->request->getHeader('Cookie');
		$csrf = $this->request->getHeader('requesttoken');
		if ($cookie === '' || strlen($cookie) > 16384 || strlen($csrf) > 2048 || preg_match('/[\r\n\x00]/', $cookie . $csrf)) {
			throw new Problem('A current native browser session is required.', 401);
		}
		$base = $this->base();
		$binding = $this->session->getId();
		if ($binding === '') {
			throw new Problem('A current native browser session is required.', 401);
		}
		// A sibling HTTP request must never wait on our PHP session lock.
		$this->session->close();
		$options = ['headers' => [...$request->headers, 'Cookie' => $cookie, 'requesttoken' => $csrf,
			'OCS-APIRequest' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Cache-Control' => 'no-cache'],
			'allow_redirects' => false, 'cookies' => false, 'http_errors' => false, 'stream' => true,
			'timeout' => 15, 'connect_timeout' => 3, 'read_timeout' => 15,
			'nextcloud' => ['allow_local_address' => true]];
		if ($request->body !== '') {
			$options['body'] = $request->body;
		}
		try {
			$response = $this->clients->newClient()->request($request->method, $base . $request->path, $options);
		} catch (\Throwable) {
			throw new Problem('The native provider request could not be confirmed.', 503);
		}
		$body = $response->getBody();
		try {
			$status = $response->getStatusCode();
			if (in_array($status, [401, 403, 404, 409, 412, 423, 429], true)) {
				throw new Problem('The native provider refused this request.', $status);
			}
			if ($status < 200 || $status >= 300) {
				throw new Problem('The native provider returned an unsuccessful response.', 503);
			}
			$length = $response->getHeader('Content-Length');
			if ($length !== '' && (!ctype_digit($length) || (float)$length > self::MAX_BYTES)) {
				throw new Problem('Narrow the native provider request.', 413);
			}
			$raw = is_resource($body) ? stream_get_contents($body, self::MAX_BYTES + 1) : $body;
			if (!is_string($raw) || strlen($raw) > self::MAX_BYTES) {
				throw new Problem('Narrow the native provider request.', 413);
			}
			if ($this->users->getUser()?->getUID() !== $actor || $this->session->getId() !== $binding || $this->base() !== $base) {
				throw new Problem('The native account or provider connection changed.', 409);
			}
			return ['status' => $status, 'body' => $raw, 'etag' => $response->getHeader('ETag'), 'contentType' => $response->getHeader('Content-Type')];
		} finally {
			if (is_resource($body)) {
				fclose($body);
			}
		}
	}
}
