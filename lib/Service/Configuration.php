<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Service;

use OCA\FadenstackAgent\AppInfo\Application;
use OCP\IConfig;

class Configuration {
	public function __construct(
		private IConfig $config,
	) {
	}

	public static function origin(string $url, bool $development): string {
		$p = parse_url($url);
		if (!$p || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass'])
			|| isset($p['query']) || isset($p['fragment']) || !in_array($p['path'] ?? '', ['', '/'], true)) {
			throw new Problem('Use a server origin without credentials, path, query or fragment.');
		}
		$host = strtolower($p['host']);
		$local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
		if ($p['scheme'] !== 'https' && !($p['scheme'] === 'http' && $local && $development)) {
			throw new Problem('HTTPS is required; localhost HTTP needs the development option.');
		}
		if (!preg_match('/^(\[[0-9a-f:]+\]|[a-z0-9.-]+)$/D', $host)) {
			throw new Problem('Invalid server hostname.');
		}
		return $p['scheme'] . '://' . $host . (isset($p['port']) ? ':' . $p['port'] : '');
	}

	public function get(): array {
		return [
			'enabled' => $this->config->getAppValue(Application::APP_ID, 'integration_enabled', '0') === '1',
			'serverUrl' => $this->config->getAppValue(Application::APP_ID, 'server_url', ''),
			'agent' => $this->config->getAppValue(Application::APP_ID, 'agent', ''),
			'development' => $this->config->getAppValue(Application::APP_ID, 'development', '0') === '1',
		];
	}

	/** Opt-in until compatible SDK/gateway and deployment acceptance are complete. */
	public function historyEnabled(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'history_enabled', '0') === '1';
	}

	/** Distinct server-controlled opt-in for verified read-only Files tools (nc_files_organize_read, nc_files_shares_read). Default false. */
	public function filesReadsEnabled(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'files_reads_enabled', '0') === '1';
	}

	/** Distinct server-controlled opt-in for experimental single-folder creation (nc_files_create_folder). Default false, development-only. */
	public function folderCreateEnabled(): bool {
		return $this->get()['development']
			&& $this->config->getAppValue(Application::APP_ID, 'folder_create_enabled', '0') === '1';
	}

	/** Release gate: path-based DAV does not bind the approved parent at dispatch. */
	public function folderDispatchSupported(): bool {
		return false;
	}

	public function save(string $serverUrl, string $agent, bool $enabled, bool $development): array {
		$origin = self::origin(trim($serverUrl), $development);
		if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $agent)) {
			throw new Problem('An agent identifier of letters, digits, hyphens and underscores is required.');
		}
		foreach (['server_url' => $origin, 'agent' => $agent, 'integration_enabled' => $enabled ? '1' : '0',
			'development' => $development ? '1' : '0'] as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}
		return $this->get();
	}

	public function requireEnabled(): void {
		$c = $this->get();
		if (!$c['enabled'] || !$c['agent'] || !$c['serverUrl']) {
			throw new Problem('The administrator has not enabled and configured Fadenstack Agent.', 403);
		}
		self::origin($c['serverUrl'], $c['development']);
	}
}
