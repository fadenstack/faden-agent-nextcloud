<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\ContentPlan;
use OCA\FadenstackAgent\Service\Problem;
use PHPUnit\Framework\TestCase;

final class ContentPlanTest extends TestCase {
	public function testCanonicalRootUrlIsBoundBeforeApproval(): void {
		self::assertSame('https://example.invalid/', ContentPlan::bookmarkUrl('https://example.invalid'));
		self::assertSame('https://example.invalid/a?q=1#part', ContentPlan::bookmarkUrl('https://example.invalid/a?q=1#part'));
	}
	public function testAmbiguousAndCredentialBearingUrlsCannotMergeAnotherBookmark(): void {
		foreach (['https://user:password@example.invalid/', 'http://127.0.0.1/', 'http://[::1]/', 'https://example.invalid:443/', 'https://Example.invalid/', 'https://example.invalid/a/../b', 'https://example.invalid/a/%2e%2e/b', 'https://example.invalid/a\\b', 'https://example.invalid/a b', 'https://example.invalid/%zz', 'https://example.invalid/a{b}'] as $url) {
			try {
				ContentPlan::bookmarkUrl($url);
				self::fail('Accepted an ambiguous URL.');
			} catch (Problem $error) {
				self::assertSame(400, $error->status);
			}
		}
	}
}
