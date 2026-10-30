<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;

/** Bounded DAV XML; only successful property blocks can contribute data. */
final class DavResponse {
	public static function document(array $response, string $componentNamespace): \DOMXPath {
		if (($response['status'] ?? null) !== 207 || !is_string($response['body'] ?? null)
			|| strlen($response['body']) > NativeApiBridge::MAX_BYTES || str_contains(strtoupper($response['body']), '<!DOCTYPE')) {
			throw new Problem('The native DAV response is invalid.', 503);
		}
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		try {
			$loaded = $document->loadXML($response['body'], LIBXML_NONET);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		if (!$loaded || $document->documentElement?->localName !== 'multistatus' || $document->documentElement?->namespaceURI !== 'DAV:') {
			throw new Problem('The native DAV response is invalid.', 503);
		}
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('d', 'DAV:');
		$xpath->registerNamespace('c', $componentNamespace);
		foreach (iterator_to_array($xpath->query('/d:multistatus/d:response/d:propstat')) as $property) {
			$status = trim((string)$xpath->evaluate('string(d:status)', $property));
			if (!preg_match('~^HTTP/[0-9.]+ ([1-5][0-9]{2})(?:\s.*)?$~D', $status, $match)) {
				throw new Problem('The native DAV property status is invalid.', 503);
			}
			if ($match[1] !== '200') {
				$property->parentNode->removeChild($property);
			}
		}
		return $xpath;
	}

	public static function etag(string $etag): string {
		try {
			return NativeRequest::strongEtag($etag);
		} catch (Problem) {
			throw new Problem('The native DAV revision is invalid.', 503);
		}
	}

	public static function verifyContent(array $response, string $expected): string {
		if (($response['status'] ?? null) !== 200 || !is_string($response['body'] ?? null)
			|| !hash_equals(hash('sha256', $expected), hash('sha256', $response['body']))) {
			throw new Problem('The native content does not match the approved result. Inspect the outcome before another write.', 409);
		}
		return self::etag($response['etag'] ?? '');
	}
}
