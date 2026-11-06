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
		public readonly bool $emptyHistory = false,
	) {
	}

	public static function segment(string $value): string {
		if ($value === '' || strlen($value) > 255 || $value === '.' || $value === '..' || preg_match('~[\x00-\x1f\x7f/\\\\]~', $value)) {
			throw new Problem('A canonical native identifier is required.', 400);
		}
		return rawurlencode($value);
	}

	public static function strongEtag(string $etag): string {
		$etag = trim($etag);
		// One concrete opaque entity tag, never a list, wildcard or header value.
		if (!preg_match('/^(?:"[\x21\x23-\x7e]+"|[a-zA-Z0-9._:-]+)$/D', $etag)
			|| str_starts_with($etag, 'W/') || str_starts_with($etag, 'w/')) {
			throw new Problem('A strong native ETag is required.', 412);
		}
		if (!str_starts_with($etag, '"')) {
			$etag = '"' . $etag . '"';
		}
		return $etag;
	}

	/** Closed Mail 5.13 routes. IDs are resolved under account scope by the caller. */
	public static function mail(string $actor, string $operation, int $id = 0, array $payload = []): self {
		if ($id < 0 || $id > 999999999999999 || (!in_array($operation, ['accounts', 'create_draft', 'stage_outbox'], true) && $id < 1)) throw new Problem('A positive native Mail identifier is required.', 400);
		[$method, $path] = match ($operation) {
			'accounts' => ['GET', '/apps/mail/api/accounts'],
			'account' => ['GET', '/apps/mail/api/accounts/' . $id],
			'mailboxes' => ['GET', '/apps/mail/api/mailboxes?accountId=' . $id],
			'sync' => ['POST', '/apps/mail/api/mailboxes/' . $id . '/sync'],
			'messages' => ['GET', '/apps/mail/api/messages?mailboxId=' . $id . '&limit=20&view=singleton'],
			'message' => ['GET', '/apps/mail/api/messages/' . $id],
			'body' => ['GET', '/apps/mail/api/messages/' . $id . '/body'],
			'raw' => ['GET', '/ocs/v2.php/apps/mail/message/' . $id . '/raw?format=json'],
			'create_draft' => ['POST', '/apps/mail/api/drafts'],
			'update_draft' => ['PUT', '/apps/mail/api/drafts/' . $id],
			'persist_draft' => ['POST', '/apps/mail/api/drafts/move/' . $id],
			'stage_outbox' => ['POST', '/apps/mail/api/outbox'],
			'outbox' => ['GET', '/apps/mail/api/outbox/' . $id],
			'send_outbox' => ['POST', '/apps/mail/api/outbox/' . $id],
			'retire_draft' => ['DELETE', '/apps/mail/api/messages/' . $id],
			default => throw new Problem('Unsupported native Mail operation.', 400),
		};
		if (in_array($operation, ['create_draft', 'update_draft', 'stage_outbox'], true)) {
			if (array_diff(array_keys($payload), ['accountId', 'subject', 'bodyPlain', 'bodyHtml', 'editorBody', 'isHtml', 'smimeSign', 'smimeEncrypt', 'to', 'cc', 'bcc', 'attachments', 'aliasId', 'inReplyToMessageId', 'draftId', 'smimeCertificateId', 'sendAt', 'requestMdn', 'isPgpMime', 'isAiGenerated'])
				|| ($payload['sendAt'] ?? null) !== null) throw new Problem('Only an unscheduled native Mail payload is supported.', 400);
			// Native Files attachment fallbacks would bypass provider source scope.
			foreach ($payload['attachments'] ?? [] as $attachment) {
				if (!is_array($attachment) || ($attachment['type'] ?? '') !== 'message-attachment'
					|| array_diff(array_keys($attachment), ['type', 'mailboxId', 'uid', 'id'])
					|| !is_int($attachment['mailboxId'] ?? null) || $attachment['mailboxId'] < 1
					|| !is_int($attachment['uid'] ?? null) || $attachment['uid'] < 1
					|| !is_string($attachment['id'] ?? null) || !preg_match('/^[0-9]+(?:\.[0-9]+)*$/D', $attachment['id'])) throw new Problem('An authorized native MIME attachment is required.', 400);
			}
		} elseif ($payload !== []) throw new Problem('Unexpected native Mail arguments.', 400);
		if ($operation === 'sync') $payload = ['init' => true];
		return self::jsonRequest($actor, $method, $path, $payload);
	}

	/** Talk 25 public APIs. Reads never update markers, presence or notifications. */
	public static function talk(string $actor, string $operation, string $token, array $payload = []): self {
		if (!preg_match('/^[a-z0-9]{4,30}$/D', $token)) throw new Problem('A native Talk room token is required.', 400);
		[$method, $path] = match ($operation) {
			'room' => ['GET', '/ocs/v2.php/apps/spreed/api/v4/room/' . $token . '?format=json'],
			'participants' => ['GET', '/ocs/v2.php/apps/spreed/api/v4/room/' . $token . '/participants?format=json&includeStatus=false'],
			'history' => ['GET', '/ocs/v2.php/apps/spreed/api/v1/chat/' . $token . '?format=json&lookIntoFuture=0&limit=50&setReadMarker=0&noStatusUpdate=1&markNotificationsAsRead=0'],
			'send' => ['POST', '/ocs/v2.php/apps/spreed/api/v1/chat/' . $token . '?format=json'],
			default => throw new Problem('Unsupported native Talk operation.', 400),
		};
		if ($operation === 'send') {
			if (array_diff(array_keys($payload), ['message', 'replyTo', 'referenceId', 'silent'])
				|| !is_string($payload['message'] ?? null) || trim($payload['message']) === '' || mb_strlen($payload['message']) > 4000
				|| (isset($payload['replyTo']) && (!is_int($payload['replyTo']) || $payload['replyTo'] < 1))
				|| !is_string($payload['referenceId'] ?? null) || !preg_match('/^faden-[a-f0-9]{48}$/D', $payload['referenceId'])
				|| (isset($payload['silent']) && !is_bool($payload['silent']))) throw new Problem('Invalid native Talk message.', 400);
		} elseif ($payload !== []) throw new Problem('Unexpected native Talk read arguments.', 400);
		return self::jsonRequest($actor, $method, $path, $payload, $operation === 'history');
	}

	private static function jsonRequest(string $actor, string $method, string $path, array $payload, bool $emptyHistory = false): self {
		$body = $method === 'GET' ? '' : json_encode($payload ?: new \stdClass(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (strlen($body) > 131072) throw new Problem('Narrow the native communications payload.', 413);
		return new self($actor, $method, $path, $body, ['Accept' => 'application/json', 'Content-Type' => 'application/json'], $emptyHistory);
	}

	public static function mailAttachment(string $actor, int $messageId, string $part): self {
		if ($messageId < 1 || $messageId > 999999999999999 || !preg_match('/^[0-9]+(?:\.[0-9]+)*$/D', $part) || strlen($part) > 64) throw new Problem('A native MIME part is required.', 400);
		return new self($actor, 'GET', '/apps/mail/api/messages/' . $messageId . '/attachment/' . $part, '', ['Accept' => 'application/octet-stream']);
	}

	/** Public native app APIs only. The caller cannot choose a URL, method or header. */
	public static function structured(string $actor, string $provider, string $operation, int $collection, int $item = 0, int $stack = 0, array $payload = []): self {
		if ($collection < 1 || $collection > 999999999999999 || $item < 0 || $stack < 0) throw new Problem('Invalid native identifier.', 400);
		if ($provider === 'tables') {
			$base = '/apps/tables/api/1';
			[$method, $path] = match ($operation) {
				'metadata' => ['GET', "/tables/{$collection}"], 'columns' => ['GET', "/tables/{$collection}/columns"],
				'rows' => ['GET', "/tables/{$collection}/rows?limit=101&offset=0"],
				'row' => ['GET', "/rows/{$item}"], 'add_column' => ['POST', "/tables/{$collection}/columns"],
				'add_row' => ['POST', "/tables/{$collection}/rows"], 'update_row' => ['PUT', "/rows/{$item}"],
				'delete_row' => ['DELETE', "/rows/{$item}"], default => throw new Problem('Unsupported Tables operation.', 400),
			};
			if (in_array($operation, ['row', 'update_row', 'delete_row'], true) && $item < 1) throw new Problem('A native row is required.', 400);
		} elseif ($provider === 'deck') {
			$base = '/apps/deck/api/v1.0';
			$card = "/boards/{$collection}/stacks/{$stack}/cards/{$item}";
			if ($operation === 'move') {
				$destination = $payload['stackId'] ?? null;
				if (!is_int($destination) || $destination < 1 || $destination > 999999999999999) throw new Problem('A native destination stack is required.', 400);
				// Native route parameters override JSON parameters. The public reorder
				// route must name the destination, not silently reorder the old stack.
				$card = "/boards/{$collection}/stacks/{$destination}/cards/{$item}";
			}
			[$method, $path] = match ($operation) {
				'metadata' => ['GET', "/boards/{$collection}"], 'stacks' => ['GET', "/boards/{$collection}/stacks"],
				'card' => ['GET', $card], 'create' => ['POST', "/boards/{$collection}/stacks/{$stack}/cards"],
				'patch' => ['PUT', $card], 'move' => ['PUT', $card . '/reorder'],
				'assign' => ['PUT', $card . '/assignUser'], 'label' => ['PUT', $card . '/assignLabel'],
				default => throw new Problem('Unsupported Deck operation.', 400),
			};
			if (!in_array($operation, ['metadata', 'stacks'], true) && ($stack < 1 || ($operation !== 'create' && $item < 1))) throw new Problem('A native stack and card are required.', 400);
		} else throw new Problem('Unsupported native provider.', 400);
		$body = $method === 'GET' || $method === 'DELETE' ? '' : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (strlen($body) > 65536) throw new Problem('Narrow the native mutation.', 413);
		return new self($actor, $method, $base . $path, $body, ['Accept' => 'application/json', 'Content-Type' => 'application/json']);
	}

	/** Fixed same-instance Files operation; only our authenticated guard consumes this ticket. */
	public static function fileOperation(string $actor, string $operation, string $source, string $base, string $parent, string $name,
		string $etag, string $ticket, string $binding, #[\SensitiveParameter] string $token): self {
		if (!in_array($operation, ['copy', 'move'], true) || !preg_match('/^[a-f0-9]{48}$/D', $ticket)) throw new Problem('Invalid native Files operation.', 400);
		$encode = static fn (string $path): string => implode('/', array_map(self::segment(...), explode('/', trim($path, '/'))));
		$prefix = '/remote.php/dav/files/' . self::segment($actor) . '/';
		return new self($actor, strtoupper($operation), $prefix . $encode($source), '', [
			'Destination' => $base . $prefix . ($parent === '/' || $parent === '' ? '' : $encode($parent) . '/') . self::segment($name),
			'Overwrite' => 'F', 'If-Match' => self::strongEtag($etag), 'X-Fadenstack-File-Ticket' => $ticket,
			'X-Fadenstack-Session' => $binding, 'X-Fadenstack-Agent-Token' => $token,
		]);
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

	public static function calendarCollection(string $actor, string $calendar): self {
		$body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:resourcetype/><d:current-user-privilege-set/><c:supported-calendar-component-set/></d:prop></d:propfind>';
		return new self($actor, 'PROPFIND', '/remote.php/dav/calendars/' . self::segment($actor) . '/' . self::segment($calendar) . '/', $body,
			['Depth' => '0', 'Content-Type' => 'application/xml; charset=utf-8']);
	}

	public static function calendarObject(string $actor, string $calendar, string $filename): self {
		return new self($actor, 'GET', '/remote.php/dav/calendars/' . self::segment($actor) . '/' . self::segment($calendar) . '/' . self::segment($filename), '', []);
	}

	public static function createCalendarObject(string $actor, string $calendar, string $filename, string $icsData): self {
		return new self($actor, 'PUT', '/remote.php/dav/calendars/' . self::segment($actor) . '/' . self::segment($calendar) . '/' . self::segment($filename), $icsData,
			['Content-Type' => 'text/calendar; charset=utf-8', 'If-None-Match' => '*']);
	}

	public static function updateCalendarObject(string $actor, string $calendar, string $filename, string $icsData, string $etag): self {
		return new self($actor, 'PUT', '/remote.php/dav/calendars/' . self::segment($actor) . '/' . self::segment($calendar) . '/' . self::segment($filename), $icsData,
			['Content-Type' => 'text/calendar; charset=utf-8', 'If-Match' => self::strongEtag($etag)]);
	}

	public static function deleteCalendarObject(string $actor, string $calendar, string $filename, string $etag): self {
		return new self($actor, 'DELETE', '/remote.php/dav/calendars/' . self::segment($actor) . '/' . self::segment($calendar) . '/' . self::segment($filename), '',
			['If-Match' => self::strongEtag($etag)]);
	}

	public static function addressbookCollections(string $actor): self {
		$body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:prop><d:displayname/><d:resourcetype/><d:current-user-privilege-set/><d:owner/><d:acl/></d:prop></d:propfind>';
		return new self($actor, 'PROPFIND', '/remote.php/dav/addressbooks/users/' . self::segment($actor) . '/', $body,
			['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8']);
	}

	public static function addressbookObjects(string $actor, string $book): self {
		$body = '<?xml version="1.0"?><card:addressbook-query xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:prop><d:getetag/><card:address-data/></d:prop><card:filter/></card:addressbook-query>';
		return new self($actor, 'REPORT', '/remote.php/dav/addressbooks/users/' . self::segment($actor) . '/' . self::segment($book) . '/', $body,
			['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8']);
	}

	public static function addressbookCollection(string $actor, string $book): self {
		$body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav"><d:prop><d:resourcetype/><d:current-user-privilege-set/></d:prop></d:propfind>';
		return new self($actor, 'PROPFIND', '/remote.php/dav/addressbooks/users/' . self::segment($actor) . '/' . self::segment($book) . '/', $body,
			['Depth' => '0', 'Content-Type' => 'application/xml; charset=utf-8']);
	}

	public static function findAddressbookObjects(string $actor, string $book, string $query): self {
		if ($query === '' || strlen($query) > 80 || preg_match('/[\x00-\x1f\x7f]/', $query)) {
			throw new Problem('Use bounded contact search text.', 400);
		}
		$text = htmlspecialchars($query, ENT_XML1 | ENT_QUOTES, 'UTF-8');
		$body = '<?xml version="1.0"?><c:addressbook-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav"><d:prop><d:getetag/><c:address-data/></d:prop><c:filter><c:prop-filter name="FN"><c:text-match collation="i;unicode-casemap" match-type="contains">' . $text . '</c:text-match></c:prop-filter></c:filter></c:addressbook-query>';
		return new self($actor, 'REPORT', '/remote.php/dav/addressbooks/users/' . self::segment($actor) . '/' . self::segment($book) . '/', $body,
			['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8']);
	}

	public static function addressbookObject(string $actor, string $book, string $filename): self {
		return new self($actor, 'GET', '/remote.php/dav/addressbooks/users/' . self::segment($actor) . '/' . self::segment($book) . '/' . self::segment($filename), '', []);
	}

	public static function createAddressbookObject(string $actor, string $book, string $filename, string $vcardData): self {
		return new self($actor, 'PUT', '/remote.php/dav/addressbooks/users/' . self::segment($actor) . '/' . self::segment($book) . '/' . self::segment($filename), $vcardData,
			['Content-Type' => 'text/vcard; charset=utf-8', 'If-None-Match' => '*']);
	}

	public static function updateAddressbookObject(string $actor, string $book, string $filename, string $vcardData, string $etag): self {
		return new self($actor, 'PUT', '/remote.php/dav/addressbooks/users/' . self::segment($actor) . '/' . self::segment($book) . '/' . self::segment($filename), $vcardData,
			['Content-Type' => 'text/vcard; charset=utf-8', 'If-Match' => self::strongEtag($etag)]);
	}

	public static function deleteAddressbookObject(string $actor, string $book, string $filename, string $etag): self {
		return new self($actor, 'DELETE', '/remote.php/dav/addressbooks/users/' . self::segment($actor) . '/' . self::segment($book) . '/' . self::segment($filename), '',
			['If-Match' => self::strongEtag($etag)]);
	}
}
