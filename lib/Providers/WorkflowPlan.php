<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;

/** Five bounded operations. No generic Flow classes, live canvas or body replacement. */
final class WorkflowPlan {
	public const ACTION = 'OCA\\FilesAutomatedTagging\\Operation';
	public const ENTITY = 'OCA\\WorkflowEngine\\Entity\\File';
	public const CHECK = 'OCA\\WorkflowEngine\\Check\\FileName';
	public const EVENT = '\\OCP\\Files::postCreate';
	public const EMPTY_BOARD = '{"elements":[],"scrollToContent":true}';
	public const TOOLS = ['whiteboard' => ['nc_whiteboard_read', 'nc_whiteboard_create'], 'flow' => ['nc_flow_read', 'nc_flow_create_draft', 'nc_flow_update_rule']];
	public const LIMITATION = 'Individual approval applies only to this exact native payload, account and audience. Audit only; no Undo or Redo. Stop prevents further dispatch, not an in-flight request or an already triggered automation. An uncertain request is never repeated automatically.';
	public const FLOW_LIMITATION = 'Native last-write-wins: native editors are not locked by our final check. A blocked draft uses contradictory native filename conditions, not a native disabled flag. Native Flow labels saved rules Active; both conditions must remain until separately approved activation. Personal tagging can run on matching file changes throughout this native account, including shared files, and may trigger other tag-based automation. Existing tag effects are not reversed by disabling or deleting the rule.';
	public function __construct(
		private WorkflowAuthority $native,
		private Configuration $configuration,
	) {
	}
	/** Return a canonical bounded recipe; reject unknown fields instead of dropping them. */
	public static function validateRecipe(array $rule): array {
		if (array_diff(array_keys($rule), ['id', 'class', 'name', 'checks', 'operation', 'entity', 'events'])
			|| (isset($rule['class']) && $rule['class'] !== self::ACTION) || ($rule['entity'] ?? null) !== self::ENTITY || ($rule['events'] ?? null) !== [self::EVENT]) {
			throw new Problem('This native Flow recipe is outside the tested subset.', 400);
		}
		$name = ContentPlan::text($rule['name'] ?? null, 200);
		$tag = $rule['operation'] ?? null;
		if (!is_string($tag) || !preg_match('/^[1-9][0-9]{0,14}$/D', $tag)) {
			throw new Problem('Use one native tag identifier.', 400);
		}
		$checks = $rule['checks'] ?? null;
		if (!is_array($checks) || !array_is_list($checks) || !in_array(count($checks), [1, 2], true)) {
			throw new Problem('Use the tested native filename condition.', 400);
		}
		$filename = null;
		foreach ($checks as $index => $check) {
			if (!is_array($check) || array_diff(array_keys($check), ['class', 'operator', 'value']) || ($check['class'] ?? null) !== self::CHECK || ($check['operator'] ?? null) !== ($index === 0 ? 'is' : '!is')) {
				throw new Problem('The native Flow guard changed. Use the native editor.', 409);
			}
			$value = ContentPlan::text($check['value'] ?? null, 255);
			NativeRequest::segment($value);
			if ($filename !== null && $value !== $filename) {
				throw new Problem('The native draft no longer has a contradictory guard.', 409);
			}
			$filename = $value;
		}
		return ['name' => $name, 'checks' => $checks, 'operation' => $tag, 'entity' => self::ENTITY, 'events' => [self::EVENT]];
	}
	private function resource(string $provider, array $policy, mixed $revision, array $args, string $audience = 'private', ?string $sourceClass = null): array {
		return ['provider' => $provider, 'collectionId' => 'personal', 'resourceId' => 'nextcloud:' . $provider . ':' . rtrim(strtr(base64_encode(json_encode($args ?: new \stdClass(), JSON_THROW_ON_ERROR)), '+/', '-_'), '='),
			'revision' => StructuredProvider::digest($revision), 'policyVersion' => $policy['policyVersion'], 'audience' => $audience, 'sourceClass' => $sourceClass ?? $policy['sourceClass'], 'filename' => json_encode($args ?: new \stdClass(), JSON_THROW_ON_ERROR)];
	}
	public function read(string $provider, array $args, bool $audit = false): array {
		$policy = $this->native->policy($provider, $audit);
		if ($provider === 'whiteboard') {
			if (array_keys($args) !== ['path']) {
				throw new Problem('Choose one saved native board.', 400);
			}
			$value = $this->native->board($policy, ContentPlan::text($args['path'], 2048));
			return ['scene' => $value['scene'], 'fileResourceId' => $value['scope']['resourceId'], 'storedOnly' => true, 'limitation' => 'Saved scene only. Unsaved browser and collaborative-server state are excluded. Use the native canvas for editing.',
				'resource' => $this->resource($provider, $policy, [$value['scope'], $value['digest']], $args, $value['scope']['audience'], $value['scope']['sourceClass'])];
		}
		if ($args !== []) {
			throw new Problem('Personal Flow read takes no account or administrator selector.', 400);
		}
		$rules = $this->native->rules($policy);
		$summary = [];
		foreach ($rules as $id => $rule) {
			try {
				$recipe = self::validateRecipe($rule);
				$tag = $this->native->tag($policy, (int)$recipe['operation']);
				$summary[] = ['id' => $id, 'description' => $recipe['name'], 'operation' => 'tag_file', 'filename' => $recipe['checks'][0]['value'], 'tag' => $tag, 'active' => count($recipe['checks']) === 1, 'blockedDraft' => count($recipe['checks']) === 2, 'supported' => true];
			} catch (Problem) {
				// Arbitrary native operation data can contain URLs or secrets. Never
				// disclose those configs or silently rewrite an unsupported recipe.
				$summary[] = ['id' => $id, 'description' => ContentPlan::text($rule['name'] ?? '', 200, true), 'supported' => false, 'handoff' => 'Inspect this rule in native Flow.'];
			}
		}
		$tags = $this->native->tags($policy);
		return ['rules' => $summary, 'availableTags' => $tags, 'complete' => true, 'limitation' => self::FLOW_LIMITATION,
			'resource' => $this->resource('flow', $policy, [$rules, $tags], [])];
	}
	public function prepare(string $tool, array $args): array {
		$provider = explode('_', $tool)[1] ?? '';
		if (!isset(self::TOOLS[$provider]) || !in_array($tool, array_slice(self::TOOLS[$provider], 1), true)) {
			throw new Problem('Unsupported workflow mutation.', 400);
		}
		$policy = $this->native->policy($provider);
		if (!$policy['writesEnabled'] || !$this->configuration->folderDispatchSupported() || !in_array($tool, $policy['processing']['tools'], true)) {
			throw new Problem('This native workflow write is disabled.', 403);
		}
		$before = null;
		$nativeId = 0;
		$audience = 'private';
		$sourceClass = $policy['sourceClass'];
		if ($provider === 'whiteboard') {
			if (array_diff(array_keys($args), ['path', 'name'])) {
				throw new Problem('Unexpected board creation fields.', 400);
			}
			$path = ContentPlan::text($args['path'] ?? null, 2048);
			$name = ContentPlan::text($args['name'] ?? null, 100);
			$value = $this->native->destination($policy, $path, $name);
			$payload = ['folderId' => $value['node']->getId(), 'folderPath' => $value['path'], 'name' => $name, 'content' => self::EMPTY_BOARD];
			$authority = ['folderId' => $payload['folderId'], 'path' => $value['path'], 'permissions' => $value['node']->getPermissions(), 'scope' => $value['scope']];
			$audience = $value['scope']['audience'];
			$sourceClass = $value['scope']['sourceClass'];
			$operation = 'create';
			$target = '/' . ($value['path'] === '' ? '' : $value['path'] . '/') . $name;
			$display = [['label' => 'New board', 'after' => $target], ['label' => 'Canvas', 'after' => 'Empty native board. Existing files and live canvases are never replaced.']];
			$limitation = 'Native conditional creation refuses an existing destination. Only the saved file is created; edits in the native canvas require its collaboration/save service. ' . self::LIMITATION;
		} else {
			$rules = $this->native->rules($policy);
			$authority = ['actor' => $policy['actor']];
			if ($tool === 'nc_flow_create_draft') {
				if (array_diff(array_keys($args), ['description', 'operation', 'config']) || ($args['operation'] ?? null) !== 'tag_file' || !is_array($args['config'] ?? null) || array_diff(array_keys($args['config']), ['filename', 'tagId'])) {
					throw new Problem('Only the reviewed tag_file recipe is supported.', 400);
				}
				$name = ContentPlan::text($args['description'] ?? null, 200);
				$filename = ContentPlan::text($args['config']['filename'] ?? null, 255);
				NativeRequest::segment($filename);
				$tag = $this->native->tag($policy, ContentAuthority::id($args['config']['tagId'] ?? null));
				$condition = ['class' => self::CHECK, 'operator' => 'is', 'value' => $filename];
				$payload = ['class' => self::ACTION, 'name' => $name, 'checks' => [$condition, [...$condition, 'operator' => '!is']], 'operation' => (string)$tag['id'], 'entity' => self::ENTITY, 'events' => [self::EVENT]];
				$operation = 'create';
			} else {
				if (array_diff(array_keys($args), ['ruleId', 'active', 'deleteRule']) || (array_key_exists('active', $args) === array_key_exists('deleteRule', $args)) || (isset($args['active']) && !is_bool($args['active'])) || (isset($args['deleteRule']) && $args['deleteRule'] !== true)) {
					throw new Problem('Choose one state change or deletion.', 400);
				}
				$nativeId = ContentAuthority::id($args['ruleId'] ?? null);
				$before = $rules[$nativeId] ?? throw new Problem('This rule is not in your native personal Flow collection.', 403);
				$payload = self::validateRecipe($before);
				$tag = $this->native->tag($policy, (int)$payload['operation']);
				if ($args['deleteRule'] ?? false) {
					$operation = 'delete';
					$payload = [];
				} else {
					$wasActive = count($payload['checks']) === 1;
					if ($wasActive === $args['active']) {
						throw new Problem('The rule already has the requested state. No write is needed.', 409);
					}
					$condition = $payload['checks'][0];
					$payload['checks'] = $args['active'] ? [$condition] : [$condition, [...$condition, 'operator' => '!is']];
					$operation = 'update';
				}
			}
			if ($payload) {
				self::validateRecipe($payload);
			}
			$authority['tag'] = $tag;
			$target = 'Personal Flow: ' . ($payload['name'] ?? $before['name']);
			$state = $operation === 'delete' ? 'Delete rule permanently; existing tag effects remain' : (count($payload['checks']) === 1 ? 'Activate automatic tagging' : 'Blocked draft: filename is X AND filename is not X');
			$display = [['label' => 'Rule', 'after' => $target], ['label' => 'Requested state', 'after' => $state], ['label' => 'Filename', 'after' => ($payload ?: $before)['checks'][0]['value']], ['label' => 'Native tag', 'after' => $tag['name'] . ' (#' . $tag['id'] . ')'], ['label' => 'Trigger scope', 'after' => 'Matching native file changes throughout this user account, including shared files. Other tag-based automation may run.']];
			$limitation = self::FLOW_LIMITATION . ' ' . self::LIMITATION;
		}
		return ['provider' => $provider, 'toolName' => $tool, 'args' => $args, 'operation' => $operation, 'collectionId' => 'personal', 'itemId' => $nativeId, 'payload' => $payload, 'before' => $before,
			'authority' => StructuredProvider::digest($authority), 'target' => $target, 'resource' => $this->resource($provider, $policy, [$authority, $before], $provider === 'whiteboard' ? ['path' => $args['path']] : ['proposalTarget' => $operation, 'ruleId' => $nativeId], $audience, $sourceClass),
			'display' => [['label' => 'Native account', 'after' => $policy['actor']], ...$display], 'limitation' => $limitation, 'history' => 'audit-only'];
	}
}
