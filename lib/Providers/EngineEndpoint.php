<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\AppInfo\Application;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCP\IConfig;

/** Administrator-owned destinations only. Tool arguments cannot introduce a URL. */
final class EngineEndpoint {
	public const VERSIONS = ['windmill' => '1.841.0', 'n8n' => '2.43.8'];
	public function __construct(
		private IConfig $config,
	) {
	}
	public static function id(string $id): string {
		if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $id)) {
			throw new Problem('Choose a configured workflow connection.', 400);
		}
		return $id;
	}
	public function all(): array {
		$raw = $this->config->getAppValue(Application::APP_ID, 'engine_connections', '');
		if ($raw === '') {
			return [];
		}
		try {
			if (strlen($raw) > 32768) {
				throw new \UnexpectedValueException();
			}
			$data = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
			if (!is_array($data) || array_diff(array_keys($data), ['version', 'connections']) || ($data['version'] ?? null) !== 1
				|| !is_array($data['connections'] ?? null) || !array_is_list($data['connections']) || count($data['connections']) > 8) {
				throw new \UnexpectedValueException();
			}
			$result = [];
			foreach ($data['connections'] as $value) {
				if (!is_array($value) || array_diff(array_keys($value), ['id', 'provider', 'label', 'baseUrl', 'version', 'workspace', 'allowLocal'])
					|| !is_string($value['id'] ?? null) || !is_string($value['provider'] ?? null) || !isset(self::VERSIONS[$value['provider']])
					|| ($value['version'] ?? null) !== self::VERSIONS[$value['provider']] || !is_bool($value['allowLocal'] ?? false)) {
					throw new \UnexpectedValueException();
				}
				$id = self::id($value['id']);
				if (isset($result[$id]) || !is_string($value['baseUrl'] ?? null) || strlen($value['baseUrl']) > 2048) {
					throw new \UnexpectedValueException();
				}
				$parts = parse_url($value['baseUrl']);
				if (!$parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
					|| !isset($parts['scheme'], $parts['host'])) {
					throw new \UnexpectedValueException();
				}
				$development = $this->config->getAppValue(Application::APP_ID, 'development', '0') === '1';
				$origin = Configuration::origin($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''), $development);
				$prefix = rtrim($parts['path'] ?? '', '/');
				if (strlen($prefix) > 255 || ($prefix !== '' && !preg_match('~^(/[a-zA-Z0-9_-]+)+$~D', $prefix))) {
					throw new \UnexpectedValueException();
				}
				if ($value['provider'] === 'windmill') {
					$workspace = $value['workspace'] ?? null;
					if (!is_string($workspace) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $workspace)) {
						throw new \UnexpectedValueException();
					}
				} elseif (isset($value['workspace'])) {
					throw new \UnexpectedValueException();
				}
				$label = $value['label'] ?? $id;
				if (!is_string($label) || $label === '' || mb_strlen($label) > 80 || preg_match('/[\x00-\x1f\x7f]/', $label)) {
					throw new \UnexpectedValueException();
				}
				$entry = ['id' => $id, 'provider' => $value['provider'], 'label' => $label, 'baseUrl' => $origin . $prefix,
					'version' => $value['version'], 'workspace' => $value['workspace'] ?? '', 'allowLocal' => $value['allowLocal'] ?? false];
				$result[$id] = [...$entry, 'stamp' => StructuredProvider::digest($entry)];
			}
			return $result;
		} catch (\Throwable) {
			throw new Problem('The administrator workflow connection configuration is invalid.', 503);
		}
	}
	public function get(string $id): array {
		return $this->all()[self::id($id)] ?? throw new Problem('This workflow connection is unavailable.', 403);
	}
}
