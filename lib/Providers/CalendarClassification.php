<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

/** Conservative disclosure classification, never permission to edit or invite. */
final class CalendarClassification {
	public static function sourceClass(string $raw, string $baseline): string {
		$unfolded = preg_replace('/\r?\n[ \t]/', '', $raw);
		foreach (preg_split('/\r?\n/', $unfolded) as $line) {
			$quoted = false;
			for ($index = 0, $length = strlen($line); $index < $length; $index++) {
				if ($line[$index] === '"') {
					$quoted = !$quoted;
				}
				if ($line[$index] !== ':' || $quoted) {
					continue;
				}
				$name = strtoupper(explode(';', substr($line, 0, $index), 2)[0]);
				if ($name === 'CLASS' && strtoupper(trim(substr($line, $index + 1))) !== 'PUBLIC') {
					return 'protected';
				}
				break;
			}
		}
		return $baseline;
	}
}
