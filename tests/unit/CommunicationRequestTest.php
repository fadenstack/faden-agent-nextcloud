<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\NativeRequest;
use OCA\FadenstackAgent\Service\Problem;
use PHPUnit\Framework\TestCase;

final class CommunicationRequestTest extends TestCase {
	public function testTalkReadsDoNotChangePresenceOrReadPointers(): void {
		$request = NativeRequest::talk('alice', 'history', 'abcd1234');
		self::assertSame('GET', $request->method);
		parse_str(parse_url($request->path, PHP_URL_QUERY), $query);
		self::assertSame('0', $query['setReadMarker']);
		self::assertSame('0', $query['markNotificationsAsRead']);
		self::assertSame('1', $query['noStatusUpdate']);
		self::assertSame('0', $query['lookIntoFuture']);
		self::assertSame('50', $query['limit']);
		self::assertStringContainsString('includeStatus=false', NativeRequest::talk('alice', 'participants', 'abcd1234')->path);
	}

	public function testPublicTalkRouteCannotBecomeAProxy(): void {
		foreach (['../bob', 'https://other.test', 'room?setReadMarker=1', '%61bcd', 'ABCD', 'abcd/1234'] as $token) {
			try {
				NativeRequest::talk('alice', 'history', $token);
				self::fail('Accepted an unsafe room token.');
			} catch (Problem $error) {
				self::assertSame(400, $error->status);
			}
		}
	}

	public function testTalkSendUsesCorrelationAndExplicitNotificationChoice(): void {
		$body = ['message' => 'Synthetic message', 'replyTo' => 42, 'referenceId' => 'faden-' . str_repeat('a', 48), 'silent' => false];
		$request = NativeRequest::talk('alice', 'send', 'abcd1234', $body);
		self::assertSame('POST', $request->method);
		self::assertSame($body, json_decode($request->body, true));
	}

	public function testTalkReadCannotCarryEffectParameters(): void {
		$this->expectException(Problem::class);
		NativeRequest::talk('alice', 'history', 'abcd1234', ['setReadMarker' => 1]);
	}

	public function testMailNeverQueuesAScheduledSend(): void {
		$this->expectException(Problem::class);
		NativeRequest::mail('alice', 'stage_outbox', 0, ['accountId' => 1, 'sendAt' => time()]);
	}

	public function testMailRefusesNativeFilesAttachmentFallback(): void {
		$this->expectException(Problem::class);
		NativeRequest::mail('alice', 'stage_outbox', 0, ['accountId' => 1, 'attachments' => [['fileName' => '/Other scope/secret.txt']]]);
	}

	public function testMailPreservesTypedMimeReferencesWithoutDownloadingExternalUrls(): void {
		$body = ['accountId' => 1, 'sendAt' => null, 'isAiGenerated' => true, 'attachments' => [['type' => 'message-attachment', 'mailboxId' => 2, 'uid' => 3, 'id' => '2.1']]];
		$request = NativeRequest::mail('alice', 'stage_outbox', 0, $body);
		self::assertSame($body, json_decode($request->body, true));
		self::assertSame('/apps/mail/api/outbox', $request->path);
		self::assertSame('/apps/mail/api/outbox/4', NativeRequest::mail('alice', 'send_outbox', 4)->path);
	}

	public function testMailReadHasABoundedSingletonQueryAndNoSeenFlag(): void {
		$request = NativeRequest::mail('alice', 'messages', 7);
		self::assertSame('/apps/mail/api/messages?mailboxId=7&limit=20&view=singleton', $request->path);
		self::assertSame('', $request->body);
		self::assertSame('{"init":true}', NativeRequest::mail('alice', 'sync', 7)->body);
	}
}
