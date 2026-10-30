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

	public function calendar(): array {
		$this->configuration->requireEnabled();
		if (!$this->configuration->providerReadsEnabled()) {
			throw new Problem('The native provider read slice is unavailable.', 403);
		}
		$uid = $this->users->getUser()?->getUID();
		if ($uid === null) {
			throw new Problem('Sign in to Nextcloud.', 401);
		}
		$raw = $this->config->getAppValue(Application::APP_ID, 'provider_policy', '');
		if ($raw === '') {
			throw new Problem('Calendar tools have not been enabled by the administrator.', 403);
		}
		try {
			if (strlen($raw) > 32768) {
				throw new \InvalidArgumentException();
			}
			$value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
			if (!is_array($value) || ($value['version'] ?? null) !== 1 || array_diff(array_keys($value), ['version', 'calendar'])) {
				throw new \InvalidArgumentException();
			}
			$calendar = $value['calendar'] ?? null;
			if (!is_array($calendar) || !is_bool($calendar['enabled'] ?? null) || array_diff(array_keys($calendar), ['enabled', 'collections', 'sourceClass'])) {
				throw new \InvalidArgumentException();
			}
			$collections = $calendar['collections'] ?? null;
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
			$sourceClass = $calendar['sourceClass'] ?? 'unknown';
			if (!in_array($sourceClass, ['standard', 'protected', 'unknown'], true)) {
				throw new \InvalidArgumentException();
			}
		} catch (\Throwable) {
			throw new Problem('The administrator provider policy is invalid.', 503);
		}
		if (!$calendar['enabled']) {
			throw new Problem('Calendar tools have been disabled by the administrator.', 403);
		}
		$base = $this->filesPolicy->getPolicy($uid, $this->configuration->get()['serverUrl']);
		$policyVersion = hash('sha256', json_encode([$uid, $base['version'], $value], JSON_THROW_ON_ERROR));
		return ['actor' => $uid, 'collections' => $collections, 'sourceClass' => $sourceClass,
			'policyVersion' => $policyVersion, 'processing' => $base];
	}

	public function assertDisclosure(array $policy, ?string $sourceClass = null): void {
		$sourceClass ??= $policy['sourceClass'];
		if ($sourceClass === 'unknown' || !in_array($sourceClass, $policy['processing']['sourceClasses'], true)
			|| ($sourceClass === 'protected' && $policy['processing']['downstreamPolicy'] !== 'configured')) {
			throw new Problem('This provider source cannot be processed under the configured policy.', 403);
		}
	}
}
