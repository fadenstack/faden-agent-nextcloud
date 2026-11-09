<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCP\IConfig;
use OCP\IURLGenerator;

/** Explicit same-instance public clients. Never dynamic registration from chat. */
final class N8nOAuthConfiguration {
	public function __construct(
		private IConfig $config,
		private EngineEndpoint $endpoints,
		private EngineWebhookTargets $targets,
		private IURLGenerator $urls,
		private Configuration $configuration,
	) {
	}
	public function all(): array {
		try {
			$raw = $this->config->getAppValue('fadenstack_agent', 'engine_oauth', '{"version":1,"clients":[]}');
			if (strlen($raw) > 16384) {
				throw new \UnexpectedValueException();
			}
			$v = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
			if (!is_array($v) || count($v) !== 2 || ($v['version'] ?? null) !== 1 || !is_array($v['clients'] ?? null) || !array_is_list($v['clients']) || count($v['clients']) > 16) {
				throw new \UnexpectedValueException();
			}
			$result = [];
			foreach ($v['clients'] as $row) {
				if (!is_array($row) || count($row) !== 3 || array_diff(array_keys($row), ['targetId', 'issuer', 'clientId']) || count(array_filter($row, 'is_string')) !== 3) {
					throw new \UnexpectedValueException();
				}
				$t = $this->targets->get($row['targetId']);
				if (($t['authentication'] ?? null) !== 'oauth2' || isset($result[$t['id']])) {
					throw new \UnexpectedValueException();
				}
				$b = N8nOAuthBinding::configured($this->endpoints->get($t['connectionId']), $row['issuer'], $row['clientId'],
					$this->urls->linkToRouteAbsolute('fadenstack_agent.engine_oauth.callback', ['id' => $t['connectionId']]), $t['path'], $this->configuration->get()['development']);
				$result[$t['id']] = ['target' => $t, 'binding' => $b, 'stamp' => StructuredProvider::digest([$t, $b->endpoint, $b->issuer, $b->clientId, $b->callback, $b->resource])];
			}
			return $result;
		} catch (\Throwable) {
			throw new Problem('The administrator n8n OAuth configuration is invalid.', 503);
		}
	}
	public function get(string $targetId): array {
		return $this->all()[EngineEndpoint::id($targetId)] ?? throw new Problem('This native OAuth target is unavailable.', 403);
	}
	/** Never returns a grant/token to a controller or proposal. */
	public static function grant(array $c, array $entry, string $principal): array {
		$g = $c['oauth'][$entry['target']['id']] ?? null;
		if (!is_array($g) || ($g['state'] ?? null) !== 'ready' || ($g['stamp'] ?? null) !== $entry['stamp']
			|| ($g['subject'] ?? null) !== $principal || ($g['expiresAt'] ?? 0) <= time() + 30) {
			throw new Problem('Connect or explicitly refresh your own OAuth webhook grant before preparing an execution.', 403);
		}
		return $g;
	}
}
