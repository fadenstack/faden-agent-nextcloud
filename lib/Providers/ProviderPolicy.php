<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\AppInfo\Application;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\IConfig;
use OCP\IUserSession;

/** Separate provider scopes preserve the existing Files policy and its legacy defaults. */
final class ProviderPolicy {
	public function __construct(
		private IConfig $config,
		private Configuration $configuration,
		private ScopePolicy $filesPolicy,
		private IUserSession $users,
	) {
	}

	private function parsePolicy(string $section, bool $audit = false): array {
		if (!$audit) {
			$this->configuration->requireEnabled();
		}
		if (!$audit && !$this->configuration->providerReadsEnabled()) {
			throw new Problem('The native provider read slice is unavailable.', 403);
		}
		$uid = $this->users->getUser()?->getUID();
		if ($uid === null) {
			throw new Problem('Sign in to Nextcloud.', 401);
		}
		$raw = $this->config->getAppValue(Application::APP_ID, 'provider_policy', '');
		if ($raw === '') {
			throw new Problem(ucfirst($section) . ' tools have not been enabled by the administrator.', 403);
		}
		try {
			if (strlen($raw) > 32768) {
				throw new \InvalidArgumentException();
			}
			$value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
			if (!is_array($value) || ($value['version'] ?? null) !== 1 || array_diff(array_keys($value), ['version', 'calendar', 'tasks', 'contacts', 'tables', 'deck', 'mail', 'talk'])) {
				throw new \InvalidArgumentException();
			}
			$config = $value[$section] ?? null;
			if (!is_array($config) || !is_bool($config['enabled'] ?? null) || array_diff(array_keys($config), ['enabled', 'collections', 'sourceClass', 'writesEnabled'])) {
				throw new \InvalidArgumentException();
			}
			$collections = $config['collections'] ?? null;
			if (!is_array($collections) || !array_is_list($collections) || !$collections || count($collections) > 8
				|| count(array_unique($collections, SORT_REGULAR)) !== count($collections)) {
				throw new \InvalidArgumentException();
			}
			foreach ($collections as $collection) {
				if (!is_string($collection)) {
					throw new \InvalidArgumentException();
				}
				NativeRequest::segment($collection);
			}
			$sourceClass = $config['sourceClass'] ?? 'unknown';
			if (!in_array($sourceClass, ['standard', 'protected', 'unknown'], true)) {
				throw new \InvalidArgumentException();
			}
			if (isset($config['writesEnabled']) && !is_bool($config['writesEnabled'])) {
				throw new \InvalidArgumentException();
			}
			$writesAllowed = ($config['writesEnabled'] ?? false) && $this->configuration->providerWritesEnabled();
		} catch (\Throwable) {
			throw new Problem('The administrator provider policy is invalid.', 503);
		}
		if (!$audit && !$config['enabled']) {
			throw new Problem(ucfirst($section) . ' tools have been disabled by the administrator.', 403);
		}
		$base = $this->filesPolicy->getPolicy($uid, $this->configuration->get()['serverUrl']);
		$policyVersion = hash('sha256', json_encode([$uid, $base['version'], $value, $writesAllowed], JSON_THROW_ON_ERROR));
		return ['actor' => $uid, 'collections' => $collections, 'sourceClass' => $sourceClass,
			'policyVersion' => $policyVersion, 'processing' => $base, 'writesEnabled' => $writesAllowed];
	}

	public function calendar(): array {
		return $this->parsePolicy('calendar');
	}

	public function tasks(): array {
		return $this->parsePolicy('tasks');
	}

	public function contacts(): array {
		return $this->parsePolicy('contacts');
	}

	public function structured(string $provider, bool $audit = false): array {
		if (!in_array($provider, ['tables', 'deck'], true)) throw new Problem('Unsupported structured provider.', 400);
		$policy = $this->parsePolicy($provider, $audit);
		foreach ($policy['collections'] as $id) {
			if (!preg_match('/^[1-9][0-9]{0,14}$/D', $id)) throw new Problem('Use numeric native collection identifiers.', 503);
		}
		return $policy;
	}

	public function communication(string $provider, bool $audit = false): array {
		if (!in_array($provider, ['mail', 'talk'], true)) throw new Problem('Unsupported communications provider.', 400);
		$policy = $this->parsePolicy($provider, $audit);
		foreach ($policy['collections'] as $id) {
			if (!preg_match($provider === 'mail' ? '/^[1-9][0-9]{0,14}$/D' : '/^[a-z0-9]{4,30}$/D', $id)) throw new Problem('Use canonical native account or room identifiers.', 503);
		}
		return $policy;
	}

	/** Disabling dispatch retains authorized native audit/inspection; revoked scope still denies. */
	public function audit(string $provider): array {
		if (!in_array($provider, ['calendar', 'tasks', 'contacts', 'tables', 'deck', 'mail', 'talk'], true)) {
			throw new Problem('Unsupported provider audit.', 400);
		}
		return $this->parsePolicy($provider, true);
	}

	public function assertDisclosure(array $policy, ?string $sourceClass = null): void {
		$sourceClass ??= $policy['sourceClass'];
		if ($sourceClass === 'unknown' || !in_array($sourceClass, $policy['processing']['sourceClasses'], true)
			|| ($sourceClass === 'protected' && $policy['processing']['downstreamPolicy'] !== 'configured')) {
			throw new Problem('This provider source cannot be processed under the configured policy.', 403);
		}
	}
}
