<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\MailMime;
use OCA\FadenstackAgent\Service\Problem;
use PHPUnit\Framework\TestCase;

final class MailMimeTest extends TestCase {
	public function testNativePlainDraftAndAiMarkerRemainReadableWithoutHtmlRendering(): void {
		$value = MailMime::parse("From: Alice <alice@example.invalid>\r\nSubject: Synthetic test\r\nX-AI-Generated: 1\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nExact body.\r\n");
		self::assertSame('Exact body.', $value['bodyPlain']);
		self::assertNull($value['bodyHtml']);
		self::assertTrue($value['isAiGenerated']);
		self::assertSame([['label' => 'Alice', 'email' => 'alice@example.invalid']], $value['from']);
	}
	public function testMIMERecipientsReplacePotentiallyStaleEnvelopeMetadata(): void {
		$value = MailMime::parse("From: Alice <alice@example.invalid>\r\nTo: Bob <bob@example.invalid>\r\nCc: c@example.invalid\r\nBcc: d@example.invalid\r\nReply-To: reply@example.invalid\r\nSubject: Test\r\n\r\nBody\r\n");
		self::assertSame('Bob', $value['to'][0]['label']);
		self::assertSame('c@example.invalid', $value['cc'][0]['email']);
		self::assertSame('d@example.invalid', $value['bcc'][0]['email']);
		self::assertSame(['Reply-To'], $value['unsupportedHeaders']);
	}
	public function testRepeatedAddressHeadersAreRejected(): void {
		$this->expectException(Problem::class);
		MailMime::parse("To: a@example.invalid\r\nTo: b@example.invalid\r\n\r\nBody\r\n");
	}
	public function testAttachmentHashUsesDecodedBytes(): void {
		$raw = "Subject: Test\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=abc123\r\n\r\n--abc123\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nKeep body\r\n--abc123\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=synthetic.bin\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode("\x00Exact attachment\xff") . "\r\n--abc123--\r\n";
		$value = MailMime::parse($raw);
		self::assertSame('Keep body', $value['bodyPlain']);
		self::assertSame(hash('sha256', "\x00Exact attachment\xff"), $value['attachments'][0]['sha256']);
		self::assertSame('synthetic.bin', $value['attachments'][0]['fileName']);
	}
	public function testOversizeMessagesFailClosed(): void {
		$this->expectException(Problem::class);
		MailMime::parse(str_repeat('x', 393217));
	}
}
