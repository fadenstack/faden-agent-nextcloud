<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Service;

/**
 * The AI mark in a Markdown file's YAML front matter, byte for byte as the Fadenstack SDKs write it
 * (`withFrontMatter` in @fadenstack/provenance): `ai-generated: true` and `ai-record-id: "<record id>"` after the
 * fields of front matter the text has (an earlier mark replaced, the other fields, the closing line and the body
 * kept), or new front matter and a blank line before the text; the text's own line breaks (CRLF when it has any).
 * Nextcloud Text shows front matter as a labelled box and keeps it on save. FileService checks the client's marked
 * text against this, so a write holds the approved text and the mark, and nothing else.
 */
final class FrontMatter {
	public const GENERATED = 'ai-generated';
	public const RECORD = 'ai-record-id';
	private const BOM = "\u{FEFF}";

	public static function withMark(string $text, string $recordId): string {
		$newline = str_contains($text, "\r\n") ? "\r\n" : "\n";
		$bom = str_starts_with($text, self::BOM) ? self::BOM : '';
		$body = substr($text, strlen($bom));
		$ours = [self::GENERATED . ': true', self::RECORD . ': "' . $recordId . '"'];
		$existing = self::parse($body);
		if ($existing === null) {
			return $bom . '---' . $newline . implode($newline, $ours) . $newline . '---' . $newline . $newline . $body;
		}
		$kept = array_filter($existing['lines'], static fn (string $line): bool => !str_starts_with($line, self::GENERATED . ':')
			&& !str_starts_with($line, self::RECORD . ':'));
		return $bom . '---' . $newline . implode($newline, [...$kept, ...$ours]) . $newline . $existing['close'] . $existing['rest'];
	}

	/** The front matter a text starts with, up to and with its closing line, as it is stored; null without any. */
	public static function head(string $text): ?string {
		$bom = str_starts_with($text, self::BOM) ? self::BOM : '';
		$found = self::parse(substr($text, strlen($bom)));
		return $found === null ? null : substr($text, 0, strlen($text) - strlen($found['rest']));
	}

	/**
	 * Front matter at the very start of a text: `---`, the lines, `---` or `...`; null when there is none or it is
	 * never closed. The lines without their line breaks; the closing line and the rest exactly as they are.
	 *
	 * @return array{lines: list<string>, close: string, rest: string}|null
	 */
	private static function parse(string $text): ?array {
		$first = strpos($text, "\n");
		if ($first === false || self::line(substr($text, 0, $first)) !== '---') {
			return null;
		}
		$lines = [];
		$length = strlen($text);
		for ($at = $first + 1; $at < $length;) {
			$end = strpos($text, "\n", $at);
			$next = $end === false ? $length : $end + 1;
			$line = self::line(substr($text, $at, ($end === false ? $length : $end) - $at));
			if ($line === '---' || $line === '...') {
				return ['lines' => $lines, 'close' => substr($text, $at, $next - $at), 'rest' => substr($text, $next)];
			}
			$lines[] = $line;
			$at = $next;
		}
		return null;
	}

	/** A line without the carriage return of a CRLF break. */
	private static function line(string $line): string {
		return str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;
	}
}
