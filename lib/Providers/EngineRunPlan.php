<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCP\IConfig;

/** Version-bound runs of explicitly configured own-user flows. Never deployment. */
final class EngineRunPlan {
	public const TOOL = 'nc_windmill_run';
	public const LIMITATION = 'Runs this deployed flow version once with the displayed inputs. Workflow dependencies and credentials can change independently. Effects may send data, notify others or change external systems; their audience is unknown. No Undo/Redo. Stop prevents further dispatch, but does not cancel a running job. A timeout is never retried automatically.';
	public function __construct(
		private EngineAuthority $native,
		private EngineReads $reads,
		private Configuration $configuration,
		private IConfig $config,
	) {
	}
	public static function canMove(array $policy): bool {
		return in_array('unknown', $policy['processing']['destinationAudiences'], true)
			&& count(array_filter($policy['processing']['movements'], static fn ($rule) => $rule['source'] === $policy['sourceClass'] && $rule['destination'] === 'unknown')) > 0;
	}
	public function targets(string $connectionId): array {
		try {
			$raw = $this->config->getAppValue('fadenstack_agent', 'engine_run_targets', '[]');
			if (strlen($raw) > 16384) {
				throw new \UnexpectedValueException();
			}
			$rows = json_decode($raw, true, 5, JSON_THROW_ON_ERROR);
			if (!is_array($rows) || !array_is_list($rows) || count($rows) > 32) {
				throw new \UnexpectedValueException();
			}
			foreach ($rows as $row) {
				if (!is_array($row) || array_diff(array_keys($row), ['connectionId', 'reference']) || count($row) !== 2
					|| !is_string($row['connectionId'] ?? null) || !is_string($row['reference'] ?? null) || strlen($row['reference']) > 255) {
					throw new \UnexpectedValueException();
				}
			}
			return array_values(array_unique(array_column(array_filter($rows, static fn ($row) => $row['connectionId'] === $connectionId), 'reference')));
		} catch (\Throwable) {
			throw new Problem('The engine execution target configuration is invalid.', 503);
		}
	}
	public static function jobId(string $id): string {
		if (!preg_match('/^[a-f0-9]{48}$/D', $id)) {
			throw new Problem('Use one native action identifier.', 400);
		}
		$hex = substr(hash('sha256', 'windmill-job:' . $id), 0, 32);
		$hex[12] = '4';
		$hex[16] = dechex((hexdec($hex[16]) & 3) | 8);
		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
	}
	public function prepare(array $args, string $id): array {
		$jobId = self::jobId($id);
		if (array_diff(array_keys($args), ['connectionId', 'reference', 'argsJson']) || count($args) !== 3
			|| !is_string($args['connectionId'] ?? null) || !is_string($args['reference'] ?? null) || !is_string($args['argsJson'] ?? null)
			|| strlen($args['argsJson']) > 32768 || !($inputs = EngineJson::decode($args['argsJson'])) instanceof \stdClass) {
			throw new Problem('Choose a configured flow and one bounded input object.', 400);
		}
		if (!$this->configuration->folderDispatchSupported()) {
			throw new Problem('This native locking configuration is outside the tested dispatch subset.', 403);
		}
		$c = $this->native->connection('windmill', $args['connectionId'], true);
		if (!self::canMove($c['policy']) || !in_array(self::TOOL, $c['policy']['processing']['tools'], true)
			|| !in_array($args['reference'], $this->targets($args['connectionId']), true)) {
			throw new Problem('This execution target, tool or unknown-audience movement is outside the configured scope.', 403);
		}
		$principal = $this->native->principal($c);
		if (!str_starts_with($args['reference'], 'u/' . $principal . '/')) {
			throw new Problem('Runs require the connected user\'s own flow.', 403);
		}
		$read = $this->reads->read('windmill', ['connectionId' => $args['connectionId'], 'reference' => $args['reference']]);
		$flow = (array)$read['definition'];
		$version = $flow['version_id'] ?? null;
		if ((!is_int($version) && !is_string($version)) || !preg_match('/^[1-9][0-9]{0,18}$/D', (string)$version) || (string)(int)$version !== (string)$version
			|| ($flow['archived'] ?? null) !== false || ($flow['is_draft'] ?? null) !== false) {
			throw new Problem('Use one current deployed non-archived Windmill flow.', 403);
		}
		// Do not execute publisher impersonation or token-scope override variants.
		foreach (['on_behalf_of', 'on_behalf_of_email', 'job_token_scopes'] as $field) {
			if (isset($flow[$field]) || isset($flow['value']->{$field})) {
				throw new Problem('This flow execution identity is outside the supported subset.', 403);
			}
		}
		$payload = ['argsJson' => EngineJson::encode($inputs), 'version' => (string)$version, 'jobId' => $jobId,
			'workflowJson' => EngineJson::encode((object)$flow)];
		EngineAuthority::secretGuard(EngineJson::encode($payload), $c);
		return ['toolName' => self::TOOL, 'provider' => 'windmill', 'args' => $args, 'target' => $args['reference'], 'before' => null,
			'payload' => $payload, 'connectionId' => $args['connectionId'], 'connectionRevision' => $c['revision'], 'endpointStamp' => $c['endpoint']['stamp'],
			'enginePrincipal' => $principal, 'workspace' => $c['endpoint']['workspace'], 'resource' => $read['resource'],
			'destinationAudience' => 'unknown', 'history' => 'audit-only', 'limitation' => self::LIMITATION];
	}
	/** Match native authority, version and exact arguments; never return code/logs/results. */
	public static function observe(array $p, array $job): array {
		if (($job['id'] ?? null) !== $p['payload']['jobId'] || ($job['workspace_id'] ?? null) !== $p['workspace']
			|| ($job['created_by'] ?? null) !== $p['enginePrincipal'] || ($job['permissioned_as'] ?? null) !== 'u/' . $p['enginePrincipal']
			|| ($job['script_path'] ?? null) !== $p['target'] || ($job['job_kind'] ?? null) !== 'flow'
			|| ($job['script_hash'] ?? null) !== str_pad(dechex((int)$p['payload']['version']), 16, '0', STR_PAD_LEFT)
			|| !($job['args'] ?? null) instanceof \stdClass || EngineJson::encode($job['args']) !== $p['payload']['argsJson']) {
			throw new Problem('The native job identity, flow version or inputs could not be verified.', 503);
		}
		$type = $job['type'] ?? null;
		if (!in_array($type, ['QueuedJob', 'CompletedJob'], true) || ($type === 'CompletedJob' && (!is_bool($job['success'] ?? null) || !is_bool($job['canceled'] ?? null)))) {
			throw new Problem('The native job state could not be verified.', 503);
		}
		$state = $type === 'QueuedJob' ? 'running' : ($job['canceled'] ? 'cancelled' : ($job['success'] ? 'succeeded' : 'failed'));
		return ['present' => true, 'matches' => true, 'reference' => $p['target'], 'jobId' => $p['payload']['jobId'], 'version' => $p['payload']['version'],
			'executionState' => $state, 'dispatchVerified' => true, 'externalEffectsVerified' => false];
	}
}
