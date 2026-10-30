<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;

/** First bounded provider: native, current-session Calendar discovery and reads. */
final class CalendarProvider {
	public function __construct(
		private NativeApiBridge $bridge,
		private ProviderPolicy $policy,
	) {
	}

	private function document(array $response): \DOMXPath {
		if ($response['status'] !== 207 || str_contains(strtoupper($response['body']), '<!DOCTYPE')) {
			throw new Problem('The native Calendar response is invalid.', 503);
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
			throw new Problem('The native Calendar response is invalid.', 503);
		}
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('d', 'DAV:');
		$xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
		return $xpath;
	}

	private function text(\DOMXPath $xpath, string $expression, \DOMNode $node): string {
		return (string)$xpath->evaluate('string(' . $expression . ')', $node);
	}

	private function collectionFromHref(string $href, string $actor): ?string {
		$parts = parse_url($href);
		if ($parts === false || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
			throw new Problem('Invalid native Calendar reference.', 503);
		}
		$base = parse_url($this->bridge->base());
		if (isset($parts['host']) && (($parts['scheme'] ?? '') !== $base['scheme'] || $parts['host'] !== $base['host'] || ($parts['port'] ?? null) !== ($base['port'] ?? null))) {
			throw new Problem('Foreign native Calendar reference.', 503);
		}
		$prefix = ($base['path'] ?? '') . '/remote.php/dav/calendars/' . rawurlencode($actor) . '/';
		$path = $parts['path'] ?? '';
		if ($path === $prefix) {
			return null;
		}
		if (!str_starts_with($path, $prefix)) {
			throw new Problem('Foreign native Calendar reference.', 503);
		}
		$id = rawurldecode(rtrim(substr($path, strlen($prefix)), '/'));
		NativeRequest::segment($id);
		return $id;
	}

	public function collections(): array {
		$policy = $this->policy->calendar();
		$this->policy->assertDisclosure($policy);
		$xpath = $this->document($this->bridge->send(NativeRequest::calendarCollections($policy['actor'])));
		$result = [];
		foreach ($xpath->query('/d:multistatus/d:response') as $response) {
			$id = $this->collectionFromHref($this->text($xpath, 'd:href', $response), $policy['actor']);
			if ($id === null || !in_array($id, $policy['collections'], true)) {
				continue;
			}
			$property = $xpath->query('d:propstat[contains(d:status," 200 ")]/d:prop', $response)->item(0);
			if ($property === null || !$xpath->query('d:resourcetype/c:calendar', $property)->length
				|| !$xpath->query('c:supported-calendar-component-set/c:comp[@name="VEVENT"]', $property)->length) {
				continue;
			}
			$ref = 'nextcloud:calendar:' . rtrim(strtr(base64_encode($id), '+/', '-_'), '=');
			$result[] = ['provider' => 'calendar', 'resourceId' => $ref, 'collectionId' => $id,
				'revision' => hash('sha256', $property->C14N()),
				'title' => $this->text($xpath, 'd:displayname', $property), 'policyVersion' => $policy['policyVersion'],
				'sourceClass' => $policy['sourceClass'], 'audience' => 'unknown'];
		}
		$this->assertCurrent($policy);
		return ['provider' => 'calendar', 'apiVersion' => 'caldav-v1', 'policyVersion' => $policy['policyVersion'],
			'collections' => $result, 'completeWithinConfiguredScope' => true, 'writesEnabled' => false];
	}

	public function capabilities(): array {
		try {
			$collections = $this->collections();
		} catch (Problem $error) {
			if ($error->status === 403) {
				return [];
			}
			throw $error;
		}
		if (!$collections['collections']) {
			return [];
		}
		return [['provider' => 'calendar', 'apiVersion' => 'caldav-v1', 'policyVersion' => $collections['policyVersion'],
			'enabled' => true, 'tools' => ['nc_calendar_list', 'nc_calendar_read'], 'conflict' => 'read-only',
			'history' => 'not-applicable', 'refresh' => 'manual', 'canRead' => true, 'canWrite' => false]];
	}

	public function events(string $collectionId): array {
		$policy = $this->policy->calendar();
		$this->policy->assertDisclosure($policy);
		if (!in_array($collectionId, $policy['collections'], true)) {
			throw new Problem('The Calendar collection is outside the configured scope.', 404);
		}
		$xpath = $this->document($this->bridge->send(NativeRequest::calendarObjects($policy['actor'], $collectionId, 'VEVENT')));
		$result = [];
		foreach ($xpath->query('/d:multistatus/d:response') as $response) {
			$properties = $xpath->query('d:propstat[contains(d:status," 200 ")]/d:prop', $response)->item(0);
			if ($properties === null) {
				continue;
			}
			$href = $this->text($xpath, 'd:href', $response);
			$path = parse_url($href, PHP_URL_PATH);
			if (!is_string($path)) {
				throw new Problem('Invalid native event reference.', 503);
			}
			$filename = rawurldecode(basename($path));
			NativeRequest::segment($filename);
			$prefix = substr($href, 0, strrpos($href, '/') + 1);
			if ($this->collectionFromHref($prefix, $policy['actor']) !== $collectionId) {
				throw new Problem('Foreign native event reference.', 503);
			}
			$raw = $this->text($xpath, 'c:calendar-data', $properties);
			$etag = $this->text($xpath, 'd:getetag', $properties);
			if ($raw === '' || $etag === '' || count($result) >= 50 || strlen($raw) > 65536) {
				throw new Problem('Narrow the Calendar request.', 413);
			}
			$class = CalendarClassification::sourceClass($raw, $policy['sourceClass']);
			$this->policy->assertDisclosure($policy, $class);
			$result[] = ['provider' => 'calendar', 'collectionId' => $collectionId, 'filename' => $filename,
				'resourceId' => 'nextcloud:calendar:' . rtrim(strtr(base64_encode($collectionId . "\0" . $filename), '+/', '-_'), '='),
				'revision' => $etag, 'content' => $raw, 'contentProfile' => 'icalendar-xml-normalized',
				'sourceClass' => $class, 'audience' => 'unknown', 'policyVersion' => $policy['policyVersion']];
		}
		$this->assertCurrent($policy);
		return ['provider' => 'calendar', 'policyVersion' => $policy['policyVersion'], 'events' => $result, 'complete' => true];
	}

	private function assertCurrent(array $policy): void {
		if ($this->policy->calendar()['policyVersion'] !== $policy['policyVersion']) {
			throw new Problem('The provider policy changed. Start a new request.', 409);
		}
	}
}
