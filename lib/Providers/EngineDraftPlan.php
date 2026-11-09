<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;

final class EngineDraftPlan {
	public const TOOLS = ['windmill' => 'nc_windmill_draft', 'n8n' => 'nc_n8n_draft'];
	public const LIMITATION = 'New private draft. No deployment, activation, replacement or execution. Audit only; no Undo/Redo. Stop cannot retract an in-flight request. Uncertain creates are never retried automatically. Native administrators retain normal access.';
	public function __construct(
		private EngineAuthority $native,
		private EngineReads $reads,
		private Configuration $configuration,
	) {
	}
	public static function canMove(array $policy): bool {
		return in_array('private', $policy['processing']['destinationAudiences'], true)
			&& count(array_filter($policy['processing']['movements'], static fn ($rule) => $rule['source'] === $policy['sourceClass'] && $rule['destination'] === 'private')) > 0;
	}
	public function prepare(string $name, array $args, string $requestId): array {
		$provider = array_search($name, self::TOOLS, true);
		if ($provider === false || array_diff(array_keys($args), ['connectionId', 'definitionJson', 'sourceReference', 'sourceRevision', 'sourceKind'])
			|| !is_string($args['connectionId'] ?? null) || !is_string($args['definitionJson'] ?? null)
			|| (array_key_exists('sourceReference', $args) && (!is_string($args['sourceReference']) || !is_string($args['sourceRevision'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $args['sourceRevision'])))
			|| (array_key_exists('sourceRevision', $args) && !isset($args['sourceReference']))
			|| (array_key_exists('sourceKind', $args) && (!isset($args['sourceReference']) || !in_array($args['sourceKind'], ['saved', 'own-draft'], true)))
			|| !preg_match('/^[a-f0-9]{48}$/D', $requestId)) {
			throw new Problem('Choose a configured connection and one bounded new-draft JSON definition.', 400);
		}
		if (!$this->configuration->folderDispatchSupported()) {
			throw new Problem('This native locking configuration is outside the tested dispatch subset.', 403);
		}
		$connection = $this->native->connection($provider, $args['connectionId'], true);
		if (!self::canMove($connection['policy'])) {
			throw new Problem('Writing workflow content to a private engine draft is outside the configured movement policy.', 403);
		}
		if (!in_array($name, $connection['policy']['processing']['tools'], true)) {
			throw new Problem('The administrator has not granted this draft tool.', 403);
		}
		$copy = null;
		if (isset($args['sourceReference'])) {
			$copy = $this->reads->read($provider, ['connectionId' => $args['connectionId'], 'reference' => $args['sourceReference'], 'sourceKind' => $args['sourceKind'] ?? 'saved']);
			if (!hash_equals($copy['resource']['revision'], $args['sourceRevision'])) {
				throw new Problem('The native copy source changed. Read it again and review a new proposal.', 409);
			}
			$inherited = EngineDraft::copy($provider, $copy['definition'], $args['definitionJson']);
			$value = $inherited['value'];
		} else {
			$value = EngineDraft::parse($provider, $args['definitionJson']);
		}
		$principal = $this->native->principal($connection);
		if ($provider === 'windmill') {
			$target = 'u/' . $principal . '/fadenstack_' . substr($requestId, 0, 16);
			$value->path = $target;
		} else {
			$target = $value->name . ' [fdn-' . substr($requestId, 0, 12) . ']';
			$value->name = $target;
		}
		$definition = EngineJson::encode($value);
		EngineAuthority::secretGuard($definition, $connection);
		if ($copy !== null) {
			EngineAuthority::secretGuard(EngineJson::encode($inherited['origin']), $connection);
		}
		$this->native->absent($connection, $target);
		$resource = $copy['resource'] ?? $this->reads->read($provider, ['connectionId' => $args['connectionId']])['resource'];
		return ['toolName' => $name, 'provider' => $provider, 'args' => $args, 'target' => $target, 'before' => null,
			'unsignedJson' => $definition, 'connectionId' => $args['connectionId'], 'connectionRevision' => $connection['revision'],
			'endpointStamp' => $connection['endpoint']['stamp'], 'enginePrincipal' => $principal,
			...($copy === null ? [] : ['copySource' => $copy['copySource'], 'sourceOrigin' => $inherited['origin']]),
			'resource' => $resource, 'destinationAudience' => 'private', 'history' => 'audit-only', 'limitation' => self::LIMITATION
			. ($provider === 'windmill' ? ' An existing own draft is refused; no force save.' : ' Saved drafts can still be run manually in n8n. Subworkflow callers and MCP access are disabled. A unique name identifies this create if its acknowledgement is lost.')];
	}
}
