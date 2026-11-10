<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCP\IConfig;
use OCP\IURLGenerator;

/** Human native-editor destinations. Never a URL proxy, token handoff or agent tool. */
final class EngineWorkspace {

	public function __construct(
		private IConfig $config,
		private Configuration $configuration,
		private EngineEndpoint $endpoints,
		private EngineVault $vault,
		private EngineBroker $broker,
		private ProviderPolicy $policies,
		private IURLGenerator $urls,
	) {
	}

	public function configured(): array {
		$raw = $this->config->getAppValue('fadenstack_agent', 'engine_workspaces', '');
		if ($raw === '') {
			return [];
		}
		try {
			if (strlen($raw) > 16384) {
				throw new \UnexpectedValueException();
			}
			$value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
			if (!is_array($value) || array_diff(array_keys($value), ['version', 'workspaces']) || ($value['version'] ?? null) !== 1
				|| !is_array($value['workspaces'] ?? null) || !array_is_list($value['workspaces']) || count($value['workspaces']) > 8) {
				throw new \UnexpectedValueException();
			}
			$result = [];
			foreach ($value['workspaces'] as $entry) {
				if (!is_array($entry) || count($entry) !== 2 || array_diff(array_keys($entry), ['connectionId', 'browserUrl'])
					|| !is_string($entry['connectionId'] ?? null) || !is_string($entry['browserUrl'] ?? null) || strlen($entry['browserUrl']) > 2048) {
					throw new \UnexpectedValueException();
				}
				$id = EngineEndpoint::id($entry['connectionId']);
				if (isset($result[$id])) {
					throw new \UnexpectedValueException();
				}
				$endpoint = $this->endpoints->get($id);
				$p = parse_url($entry['browserUrl']);
				if (!$p || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || !isset($p['scheme'], $p['host'])) {
					throw new \UnexpectedValueException();
				}
				$development = $this->configuration->get()['development'];
				// Chromium rejects IPv6 literals in frame-src host sources. Keep
				// this UI restriction separate from supported native API endpoints.
				if (str_contains($p['host'], ':')) {
					throw new \UnexpectedValueException();
				}
				$authority = $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
				$loopback = filter_var($p['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($p['host'], '127.');
				$origin = $development && $p['scheme'] === 'http' && $loopback ? 'http://' . $authority
					: Configuration::origin($p['scheme'] . '://' . $authority, $development);
				foreach ($result as $existing) {
					if ($existing['provider'] !== $endpoint['provider'] && strcasecmp((string)parse_url($existing['origin'], PHP_URL_HOST), $p['host']) === 0) {
						throw new \UnexpectedValueException();
					}
				}
				$path = rtrim($p['path'] ?? '', '/');
				if (strlen($path) > 255 || ($path !== '' && !preg_match('~^(/[a-zA-Z0-9_-]+)+$~D', $path))) {
					throw new \UnexpectedValueException();
				}
				$url = $origin . ($path === '' ? '/' : $path);
				if ($endpoint['provider'] === 'windmill') {
					$url .= '?workspace=' . rawurlencode($endpoint['workspace']);
				}
				$result[$id] = ['connectionId' => $id, 'provider' => $endpoint['provider'], 'label' => $endpoint['label'], 'url' => $url, 'origin' => $origin];
			}
			return $result;
		} catch (\Throwable) {
			throw new Problem('The administrator native workflow workspace configuration is invalid.', 503);
		}
	}

	public function resolve(string $id): array {
		$this->configuration->requireEnabled();
		$entry = $this->configured()[EngineEndpoint::id($id)] ?? throw new Problem('This native workflow workspace is not enabled.', 403);
		// Browser cookies have no port boundary. Do not expose a Nextcloud cookie
		// to a sibling port or silently give an engine the parent's script origin.
		$nativeHost = parse_url($this->urls->getAbsoluteURL('/'), PHP_URL_HOST);
		if (!is_string($nativeHost) || strcasecmp($nativeHost, (string)parse_url($entry['origin'], PHP_URL_HOST)) === 0) {
			throw new Problem('Use a separate browser hostname for the native workflow editor. Shared-origin embedding needs separate deployment review.', 403);
		}
		$policy = $this->policies->engine($entry['provider']);
		if (!in_array($id, $policy['collections'], true) || !in_array(EngineReads::TOOLS[$entry['provider']], $policy['processing']['tools'], true)) {
			throw new Problem('This native workflow connection is outside the configured scope.', 403);
		}
		$own = $this->vault->resolve($id);
		if ($own['actor'] !== $policy['actor'] || $own['endpoint']['provider'] !== $entry['provider']) {
			throw new Problem('Connect your own native workflow account first.', 403);
		}
		$probe = $this->broker->probe($id, $own['apiToken'], $own['revision']);
		if (($this->configured()[$id] ?? null) !== $entry || $this->policies->engine($entry['provider'])['policyVersion'] !== $policy['policyVersion']) {
			throw new Problem('The native workspace destination or scope changed. Reload before continuing.', 409);
		}
		return [...$entry, 'revision' => StructuredProvider::digest([$entry, $own['revision'], $policy['policyVersion']]), 'apiPrincipal' => $probe['principal'] ?? null];
	}

	public function listing(): array {
		$result = [];
		foreach (array_keys($this->configured()) as $id) {
			try {
				$entry = $this->resolve($id);
				$result[] = ['connectionId' => $id, 'href' => $this->urls->linkToRoute('fadenstack_agent.engine_workspace.page', ['id' => $id])];
			} catch (Problem) { /* No connected account or enabled scope never grants a workspace. */
			}
		}
		return $result;
	}
}
