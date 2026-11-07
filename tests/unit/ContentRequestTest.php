<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\NativeRequest;
use OCA\FadenstackAgent\Service\Problem;
use PHPUnit\Framework\TestCase;

final class ContentRequestTest extends TestCase {
	public function testOnlyClosedNativeOperationsCanBeDispatched(): void {
		foreach (['https://other.test', '../mail', 'create_share', 'delete_all', 'configure_proxy'] as $operation) {
			try {
				NativeRequest::content('alice', 'forms', $operation, 1);
				self::fail('Accepted a non-content route.');
			} catch (Problem $error) {
				self::assertSame(400, $error->status);
			}
		}
	}
	public function testNativeCookieRoutesAvoidStoredBookmarkCredentials(): void {
		$request = NativeRequest::content('alice', 'bookmarks', 'create', 0, 0, ['url' => 'https://example.invalid/a', 'title' => 'A', 'tags' => ['x']]);
		self::assertSame('/apps/bookmarks/bookmark', $request->path);
		self::assertSame('POST', $request->method);
		self::assertArrayNotHasKey('Authorization', $request->headers);
		self::assertSame('/apps/bookmarks/bookmark?limit=51&page=0', NativeRequest::content('alice', 'bookmarks', 'list')->path);
	}
	public function testNativePageDeletionUsesTrashRouteAndNeverPermanentDeletion(): void {
		$request = NativeRequest::content('alice', 'collectives', 'delete_page', 1, 2);
		self::assertSame('DELETE', $request->method);
		self::assertSame('/ocs/v2.php/apps/collectives/api/v1.0/collectives/1/pages/2', $request->path);
	}
	public function testCreateCannotIntroduceBookmarkFolderOrNativeShareTokens(): void {
		$this->expectException(Problem::class);
		NativeRequest::content('alice', 'bookmarks', 'create', 0, 0, ['url' => 'https://example.invalid', 'folders' => [42], 'token' => 'other-user']);
	}
	public function testReadCannotCarryEffectFields(): void {
		$this->expectException(Problem::class);
		NativeRequest::content('alice', 'notes', 'list', 0, 0, ['content' => 'replace']);
	}
	public function testExistingNoteUpdatesAreOutsideTheClosedTransport(): void {
		$this->expectException(Problem::class);
		NativeRequest::content('alice', 'notes', 'update', 0, 1, ['content' => 'replace']);
	}
	public function testFormsHasNoActivationSharingOrSubmissionRetryRoute(): void {
		foreach (['activate', 'share', 'delete_submission', 'resume'] as $operation) {
			try {
				NativeRequest::content('alice', 'forms', $operation, 1);
				self::fail('Unexpected form effect route.');
			} catch (Problem $error) {
				self::assertSame(400, $error->status);
			}
		}
	}
}
