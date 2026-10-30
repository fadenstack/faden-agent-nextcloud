<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;

/** Closed route constructors. No browser/model-supplied URL, method or headers. */
final class NativeRequest {
	private function __construct(
		public readonly string $actor,
		public readonly string $method,
		public readonly string $path,
		public readonly string $body,
		public readonly array $headers,
	) {
	}

	public static function segment(string $value): string {
		if ($value === '' || strlen($value) > 255 || $value === '.' || $value === '..' || preg_match('~[\x00-\x1f\x7f/\\\\]~', $value)) {
			throw new Problem('A canonical native identifier is required.', 400);
		}
		return rawurlencode($value);
	}

	public static function calendarCollections(string $actor): self {
		$body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/" xmlns:o="http://owncloud.org/ns"><d:prop><d:displayname/><d:resourcetype/><d:current-user-privilege-set/><d:owner/><d:acl/><c:supported-calendar-component-set/><o:calendar-enabled/><a:calendar-color/></d:prop></d:propfind>';
		return new self($actor, 'PROPFIND', '/remote.php/dav/calendars/' . self::segment($actor) . '/', $body,
			['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8']);
	}

	public static function calendarObjects(string $actor, string $calendar, string $component): self {
		if (!in_array($component, ['VEVENT', 'VTODO'], true)) {
			throw new Problem('Unsupported calendar component.', 400);
		}
		$body = '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="' . $component . '"/></c:comp-filter></c:filter></c:calendar-query>';
		return new self($actor, 'REPORT', '/remote.php/dav/calendars/' . self::segment($actor) . '/' . self::segment($calendar) . '/', $body,
			['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8']);
	}

	public static function calendarObject(string $actor, string $calendar, string $filename): self {
		return new self($actor, 'GET', '/remote.php/dav/calendars/' . self::segment($actor) . '/' . self::segment($calendar) . '/' . self::segment($filename), '', []);
	}
}
