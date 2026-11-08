<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;

/** Bounded graph JSON preserving {} versus [] and opaque native fields. */
final class EngineJson {
	public const MAX_BYTES = 131072;
	public static function decode(string $json): mixed {
		if (strlen($json) > self::MAX_BYTES) {
			throw new Problem('Narrow the workflow definition.', 413);
		}
		try {
			$value = json_decode($json, false, 26, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			throw new Problem('Use bounded workflow JSON.', 400);
		}
		self::encode($value);
		return $value;
	}
	public static function encode(mixed $value): string {
		$items = 0;
		try {
			$result = json_encode(self::canonical($value, 0, $items), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
		} catch (Problem $error) {
			throw $error;
		} catch (\Throwable) {
			throw new Problem('Use bounded workflow JSON.', 400);
		}
		if (strlen($result) > self::MAX_BYTES) {
			throw new Problem('Narrow the workflow definition.', 413);
		}
		return $result;
	}
	private static function canonical(mixed $value, int $depth, int &$items): mixed {
		if ($depth > 24 || ++$items > 12000 || is_resource($value) || (is_float($value) && !is_finite($value))) {
			throw new Problem('Narrow the workflow definition.', 413);
		}
		if ($value instanceof \stdClass) {
			$fields = get_object_vars($value);
			ksort($fields, SORT_STRING);
			$result = new \stdClass();
			foreach ($fields as $key => $item) {
				$result->{$key} = self::canonical($item, $depth + 1, $items);
			}
			return $result;
		}
		if (is_object($value)) {
			throw new Problem('Use plain workflow JSON.', 400);
		}
		if (is_array($value)) {
			if (!array_is_list($value)) {
				return self::canonical((object)$value, $depth, $items);
			}
			$result = [];
			foreach ($value as $item) {
				$result[] = self::canonical($item, $depth + 1, $items);
			}
			return $result;
		}
		return $value;
	}
}
