<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCA\FadenstackAgent\Service\FrontMatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The front matter mark byte for byte as @fadenstack/provenance writes it: the vectors in
 * tests/fixtures/provenance/front-matter.json come from the SDK's withFrontMatter (tests/unit/provenance.test.ts
 * checks the SDK still writes them).
 */
class FrontMatterTest extends TestCase {
	/** @return array<string, array{string, string, string, ?string, ?string}> */
	public static function vectors(): array {
		$fixture = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/provenance/front-matter.json'), true, 16, JSON_THROW_ON_ERROR);
		$cases = [];
		foreach ($fixture['vectors'] as $vector) {
			$cases[$vector['name']] = [$fixture['recordId'], $vector['input'], $vector['marked'], $vector['head'], $vector['inputHead']];
		}
		return $cases;
	}

	#[DataProvider('vectors')]
	public function testWritesTheMarkAsTheSdkDoes(string $id, string $input, string $marked, ?string $head, ?string $inputHead): void {
		self::assertSame($marked, FrontMatter::withMark($input, $id));
		self::assertSame($head, FrontMatter::head($marked));
		self::assertSame($inputHead, FrontMatter::head($input));
	}

	public function testMarkingTwiceKeepsOneMark(): void {
		$once = FrontMatter::withMark("---\ntitle: T\n---\nBody\n", '5a0e2b5c-7f3e-4a51-9a40-6a1c55e1d3f1');
		$twice = FrontMatter::withMark($once, '0b6f9a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b');
		self::assertSame("---\ntitle: T\nai-generated: true\nai-record-id: \"0b6f9a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b\"\n---\nBody\n", $twice);
	}
}
