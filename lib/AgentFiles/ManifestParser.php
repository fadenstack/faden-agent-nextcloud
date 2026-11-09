<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\AgentFiles;

use OCA\FadenstackAgent\Service\Problem;

/** Independently written v1.9 subset. No schema download or Microsoft code. */
final class ManifestParser {
	public const MAX_BYTES = 65536;
	public const SCHEMA = 'https://developer.microsoft.com/json-schemas/copilot/declarative-agent/v1.9/schema.json';
	public function parse(string $raw): \stdClass {
		if (strlen($raw) > self::MAX_BYTES || str_contains($raw, "\0") || !mb_check_encoding($raw, 'UTF-8')) {
			throw new Problem('Use a UTF-8 manifest of at most 64 KiB.', 413);
		}
		try {
			$value = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			throw new Problem('Use a valid declarative-agent JSON object.', 400);
		}
		// Compare decoded property names, including Unicode escapes. JSON decoding
		// alone silently accepts duplicate keys and is not a sufficient validator.
		preg_match_all('/"(?:\\\\.|[^"\\\\])*"|[{}\[\]:,]/s', $raw, $tokens);
		$stack = [];
		foreach ($tokens[0] as $i => $token) {
			if ($token === '{' || $token === '[') {
				$stack[] = ['seen' => []];
			} elseif ($token === '}' || $token === ']') {
				array_pop($stack);
			} elseif (str_starts_with($token, '"') && ($tokens[0][$i + 1] ?? '') === ':') {
				$key = json_decode($token, true, 2, JSON_THROW_ON_ERROR);
				$level = count($stack) - 1;
				if (isset($stack[$level]['seen'][$key])) {
					throw new Problem('Duplicate manifest JSON property.', 400);
				}
				$stack[$level]['seen'][$key] = true;
			}
		}
		self::keys($value, ['$schema', 'version', 'id', 'name', 'description', 'instructions', 'capabilities', 'conversation_starters']);
		if (($value->version ?? null) !== 'v1.9' || ($value->{'$schema'} ?? null) !== self::SCHEMA) {
			throw new Problem('Only the documented declarative-agent v1.9 import subset is supported.', 400);
		}
		foreach (['name' => 100, 'description' => 1000, 'instructions' => 8000] as $field => $limit) {
			self::text($value->{$field} ?? null, $limit);
		}
		if (property_exists($value, 'id')) {
			self::text($value->id, 4000);
		}
		$caps = $value->capabilities ?? null;
		if (!is_array($caps) || count($caps) !== 1) {
			throw new Problem('Use one explicitly mapped document-knowledge capability.', 400);
		}
		self::keys($caps[0], ['name', 'items_by_url']);
		if (($caps[0]->name ?? null) !== 'OneDriveAndSharePoint' || !is_array($caps[0]->items_by_url ?? null)
			|| count($caps[0]->items_by_url) < 1 || count($caps[0]->items_by_url) > 5) {
			throw new Problem('Use one to five explicit source URL references.', 400);
		}
		$references = [];
		foreach ($caps[0]->items_by_url as $item) {
			self::keys($item, ['url']);
			$url = self::reference($item->url ?? null);
			if (in_array($url, $references, true)) {
				throw new Problem('Duplicate manifest source reference.', 400);
			}
			$references[] = $url;
		}
		$starters = $value->conversation_starters ?? [];
		if (!is_array($starters) || count($starters) > 12) {
			throw new Problem('Use at most twelve conversation starters.', 400);
		}
		foreach ($starters as $starter) {
			self::keys($starter, ['title', 'text']);
			self::text($starter->text ?? null, 4000);
			if (property_exists($starter, 'title')) {
				self::text($starter->title, 4000);
			}
		}
		return $value;
	}
	private static function keys(mixed $value, array $allowed): void {
		if (!$value instanceof \stdClass || array_diff(array_keys(get_object_vars($value)), $allowed)) {
			throw new Problem('Unsupported manifest property, action, skill or selector.', 400);
		}
	}
	private static function text(mixed $value, int $limit): void {
		if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
			throw new Problem('Use bounded non-empty manifest text.', 400);
		}
	}
	public static function reference(mixed $value): string {
		if (!is_string($value) || strlen($value) > 2048 || !preg_match('~^https://[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.sharepoint\.com/[^\s?#\\\\\x00-\x1f\x7f]+$~D', $value)
			|| preg_match('/%(?![a-fA-F0-9]{2})/', $value)) {
			throw new Problem('Use a supported explicit SharePoint URL reference; URLs are never fetched.', 400);
		}
		return $value;
	}
}
