<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;

/** New drafts only. Never a graph replacement, activation or deployment. */
final class EngineDraft {
	public const MARKER = 'x-fadenstack-provenance';
	private const COMMENT = '<!-- fadenstack-provenance:';
	private static function fields(string $provider): array {
		return $provider === 'windmill' ? ['summary', 'description', 'value', 'schema'] : ['name', 'description', 'nodes', 'connections', 'settings', 'staticData', 'nodeGroups'];
	}
	/** Native source plus explicit top-level replacements; never a lossy model reconstruction. */
	public static function copy(string $provider, \stdClass $source, string $replacementJson): array {
		$fields = self::fields($provider);
		$metadata = $provider === 'windmill'
			? ['path', 'workspace_id', 'edited_by', 'edited_at', 'archived', 'extra_perms', 'dependency_job', 'lock_error_logs', 'versions', 'version_id', 'is_draft', 'draft_saved_at', 'no_deployed']
			: ['id', 'active', 'activeVersionId', 'createdAt', 'updatedAt', 'isArchived', 'versionId', 'versionCounter', 'sourceWorkflowId', 'triggerCount', 'pinData', 'meta', 'tags', 'shared', 'activeVersion'];
		if (array_diff(array_keys(get_object_vars($source)), [...$fields, ...$metadata])) {
			throw new Problem('The native source contains unsupported root fields. Review it in the native editor; no fields were silently dropped.', 400);
		}
		$base = EngineJson::decode(EngineJson::encode((object)array_intersect_key(get_object_vars($source), array_flip($fields))));
		$previous = self::previous($provider, $base);
		$patch = EngineJson::decode($replacementJson);
		if (!$patch instanceof \stdClass || array_diff(array_keys(get_object_vars($patch)), $fields)) {
			throw new Problem('Use only documented draft fields as explicit top-level replacements.', 400);
		}
		// A replacement cannot supply or erase the authoritative source origin.
		$encoded = EngineJson::encode($patch);
		if (str_contains($encoded, self::COMMENT) || ($provider === 'windmill' && ($patch->schema ?? null) instanceof \stdClass && property_exists($patch->schema, self::MARKER))) {
			throw new Problem('Do not include origin metadata in copy replacements. The native source supplies it.', 400);
		}
		foreach (get_object_vars($patch) as $key => $value) {
			$base->{$key} = $value;
		}
		return ['value' => self::parse($provider, EngineJson::encode($base)), 'origin' => $previous];
	}
	public static function parse(string $provider, string $json): \stdClass {
		$value = EngineJson::decode($json);
		if (!$value instanceof \stdClass) {
			throw new Problem('Use one workflow definition object.', 400);
		}
		$allowed = self::fields($provider);
		if (!in_array($provider, ['windmill', 'n8n'], true) || array_diff(array_keys(get_object_vars($value)), $allowed)) {
			throw new Problem('Unsupported workflow fields cannot be silently dropped. Use the documented new-draft fields.', 400);
		}
		$value->description = ContentPlan::text($value->description ?? '', 16000, true);
		if ($provider === 'windmill') {
			$value->summary = ContentPlan::text($value->summary ?? null, 200);
			if (!($value->value ?? null) instanceof \stdClass) {
				throw new Problem('Use a native Windmill flow value.', 400);
			}
			$nodes = $value->value->modules ?? null;
			$value->schema ??= (object)['type' => 'object', 'properties' => new \stdClass()];
			if (!$value->schema instanceof \stdClass) {
				throw new Problem('Use an object input schema.', 400);
			}
		} else {
			$value->name = ContentPlan::text($value->name ?? null, 80);
			$nodes = $value->nodes ?? null;
			if (!($value->connections ?? null) instanceof \stdClass || !($value->settings ?? null) instanceof \stdClass) {
				throw new Problem('Use native n8n connection and settings objects.', 400);
			}
			// A saved inactive workflow can otherwise be called as a subworkflow
			// or exposed to MCP. These transformations precede the exact preview.
			$value->settings->callerPolicy = 'none';
			$value->settings->availableInMCP = false;
			$value->staticData ??= null;
			$value->nodeGroups ??= [];
		}
		if (!is_array($nodes) || !array_is_list($nodes)) {
			throw new Problem($provider === 'windmill'
				? 'Use Windmill value.modules as an array of native module objects.'
				: 'Use n8n nodes as an array of native node objects.', 400);
		}
		if (count($nodes) > 200) {
			throw new Problem('Use at most 200 top-level nodes or modules.', 413);
		}
		if ($provider === 'n8n' && $nodes === []) {
			throw new Problem('Include at least one native n8n node. An empty workflow cannot retain AI origin in graphical export; no provenance node is inserted automatically.', 400);
		}
		foreach ($nodes as $node) {
			if (!$node instanceof \stdClass) {
				throw new Problem('Use native workflow node objects.', 400);
			}
		}
		if ($provider === 'n8n' && isset($nodes[0]->notes) && !is_string($nodes[0]->notes)) {
			throw new Problem('Use text notes on the first native n8n node for exportable AI origin.', 400);
		}
		EngineJson::encode($value);
		return $value;
	}
	/** Both legacy description comments and exportable node-note comments remain readable. */
	private static function stripComment(string $text): array {
		if (!str_contains($text, self::COMMENT)) {
			return [$text, null, false];
		}
		if (substr_count($text, self::COMMENT) !== 1 || !preg_match('/\n?<!-- fadenstack-provenance:([A-Za-z0-9+\/=]+) -->$/D', $text, $match)) {
			throw new Problem('The existing workflow origin carrier cannot be preserved safely.', 400);
		}
		$decoded = base64_decode($match[1], true);
		if (!is_string($decoded)) {
			throw new Problem('The existing workflow origin carrier is invalid.', 400);
		}
		return [substr($text, 0, strlen($text) - strlen($match[0])), EngineJson::decode($decoded), true];
	}
	private static function previous(string $provider, \stdClass $value): mixed {
		if ($provider === 'windmill') {
			$old = $value->schema->{self::MARKER} ?? null;
			unset($value->schema->{self::MARKER});
			return $old;
		}
		[$value->description, $old, $found] = self::stripComment($value->description ?? '');
		foreach ($value->nodes ?? [] as $node) {
			if (!$node instanceof \stdClass || !is_string($node->notes ?? null) || !str_contains($node->notes, self::COMMENT)) {
				continue;
			}
			[$node->notes, $candidate] = self::stripComment($node->notes);
			if ($found && EngineJson::encode($old) !== EngineJson::encode($candidate)) {
				throw new Problem('The workflow origin carriers disagree. Review them in the native editor before creating a new draft.', 400);
			}
			$old = $candidate;
			$found = true;
		}
		return $old;
	}
	public static function source(string $provider, \stdClass $value): string {
		$copy = EngineJson::decode(EngineJson::encode($value));
		self::previous($provider, $copy);
		if ($provider === 'windmill') {
			return EngineJson::encode(['summary' => $copy->summary, 'description' => $copy->description, 'value' => $copy->value, 'schema' => $copy->schema]);
		}
		// v2 treats absent and empty node notes alike, so a comment-only carrier
		// can be stripped without adding a new field to the canonical source.
		foreach ($copy->nodes as $node) {
			if (property_exists($node, 'notes') && ($node->notes === '' || $node->notes === null)) {
				unset($node->notes);
			}
		}
		return EngineJson::encode($copy);
	}
	public static function mark(string $provider, \stdClass $value, array $signed, ?array $nativeOrigin = null): string {
		$source = self::source($provider, $value);
		$previous = self::previous($provider, $value);
		if ($nativeOrigin !== null) {
			$previous = $nativeOrigin['origin'];
		}
		$mark = ['contract' => 'fadenstack/' . $provider . '-origin/' . ($provider === 'windmill' ? 'v1' : 'v2'), 'ai_generated' => true, 'mode' => 'ai-assisted',
			'payload_profile' => $provider === 'windmill' ? 'windmill-flow-source-json/v1' : 'n8n-draft-source-json/v2',
			'payload_sha256' => hash('sha256', $source), 'signed_record' => $signed,
			...($previous === null ? [] : ['previous_unverified' => $previous])];
		if ($provider === 'windmill') {
			$value->schema->{self::MARKER} = (object)$mark;
		} else {
			$node = $value->nodes[0] ?? null;
			if (!$node instanceof \stdClass || (isset($node->notes) && !is_string($node->notes))) {
				throw new Problem('Use a native n8n node with text notes for exportable AI origin.', 400);
			}
			$notes = $node->notes ?? '';
			$node->notes = $notes . ($notes === '' ? '' : "\n") . self::COMMENT . base64_encode(EngineJson::encode($mark)) . ' -->';
		}
		return EngineJson::encode($value);
	}
	public static function matches(string $expected, \stdClass $actual): bool {
		$value = EngineJson::decode($expected);
		$projection = new \stdClass();
		foreach (get_object_vars($value) as $field => $_) {
			if (!property_exists($actual, $field)) {
				return false;
			}
			$projection->{$field} = $actual->{$field};
		}
		return hash_equals($expected, EngineJson::encode($projection));
	}
}
