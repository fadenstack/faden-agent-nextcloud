<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;
use OCP\IConfig;

/** Administrator-owned same-engine published webhook routes, never model URLs. */
final class EngineWebhookTargets {
	public function __construct(
		private IConfig $config,
	) {
	}
	public function all(): array {
		try {
			$raw = $this->config->getAppValue('fadenstack_agent', 'engine_webhook_targets', '[]');
			if (strlen($raw) > 16384) {
				throw new \UnexpectedValueException();
			}
			$rows = json_decode($raw, true, 6, JSON_THROW_ON_ERROR);
			if (!is_array($rows) || !array_is_list($rows) || count($rows) > 16) {
				throw new \UnexpectedValueException();
			}
			$result = [];
			foreach ($rows as $row) {
				if (!is_array($row) || count($row) !== 6 || array_diff(array_keys($row), ['id', 'connectionId', 'workflowId', 'path', 'nodeId', 'credentialId'])
					|| count(array_filter($row, 'is_string')) !== 6 || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $row['id'])
					|| !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $row['connectionId']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $row['workflowId'])
					|| !preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $row['path']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $row['nodeId'])
					|| !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $row['credentialId']) || isset($result[$row['id']])) {
					throw new \UnexpectedValueException();
				}
				$result[$row['id']] = [...$row, 'stamp' => StructuredProvider::digest($row)];
			}
			return $result;
		} catch (\Throwable) {
			throw new Problem('The administrator webhook target configuration is invalid.', 503);
		}
	}
	public function get(string $id): array {
		return $this->all()[EngineEndpoint::id($id)] ?? throw new Problem('This published webhook target is unavailable.', 403);
	}
}
