<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;

/** Explicit, already published personal webhooks. No activation or editor-run API. */
final class N8nExecutePlan {
	public const TOOL = 'nc_n8n_execute';
	public const LIMITATION = 'Send this exact input once to the configured authenticated published webhook of your own personal workflow. Native last-write-wins: another native editor can change the active version after our last check; there is no conditional webhook dispatch. Dependencies and credentials can also change. External effects and their audience are unknown. No Undo/Redo. Stop prevents further agent dispatch but cannot retract a webhook or cancel an execution. No automatic retry. Native execution history may retain request data under native retention settings.';
	public function __construct(
		private EngineAuthority $native,
		private EngineReads $reads,
		private EngineBroker $broker,
		private EngineWebhookTargets $targets,
		private Configuration $configuration,
		private N8nOAuthConfiguration $oauth,
	) {
	}
	public function targetRows(): array {
		return array_values($this->targets->all());
	}
	public function prepare(array $args, string $id): array {
		if (array_diff(array_keys($args), ['targetId', 'dataJson']) || count($args) !== 2 || !is_string($args['targetId'] ?? null)
			|| !is_string($args['dataJson'] ?? null) || strlen($args['dataJson']) > 32768 || !preg_match('/^[a-f0-9]{48}$/D', $id)
			|| !($data = EngineJson::decode($args['dataJson'])) instanceof \stdClass) {
			throw new Problem('Choose one configured published webhook target and bounded JSON input object.', 400);
		}
		if (!$this->configuration->folderDispatchSupported()) {
			throw new Problem('This native locking configuration is outside the tested dispatch subset.', 403);
		}
		$t = $this->targets->get($args['targetId']);
		$c = $this->native->connection('n8n', $t['connectionId'], true);
		$isOAuth = ($t['authentication'] ?? null) === 'oauth2';
		if (!EngineRunPlan::canMove($c['policy']) || !in_array(self::TOOL, $c['policy']['processing']['tools'], true)
			|| (!$isOAuth && ($c['webhooks'][$t['id']]['stamp'] ?? null) !== $t['stamp'])) {
			throw new Problem('This webhook, credential, tool or unknown-audience movement is outside the current scope.', 403);
		}
		$principal = $this->native->principal($c);
		if ($isOAuth) {
			N8nOAuthConfiguration::grant($c, $this->oauth->get($t['id']), $principal);
		}
		$read = $this->reads->read('n8n', ['connectionId' => $t['connectionId'], 'reference' => $t['workflowId']]);
		$flow = (array)$read['definition'];
		EngineAuthority::assertN8nOwner($flow, $principal);
		$hook = self::publishedHook($flow, $t);
		$list = $this->broker->request(EngineRequest::n8nExecutions($c['actor'], $c['endpoint'], $t['workflowId']), $c['revision']);
		if (!is_array($list['data'] ?? null) || !array_is_list($list['data']) || count($list['data']) > 21) {
			throw new Problem('Native execution-read permission is required before webhook approval.', 403);
		}
		$body = EngineJson::encode((object)['_fadenstack' => (object)['actionId' => $id], 'data' => $data]);
		$payload = ['bodyJson' => $body, 'version' => $flow['activeVersionId'], 'workflowJson' => EngineJson::encode((object)$flow)];
		EngineAuthority::secretGuard(EngineJson::encode($payload), $c);
		return ['toolName' => self::TOOL, 'provider' => 'n8n', 'args' => $args, 'target' => $t['workflowId'], 'targetLabel' => ContentPlan::text($flow['name'] ?? '', 200), 'before' => null,
			'payload' => $payload, 'connectionId' => $t['connectionId'], 'connectionRevision' => $c['revision'], 'endpointStamp' => $c['endpoint']['stamp'],
			'enginePrincipal' => $principal, 'webhook' => $t, 'webhookNodeName' => $hook->name, 'resource' => $read['resource'],
			'destinationAudience' => 'unknown', 'history' => 'audit-only', 'limitation' => self::LIMITATION];
	}
	public static function publishedHook(array $flow, array $target): \stdClass {
		$active = $flow['activeVersion'] ?? null;
		if (($flow['id'] ?? null) !== $target['workflowId'] || ($flow['active'] ?? null) !== true || ($flow['isArchived'] ?? null) !== false
			|| !is_string($flow['activeVersionId'] ?? null) || !preg_match('/^[a-f0-9-]{36}$/D', $flow['activeVersionId'])
			|| ($flow['versionId'] ?? null) !== $flow['activeVersionId'] || !$active instanceof \stdClass
			|| ($active->versionId ?? null) !== $flow['activeVersionId'] || ($active->workflowId ?? null) !== $target['workflowId']
			|| !is_array($active->nodes ?? null) || !($active->connections ?? null) instanceof \stdClass
			|| EngineJson::encode($active->nodes) !== EngineJson::encode($flow['nodes'] ?? null)
			|| EngineJson::encode($active->connections) !== EngineJson::encode($flow['connections'] ?? null)) {
			throw new Problem('Use an already published personal workflow with no pending native graph changes.', 403);
		}
		$hooks = array_values(array_filter($active->nodes, static fn ($node) => $node instanceof \stdClass && ($node->type ?? null) === 'n8n-nodes-base.webhook'));
		$hook = $hooks[0] ?? null;
		$oauth = ($target['authentication'] ?? null) === 'oauth2';
		if (count($hooks) !== 1 || ($hook->id ?? null) !== $target['nodeId'] || !is_string($hook->name ?? null) || strlen($hook->name) > 80
			|| ($hook->typeVersion ?? null) !== ($oauth ? 2.2 : 2) || ($hook->disabled ?? false) !== false || !($hook->parameters ?? null) instanceof \stdClass
			|| ($hook->parameters->path ?? null) !== $target['path'] || ($hook->parameters->httpMethod ?? null) !== 'POST'
			|| ($hook->parameters->authentication ?? null) !== ($oauth ? 'n8nOAuth2' : 'headerAuth') || ($hook->parameters->responseMode ?? null) !== 'lastNode'
			|| !($hook->parameters->options ?? null) instanceof \stdClass
			|| ($oauth ? (get_object_vars($hook->parameters->options) !== ['oauthClient' => 'bearer'] || ($hook->parameters->requireExecuteAccess ?? null) !== true
				|| (isset($hook->credentials) && (!$hook->credentials instanceof \stdClass || count(get_object_vars($hook->credentials)) !== 0)))
				: (count(get_object_vars($hook->parameters->options)) !== 0 || ($hook->credentials->httpHeaderAuth->id ?? null) !== $target['credentialId']))) {
			throw new Problem('Use the configured static POST webhook with its reviewed native authentication and receipt response.', 403);
		}
		return $hook;
	}
	/** Null means this is a different action, never evidence of non-dispatch. */
	public static function observe(array $p, array $job): ?array {
		$body = $job['data']->resultData->runData->{$p['webhookNodeName']}[0]->data->main[0][0]->json->body ?? null;
		if (!$body instanceof \stdClass || ($body->_fadenstack->actionId ?? null) !== $p['id']) {
			return null;
		}
		if (($job['workflowId'] ?? null) !== $p['target'] || ($job['workflowVersionId'] ?? null) !== $p['payload']['version']
			|| ($job['workflowData']->id ?? null) !== $p['target'] || ($job['mode'] ?? null) !== 'webhook'
			|| ($job['retryOf'] ?? null) !== null || !is_string($job['id'] ?? null) || !preg_match('/^[1-9][0-9]{0,18}$/D', $job['id'])
			|| EngineJson::encode($body) !== $p['payload']['bodyJson'] || !is_bool($job['finished'] ?? null)) {
			throw new Problem('The native execution workflow, version or exact input could not be verified.', 503);
		}
		$status = $job['status'] ?? null;
		$state = match ($status) {
			'new', 'running', 'waiting' => 'running', 'success' => 'succeeded', 'error', 'crashed' => 'failed', 'canceled' => 'cancelled', default => null,
		};
		if ($state === null || ($status === 'success' && !$job['finished'])) {
			throw new Problem('The native execution state could not be verified.', 503);
		}
		return ['present' => true, 'matches' => true, 'reference' => $p['target'], 'executionId' => $job['id'], 'version' => $p['payload']['version'],
			'executionState' => $state, 'dispatchVerified' => true, 'externalEffectsVerified' => false];
	}
	/** Read-only recovery. Busy/pruned/ambiguous history stays unknown, never resend. */
	public function inspect(array $p, array $c, ?string $executionId = null): array {
		EngineAuthority::assertN8nOwner($this->native->definition($c, $p['target']), $p['enginePrincipal']);
		if ($executionId !== null) {
			$job = $this->broker->request(EngineRequest::n8nExecution($c['actor'], $c['endpoint'], $executionId), $c['revision']);
			return self::observe($p, $job) ?? ['present' => true, 'matches' => false, 'executionState' => 'unknown'];
		}
		$list = $this->broker->request(EngineRequest::n8nExecutions($c['actor'], $c['endpoint'], $p['target']), $c['revision']);
		$items = $list['data'] ?? null;
		if (!is_array($items) || !array_is_list($items) || count($items) > 21) {
			throw new Problem('The bounded native execution list could not be verified.', 503);
		}
		$found = [];
		if (count($items) <= 20 && ($list['nextCursor'] ?? null) === null) {
			foreach ($items as $item) {
				if (!$item instanceof \stdClass || ($item->workflowId ?? null) !== $p['target'] || !is_string($item->id ?? null)) {
					throw new Problem('The native execution filter could not be verified.', 503);
				}
				$job = $this->broker->request(EngineRequest::n8nExecution($c['actor'], $c['endpoint'], $item->id), $c['revision']);
				$match = self::observe($p, $job);
				if ($match !== null) {
					$found[] = $match;
				}
			}
		}
		return count($found) === 1 ? $found[0] : ['present' => count($found) > 0, 'matches' => false, 'executionState' => 'unknown'];
	}
}
