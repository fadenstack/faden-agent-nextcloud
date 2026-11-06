<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;
use ZBateson\MailMimeParser\Message;

/** Bounded, inert MIME parsing. No rendering, URL fetch, decryption or file writes. */
final class MailMime {
	public static function text(?string $value): ?string {
		return $value === null ? null : rtrim(str_replace(["\r\n", "\r"], "\n", $value), "\n");
	}
	public static function parse(string $raw): array {
		if (strlen($raw) > 393216 || substr_count($raw, "\n") > 8192 || preg_match_all('/boundary\s*=/i', $raw) > 32 || !str_contains($raw, "\n")) {
			throw new Problem('Use a bounded message smaller than 384 KiB.', 413);
		}
		if (!class_exists(Message::class)) {
			$loader = __DIR__ . '/../../vendor/autoload.php';
			if (!is_file($loader)) {
				throw new Problem('The packaged native MIME reader is unavailable.', 503);
			}
			require_once $loader;
		}
		try {
			$message = Message::from($raw, false);
			$addresses = [];
			foreach (['from', 'to', 'cc', 'bcc'] as $field) {
				if ($message->getHeader($field, 1) !== null) {
					throw new Problem('Ambiguous repeated address headers require the native editor.', 403);
				}
				$header = $message->getHeader($field);
				$addresses[$field] = [];
				if ($header !== null) {
					if (!method_exists($header, 'getAddresses')) {
						throw new Problem('Unverified native address header.', 503);
					}
					foreach ($header->getAddresses() as $address) {
						$addresses[$field][] = ['label' => $address->getName() ?: $address->getEmail(), 'email' => $address->getEmail()];
					}
				}
				if (count($addresses[$field]) > 50) {
					throw new Problem('Narrow the native recipients.', 413);
				}
			}
			if ($message->getTextPartCount() > 1 || $message->getHtmlPartCount() > 1 || count($message->getAllAttachmentParts()) > 20) {
				throw new Problem('This MIME structure requires the native Mail editor.', 413);
			}
			$type = strtolower($message->getContentType());
			if (str_contains($type, 'encrypted') || str_contains($type, 'signed') || $message->getSignaturePart() !== null) {
				throw new Problem('Encrypted or signed messages require the native Mail editor.', 403);
			}
			$plain = self::text($message->getTextContent());
			$html = self::text($message->getHtmlContent());
			if (($plain !== null && (strlen($plain) > 65536 || !mb_check_encoding($plain, 'UTF-8'))) || ($html !== null && (strlen($html) > 65536 || !mb_check_encoding($html, 'UTF-8')))) {
				throw new Problem('Narrow the native message body.', 413);
			}
			$attachments = [];
			foreach ($message->getAllAttachmentParts() as $part) {
				$stream = $part->getBinaryContentStream();
				$content = $stream?->getContents() ?? '';
				if (strlen($content) > 262144 || strlen($part->getFilename() ?? '') > 255) {
					throw new Problem('Narrow the native attachment.', 413);
				}
				$mime = strtolower($part->getContentType());
				// Native Mail/Horde canonicalizes line endings of text attachments.
				// Compute the final native bytes BEFORE approval and verify after save.
				$native = str_starts_with($mime, 'text/') ? str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $content)) : $content;
				$attachments[] = ['fileName' => $part->getFilename(), 'mime' => $mime, 'size' => strlen($content), 'sha256' => hash('sha256', $content),
					'nativeSize' => strlen($native), 'nativeSha256' => hash('sha256', $native), 'inline' => $part->getContentId() !== null || $part->getContentDisposition() === 'inline'];
			}
			$unsupportedHeaders = [];
			foreach (['Reply-To', 'Disposition-Notification-To', 'Return-Receipt-To', 'References', 'Resent-From', 'Resent-To'] as $header) {
				if ($message->getHeader($header) !== null) {
					$unsupportedHeaders[] = $header;
				}
			}
			return [...$addresses, 'unsupportedHeaders' => $unsupportedHeaders, 'subject' => $message->getSubject() ?? '', 'bodyPlain' => $plain, 'bodyHtml' => $html, 'attachments' => $attachments,
				'isAiGenerated' => $message->getHeaderValue('X-AI-Generated') === '1', 'inReplyToMessageId' => $message->getHeaderValue('In-Reply-To'), 'revision' => hash('sha256', $raw)];
		} catch (Problem $error) {
			throw $error;
		} catch (\Throwable) {
			throw new Problem('The native MIME message could not be verified.', 503);
		}
	}
}
