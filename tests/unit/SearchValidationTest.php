<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\SearchAccessService;
use OCP\App\IAppManager;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\ISearchResult;
use PHPUnit\Framework\TestCase;

class SearchValidationTest extends TestCase {
	public function testScopeRootCannotConsumeTheNativeOverflowSlot(): void {
		$files = $this->createMock(FileService::class);
		$files->method('searchScope')->willReturn(['href' => '/files/alice/Engineering', 'id' => 42]);
		$plan = (new SearchAccessService($files, $this->createMock(IAppManager::class), $this->createMock(IFullTextSearchManager::class)))->plan(['limit' => 1]);
		self::assertCount(1, $plan['queries']);
		$xml = new \DOMDocument();
		self::assertTrue($xml->loadXML($plan['queries'][0]));
		$xpath = new \DOMXPath($xml);
		$xpath->registerNamespace('d', 'DAV:');
		$xpath->registerNamespace('oc', 'http://owncloud.org/ns');
		self::assertSame('42', $xpath->evaluate('string(//d:where/d:and/d:not/d:eq[d:prop/oc:fileid]/d:literal)'));
		self::assertSame(0, $xpath->query('//d:lt[d:prop/oc:fileid] | //d:gt[d:prop/oc:fileid]')->length);
		self::assertSame('2', $xpath->evaluate('string(//d:limit/d:nresults)'));
		self::assertSame(1, $xpath->query('//d:not')->length);
	}
	public function testQueryEncodingAndRelevanceTiers(): void {
		$files = $this->createMock(FileService::class);
		$files->method('searchScope')->willReturn(['href' => '/files/alice/Engineering', 'id' => 42]);
		$plan = (new SearchAccessService($files, $this->createMock(IAppManager::class), $this->createMock(IFullTextSearchManager::class)))->plan(['query' => 'Überblick & <Plan>']);
		self::assertCount(3, $plan['queries']);
		self::assertStringContainsString('Überblick &amp; &lt;Plan&gt;', $plan['queries'][0]);
		self::assertStringContainsString('<d:nresults>21</d:nresults>', $plan['queries'][0]);
		self::assertStringNotContainsString('<d:not><d:eq><d:prop><d:displayname', implode('', $plan['queries']));
		self::assertStringContainsString('<d:not><d:eq><d:prop><oc:fileid', $plan['queries'][0]);
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
	public function testMissingIndexDoesNotFallBackToFilenames(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);
		$manager = $this->createMock(IFullTextSearchManager::class);
		$manager->expects(self::never())->method('search');
		$result = (new SearchAccessService($this->createMock(FileService::class), $apps, $manager))->content('GDPR');
		self::assertFalse($result['available']);
		self::assertSame([], $result['results']);
	}
	public function testIndexedExcerptsAreGatedByCurrentAccess(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$manager = $this->createMock(IFullTextSearchManager::class);
		$manager->method('isAvailable')->willReturn(true);
		$manager->method('isProviderIndexed')->with('files')->willReturn(true);
		$files = $this->createMock(FileService::class);
		$files->method('indexedContentAllowed')->willReturn(true);
		$files->method('resource')->willReturnCallback(function ($id) {
			if ($id === 'nextcloud:files:3') {
				throw new Problem('Revoked', 404);
			}
			return ['id' => 2, 'path' => '/contract.md', 'folder' => false];
		});
		$files->expects(self::once())->method('assertContentAccess')->with('nextcloud:files:2');
		$allowed = $this->createMock(IIndexDocument::class);
		$allowed->method('getId')->willReturn('2');
		$allowed->method('getExcerpts')->willReturn([['excerpt' => '<b>Warranty</b>: 24 months.']]);
		$revoked = $this->createMock(IIndexDocument::class);
		$revoked->method('getId')->willReturn('3');
		$revoked->expects(self::never())->method('getExcerpts');
		$group = $this->createMock(ISearchResult::class);
		$group->method('getDocuments')->willReturn([$allowed, $revoked]);
		$manager->expects(self::once())->method('search')->with(['providers' => ['files'], 'search' => 'warranty', 'size' => 20, 'page' => 1])->willReturn([$group]);
		$result = (new SearchAccessService($files, $apps, $manager))->content('warranty');
		self::assertCount(1, $result['results']);
		self::assertSame('Warranty: 24 months.', $result['results'][0]['excerpt']);
	}
	public function testRestrictedPolicyNeverQueriesTheBroadContentIndex(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$manager = $this->createMock(IFullTextSearchManager::class);
		$manager->method('isAvailable')->willReturn(true);
		$manager->method('isProviderIndexed')->willReturn(true);
		$manager->expects(self::never())->method('search');
		$files = $this->createMock(FileService::class);
		$files->method('indexedContentAllowed')->willReturn(false);
		$result = (new SearchAccessService($files, $apps, $manager))->content('secret');
		self::assertFalse($result['available']);
		self::assertSame([], $result['results']);
	}
}
