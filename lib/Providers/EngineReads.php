<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;

/** Authorize every request with the caller's current engine token; no writes. */
final class EngineReads {
	public const TOOLS = ['windmill' => 'nc_windmill_read', 'n8n' => 'nc_n8n_read'];
	public function __construct(
		private EngineVault $vault,
		private EngineBroker $broker,
		private ProviderPolicy $policies,
		private EngineWebhookTargets $targets,
	) {
	}
	private function policy(string $provider): array {
		$policy = $this->policies->engine($provider);
		$this->policies->assertDisclosure($policy);
		if (!in_array(self::TOOLS[$provider], $policy['processing']['tools'], true)) {
			throw new Problem('This workflow engine read is outside the administrator scope.', 403);
		}
		return $policy;
	}
	private function connection(string $provider, string $id, array $policy): array {
		if (!in_array($id, $policy['collections'], true)) {
			throw new Problem('This workflow connection is outside the administrator scope.', 403);
		}
		$value = $this->vault->resolve($id);
		if ($value['actor'] !== $policy['actor'] || $value['endpoint']['provider'] !== $provider) {
			throw new Problem('The workflow connection belongs to another provider or account.', 403);
		}
		return $value;
	}
	public function capabilities(): array {
		$result = [];
		foreach (self::TOOLS as $provider => $name) {
			try {
				$policy = $this->policy($provider);
			} catch (Problem $error) {
				if (!in_array($error->status, [403, 503], true)) {
					throw $error;
				}
				continue;
			}
			$available = [];
			foreach ($policy['collections'] as $id) {
				try {
					$connection = $this->connection($provider, $id, $policy);
					$this->broker->probe($id, $connection['apiToken'], $connection['revision']);
					$available[] = [$id, $connection['endpoint']['stamp'], $connection['revision']];
				} catch (Problem $error) {
					if (!in_array($error->status, [401, 403, 404, 409, 413, 423, 429, 503], true)) {
						throw $error;
					}
				}
			}
			if ($available) {
				$result[] = ['provider' => $provider, 'apiVersion' => $provider . '-' . EngineEndpoint::VERSIONS[$provider] . '-token',
					'policyVersion' => $policy['policyVersion'], 'enabled' => true, 'tools' => [$name],
					'conflict' => 'read-only', 'history' => 'not-applicable', 'refresh' => 'manual', 'canRead' => true, 'canWrite' => false];
			}
		}
		return $result;
	}
	public function read(string $provider, array $args): array {
		if (!isset(self::TOOLS[$provider]) || array_diff(array_keys($args), ['connectionId', 'reference', 'sourceKind'])
			|| (array_key_exists('connectionId', $args) && !is_string($args['connectionId']))
			|| (array_key_exists('reference', $args) && !is_string($args['reference']))
			|| (array_key_exists('sourceKind', $args) && (!isset($args['reference']) || !in_array($args['sourceKind'], ['saved', 'own-draft'], true)))
			|| (($args['sourceKind'] ?? 'saved') === 'own-draft' && $provider !== 'windmill')) {
			throw new Problem('Choose one configured connection and optional native workflow reference.', 400);
		}
		$policy = $this->policy($provider);
		if (!isset($args['connectionId'])) {
			if (isset($args['reference'])) {
				throw new Problem('Discover a connection before selecting a workflow.', 400);
			}
			$items = [];
			$versions = [];
			foreach ($policy['collections'] as $id) {
				try {
					$connection = $this->connection($provider, $id, $policy);
					$this->broker->probe($id, $connection['apiToken'], $connection['revision']);
					$items[] = ['connectionId' => $id, 'name' => $connection['endpoint']['label'], 'workspace' => $connection['endpoint']['workspace']];
					$versions[] = [$id, $connection['endpoint']['stamp'], $connection['revision']];
				} catch (Problem $error) {
					if (!in_array($error->status, [401, 403, 404, 409, 413, 423, 429, 503], true)) {
						throw $error;
					}
				}
			}
			if (!$items) {
				throw new Problem('Connect your own authorized engine account in Workflow connections.', 403);
			}
			return ['provider' => $provider, 'connections' => $items, 'complete' => true,
				'resource' => $this->resource($provider, 'connections', $policy, [], [$items, $versions])];
		}
		$connection = $this->connection($provider, $args['connectionId'], $policy);
		$endpoint = $connection['endpoint'];
		$reference = $args['reference'] ?? null;
		$kind = $args['sourceKind'] ?? 'saved';
		$request = $reference === null ? EngineRequest::list($policy['actor'], $endpoint) : ($kind === 'own-draft'
			? EngineRequest::ownDraft($policy['actor'], $endpoint, $reference) : EngineRequest::definition($policy['actor'], $endpoint, $reference));
		$data = $this->broker->request($request, $connection['revision']);
		if ($kind === 'own-draft') {
			if ($data === null) {
				throw new Problem('This own-user Windmill draft is unavailable.', 404);
			}
			if (!($data['value'] ?? null) instanceof \stdClass || ($data['value']->path ?? null) !== $reference) {
				throw new Problem('The native own-draft reference could not be verified.', 503);
			}
			// Include draft revision metadata in the source binding, but never
			// treat it as portable graph data or silently read a deployed flow.
			$draftRevision = $data;
			unset($draftRevision['value']);
			$data = get_object_vars($data['value']);
		}
		if ($reference === null) {
			$rows = $provider === 'windmill' ? $data : ($data['data'] ?? null);
			if (!is_array($rows) || !array_is_list($rows) || count($rows) > 21) {
				throw new Problem('Malformed bounded workflow collection.', 503);
			}
			$items = [];
			foreach (array_slice($rows, 0, 20) as $row) {
				if (!$row instanceof \stdClass) {
					throw new Problem('Malformed native workflow metadata.', 503);
				}
				$id = $provider === 'windmill' ? ($row->path ?? null) : ($row->id ?? null);
				if (!is_string($id)) {
					throw new Problem('Unverified native workflow reference.', 503);
				}
				EngineRequest::definition($policy['actor'], $endpoint, $id);
				$revision = $provider === 'windmill' ? ($row->edited_at ?? null) : ($row->versionId ?? null);
				if ($revision !== null && (!is_string($revision) || strlen($revision) > 200)) {
					throw new Problem('Malformed native workflow revision.', 503);
				}
				$items[] = ['reference' => $id, 'name' => ContentPlan::text($provider === 'windmill' ? ($row->summary ?? $id) : ($row->name ?? $id), 200, true),
					'revision' => $revision];
			}
			$snapshot = ['workflows' => $items, 'complete' => count($rows) <= 20 && ($provider === 'windmill' || ($data['nextCursor'] ?? null) === null), 'limit' => 20];
		} else {
			$actual = $provider === 'windmill' ? ($data['path'] ?? null) : ($data['id'] ?? null);
			if ($actual !== $reference || !isset($data[$provider === 'windmill' ? 'value' : 'nodes'])) {
				throw new Problem('The native workflow reference or definition could not be verified.', 503);
			}
			// Native pinned-data samples can contain unrelated sensitive records.
			// Request their exclusion and never project them if a provider ignores it.
			unset($data['pinData']);
			$nodes = $provider === 'windmill' ? ($data['value']->modules ?? null) : ($data['nodes'] ?? null);
			if (!is_array($nodes) || !array_is_list($nodes) || count($nodes) > 200
				|| ($provider === 'n8n' && !($data['connections'] ?? null) instanceof \stdClass)) {
				throw new Problem('Use a native workflow with at most 200 nodes.', 413);
			}
			EngineJson::encode((object)$data);
			$snapshot = ['definition' => (object)$data, 'reference' => $reference, ...($kind === 'own-draft' ? ['draftRevision' => $draftRevision] : [])];
		}
		if ($provider === 'n8n') {
			$visible = $reference === null ? array_column($snapshot['workflows'], 'reference') : [$reference];
			$snapshot['executionTargets'] = array_values(array_map(static fn ($target) => array_intersect_key($target, array_flip(['id', 'workflowId', 'path'])),
				array_filter($this->targets->all(), static fn ($target) => $target['connectionId'] === $args['connectionId'] && in_array($target['workflowId'], $visible, true))));
		}
		// Even an engine response echoing the request credential must not turn
		// this server-only vault value into model input. Compare JSON-escaped
		// strings so quotes/backslashes in printable tokens cannot bypass it.
		$encoded = EngineJson::encode($snapshot);
		EngineAuthority::secretGuard($encoded, $connection);
		$resource = $this->resource($provider, $args['connectionId'], $policy, $args, [$snapshot, $endpoint['stamp'], $connection['revision']]);
		unset($snapshot['draftRevision']);
		return [...$snapshot, 'provider' => $provider, 'connectionId' => $args['connectionId'],
			...($reference === null ? [] : ['copySource' => ['reference' => $reference, 'kind' => $kind, 'revision' => $resource['revision']]]),
			'limitation' => 'Saved native workflow data only. Engine permissions apply independently of Nextcloud. Graphs may contain inline inputs or code and stored credential references. Credential stores are never read. No editor state, execution, deployment or automatic refresh is granted by this read.',
			'resource' => $resource];
	}
	private function resource(string $provider, string $collection, array $policy, array $args, mixed $version): array {
		$filename = json_encode($args ?: new \stdClass(), JSON_THROW_ON_ERROR);
		return ['provider' => $provider, 'collectionId' => $collection,
			'resourceId' => 'nextcloud:' . $provider . ':' . rtrim(strtr(base64_encode($filename), '+/', '-_'), '='),
			'revision' => hash('sha256', EngineJson::encode($version)), 'policyVersion' => $policy['policyVersion'],
			'sourceClass' => $policy['sourceClass'], 'audience' => 'unknown', 'filename' => $filename];
	}
}
