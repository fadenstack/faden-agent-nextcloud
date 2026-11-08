<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;

/** New drafts only. Never a graph replacement, activation or deployment. */
final class EngineDraft {
	public const MARKER = 'x-fadenstack-provenance';
	private const COMMENT = '<!-- fadenstack-provenance:';
	public static function parse(string $provider, string $json): \stdClass {
		$value = EngineJson::decode($json);
		if (!$value instanceof \stdClass) {
			throw new Problem('Use one workflow definition object.', 400);
		}
		$allowed = $provider === 'windmill' ? ['summary', 'description', 'value', 'schema'] : ['name', 'description', 'nodes', 'connections', 'settings', 'staticData', 'nodeGroups'];
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
		if (!is_array($nodes) || !array_is_list($nodes) || count($nodes) > 200) {
			throw new Problem('Use at most 200 top-level nodes or modules.', 413);
		}
		foreach ($nodes as $node) {
			if (!$node instanceof \stdClass) {
				throw new Problem('Use native workflow node objects.', 400);
			}
		}
		EngineJson::encode($value);
		return $value;
	}
	private static function previous(string $provider, \stdClass $value): mixed {
		if ($provider === 'windmill') {
			$old = $value->schema->{self::MARKER} ?? null;
			unset($value->schema->{self::MARKER});
			return $old;
		}
		$description = $value->description;
		if (!str_contains($description, self::COMMENT)) {
			return null;
		}
		if (substr_count($description, self::COMMENT) !== 1 || !preg_match('/\n?<!-- fadenstack-provenance:([A-Za-z0-9+\/=]+) -->$/D', $description, $match)) {
			throw new Problem('The existing workflow origin carrier cannot be preserved safely.', 400);
		}
		$decoded = base64_decode($match[1], true);
		if (!is_string($decoded)) {
			throw new Problem('The existing workflow origin carrier is invalid.', 400);
		}
		$value->description = substr($description, 0, strlen($description) - strlen($match[0]));
		return EngineJson::decode($decoded);
	}
	public static function source(string $provider, \stdClass $value): string {
		$copy = EngineJson::decode(EngineJson::encode($value));
		self::previous($provider, $copy);
		if ($provider === 'windmill') {
			return EngineJson::encode(['summary' => $copy->summary, 'description' => $copy->description, 'value' => $copy->value, 'schema' => $copy->schema]);
		}
		return EngineJson::encode($copy);
	}
	public static function mark(string $provider, \stdClass $value, array $signed): string {
		$source = self::source($provider, $value);
		$previous = self::previous($provider, $value);
		$mark = ['contract' => 'fadenstack/' . $provider . '-origin/v1', 'ai_generated' => true, 'mode' => 'ai-assisted',
			'payload_profile' => $provider === 'windmill' ? 'windmill-flow-source-json/v1' : 'n8n-draft-source-json/v1',
			'payload_sha256' => hash('sha256', $source), 'signed_record' => $signed,
			...($previous === null ? [] : ['previous_unverified' => $previous])];
		if ($provider === 'windmill') {
			$value->schema->{self::MARKER} = (object)$mark;
		} else {
			$value->description .= "\n" . self::COMMENT . base64_encode(EngineJson::encode($mark)) . ' -->';
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
