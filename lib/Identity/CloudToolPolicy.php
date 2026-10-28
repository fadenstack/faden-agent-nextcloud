<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Identity;

use OCA\FadenstackAgent\Service\Problem;

/** Fresh server-to-server checks. Response stamps are comparisons, never permits. */
final class CloudToolPolicy {
	public function __construct(
		private CloudApi $api,
	) {
	}

	public static function schemaHash(): string {
		$schema = json_decode(file_get_contents(__DIR__ . '/../../contracts/history-tools.json'), true, 8, JSON_THROW_ON_ERROR)['parameters'];
		$canonical = static function (mixed $value) use (&$canonical): mixed {
			if (is_array($value)) {
				if (!array_is_list($value)) {
					ksort($value);
				}
				return array_map($canonical, $value);
			}
			return $value;
		};
		return hash('sha256', json_encode($canonical($schema), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	public function check(#[\SensitiveParameter] string $token, VerifiedCloudPrincipal $principal, string $direction, string $actionId, ?array $prepared = null): array {
		if (!in_array($direction, ['undo', 'redo', 'reconcile', 'revalidate'], true) || !preg_match('/^[a-f0-9]{32}$/D', $actionId)) {
			throw new Problem('Invalid history command.', 400);
		}
		$payload = ['name' => 'nc_actions_' . $direction, 'arguments' => ['action_id' => $actionId],
			'tool_mode' => 'ask', 'origin' => 'user_history', 'phase' => $prepared === null ? 'prepare' : 'dispatch',
			'schema_sha256' => self::schemaHash()];
		if ($prepared !== null) {
			foreach (['profile', 'arguments', 'decision'] as $key) {
				if (!is_string($prepared[$key . '_stamp'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $prepared[$key . '_stamp'])) {
					throw new Problem('The history policy check is invalid.', 409);
				}
				$payload['prepared_' . $key] = $prepared[$key . '_stamp'];
			}
		}
		$response = $this->api->request($token, 'tools/check', $payload);
		$value = $response['value'];
		$actor = ['tenant_id' => $principal->tenantId, 'user_id' => $principal->userId,
			'agent_id' => $principal->agentId, 'device_id' => $principal->deviceId];
		$received = $value['principal'] ?? null;
		ksort($actor);
		if (is_array($received)) {
			ksort($received);
		}
		if ($response['origin'] !== $principal->origin || $received !== $actor) {
			throw new Problem('The Fadenstack identity changed. Review a new command.', 409);
		}
		if (($value['decision'] ?? null) !== 'ask' || ($value['tool_class'] ?? null) !== 'destructive') {
			throw new Problem('This history command is not permitted by the current Fadenstack policy.', 403);
		}
		$result = [];
		foreach (['profile_stamp', 'arguments_stamp', 'decision_stamp'] as $key) {
			if (!is_string($value[$key] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $value[$key])) {
				throw new Problem('The Fadenstack policy response is invalid.', 503);
			}
			if ($prepared !== null && !hash_equals($prepared[$key], $value[$key])) {
				throw new Problem('The command or policy changed. Review it again.', 409);
			}
			$result[$key] = $value[$key];
		}
		return $result;
	}
}
