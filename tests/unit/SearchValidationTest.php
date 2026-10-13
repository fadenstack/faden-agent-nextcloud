<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\SearchAccessService;
use PHPUnit\Framework\TestCase;

class SearchValidationTest extends TestCase {
	public function testQueryEncodingAndRelevanceTiers(): void {
		$files = $this->createMock(FileService::class);
		$files->method('searchScope')->willReturn(['href' => '/files/alice/Engineering', 'id' => 42]);
		$plan = (new SearchAccessService($files))->plan(['query' => 'Überblick & <Plan>']);
		self::assertCount(3, $plan['queries']);
		self::assertStringContainsString('Überblick &amp; &lt;Plan&gt;', $plan['queries'][0]);
		self::assertStringContainsString('<d:nresults>21</d:nresults>', $plan['queries'][0]);
		self::assertStringContainsString('<d:not><d:eq><d:prop><oc:fileid/>', $plan['queries'][0]);
		self::assertStringContainsString('<d:descending/>', $plan['queries'][0]);
	}
	public function testInvalidScope(): void {
		$this->expectException(Problem::class);
		SearchAccessService::filters(['scope' => '/files/admin']);
	}
	public function testBounds(): void {
		$this->expectException(Problem::class);
		SearchAccessService::filters(['limit' => 51]);
	}
	public function testInvalidDate(): void {
		$this->expectException(Problem::class);
		SearchAccessService::filters(['modified_after' => '2026-02-30T00:00:00Z']);
	}
	public function testUnknownParameters(): void {
		$this->expectException(Problem::class);
		SearchAccessService::filters(['external_url' => 'https://evil.invalid']);
	}
}
