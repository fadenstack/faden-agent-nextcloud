<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\DavReference;
use OCA\FadenstackAgent\Providers\DavResponse;
use OCA\FadenstackAgent\Providers\NativeRequest;
use OCA\FadenstackAgent\Service\Problem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DavResponseTest extends TestCase {
	public static function nativeRoles(): array {
		return [
			['<d:read/>', [true, false, false, false]],
			['<d:read/><d:write/>', [true, true, true, true]],
			['<d:all/>', [true, true, true, true]],
			['<d:bind/>', [false, true, false, false]],
			['<d:write-content/>', [false, false, true, false]],
			['<d:unbind/>', [false, false, false, true]],
			['<x:all xmlns:x="https://foreign.test"/>', [false, false, false, false]],
		];
	}

	#[DataProvider('nativeRoles')]
	public function testActualNativePrivilegesBoundTheAdvertisedOperations(string $privileges, array $expected): void {
		$xpath = DavResponse::document(['status' => 207, 'body' => '<d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:prop><d:current-user-privilege-set><d:privilege>' . $privileges . '</d:privilege></d:current-user-privilege-set></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>'], 'urn:ietf:params:xml:ns:caldav');
		self::assertSame($expected, array_values(DavResponse::privileges($xpath, $xpath->query('/d:multistatus/d:response')->item(0))));
	}

	public function testOnlySuccessfulPropertyBlocksContributeNativeData(): void {
		$xpath = DavResponse::document(['status' => 207, 'body' => '<d:multistatus xmlns:d="DAV:"><d:response><d:href>/item</d:href><d:propstat><d:prop><d:displayname>authorized</d:displayname></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat><d:propstat><d:prop><d:displayname>denied-canary</d:displayname></d:prop><d:status>HTTP/1.1 403 Forbidden</d:status></d:propstat></d:response></d:multistatus>'], 'urn:ietf:params:xml:ns:caldav');
		self::assertSame('authorized', $xpath->evaluate('string(/d:multistatus/d:response/d:propstat/d:prop/d:displayname)'));
		self::assertSame(1, $xpath->query('//d:propstat')->length);
	}

	public function testForeignXmlRootCannotBeReadAsADavResult(): void {
		$this->expectException(Problem::class);
		DavResponse::document(['status' => 207, 'body' => '<multistatus xmlns="https://foreign.test/"/>'], 'urn:ietf:params:xml:ns:carddav');
	}

	public function testMalformedPropertyStatusFailsClosed(): void {
		$this->expectException(Problem::class);
		DavResponse::document(['status' => 207, 'body' => '<d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:prop/><d:status>not-a-status</d:status></d:propstat></d:response></d:multistatus>'], 'urn:ietf:params:xml:ns:caldav');
	}

	public function testReadbackMustMatchTheApprovedBytesDespiteAValidEtag(): void {
		$this->expectException(Problem::class);
		DavResponse::verifyContent(['status' => 200, 'body' => 'another actor changed this', 'etag' => 'new-revision'], 'approved content');
	}

	public function testReadbackRequiresAValidNativeRevision(): void {
		$this->expectException(Problem::class);
		DavResponse::verifyContent(['status' => 200, 'body' => 'approved', 'etag' => ''], 'approved');
	}

	public function testExactReadbackReturnsOneStrongRevision(): void {
		self::assertSame('"revision"', DavResponse::verifyContent(['status' => 200, 'body' => 'approved', 'etag' => '"revision"'], 'approved'));
	}

	public static function invalidEtags(): array {
		return [['*'], ['W/"weak"'], ['"one", "two"'], ['"unterminated'], ["revision\r\nInjected: value"], ['revision with spaces']];
	}

	#[DataProvider('invalidEtags')]
	public function testConditionalHeadersRejectWildcardsListsAndInjection(string $etag): void {
		$this->expectException(Problem::class);
		NativeRequest::strongEtag($etag);
	}

	public static function foreignReferences(): array {
		return [
			['https://foreign.test/nextcloud/remote.php/dav/calendars/alice/private/event.ics'],
			['/nextcloud/remote.php/dav/calendars/bob/private/event.ics'],
			['/nextcloud/remote.php/dav/calendars/alice/other/event.ics'],
			['/nextcloud/remote.php/dav/calendars/alice/private/%2e%2e'],
			['/nextcloud/remote.php/dav/calendars/alice/private/event.ics?outside=1'],
		];
	}

	#[DataProvider('foreignReferences')]
	public function testObjectReferencesRemainInTheResolvedNamespace(string $href): void {
		$this->expectException(Problem::class);
		DavReference::object('https://cloud.test/nextcloud', 'calendar', 'alice', 'private', $href);
	}

	public function testReferenceSupportsNativeInstallationSubpathsAndEncodedNames(): void {
		self::assertSame('annual review.ics', DavReference::object('https://cloud.test/nextcloud', 'tasks', 'alice', 'private', '/nextcloud/remote.php/dav/calendars/alice/private/annual%20review.ics'));
	}

	public function testContactSearchKeepsQueryTextOutOfXmlSyntaxAndUsesOwnedRoutes(): void {
		$request = NativeRequest::findAddressbookObjects('alice', 'colleagues', 'Taylor & <other>');
		self::assertSame('/remote.php/dav/addressbooks/users/alice/colleagues/', $request->path);
		self::assertStringContainsString('Taylor &amp; &lt;other&gt;', $request->body);
		self::assertSame('REPORT', $request->method);
	}
}
