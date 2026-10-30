<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\VerifiedReadback;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCP\ISession;
use OCP\IUserSession;

/** Native, current-session Calendar discovery, reads, and conditional mutations. */
final class CalendarProvider {
	private const KEY = 'fadenstack_calendar_state';

	public function __construct(
		private NativeApiBridge $bridge,
		private ProviderPolicy $policy,
		private Configuration $configuration,
		private ISession $session,
		private IUserSession $users,
		private JournalService $journal,
	) {
	}

	private function state(): array {
		$state = $this->session->get(self::KEY);
		if (!is_array($state) || ($state['binding'] ?? null) !== $this->session->getId()) {
			$state = ['binding' => $this->session->getId(), 'proposals' => []];
			$this->session->set(self::KEY, $state);
		}
		return $state;
	}

	private function setState(array $state): void {
		$this->session->set(self::KEY, $state);
	}

	private function journalIdentity(array $policy): JournalIdentity {
		$state = $this->state();
		$binding = hash('sha256', json_encode([$state['binding'], $this->configuration->get(), $policy['policyVersion']], JSON_THROW_ON_ERROR));
		return new JournalIdentity($policy['actor'], JournalIdentity::nativeScope(), $binding);
	}

	private function assertProposalIdentity(array $proposal, array $policy): JournalIdentity {
		$identity = $this->journalIdentity($policy);
		if (($proposal['identityScope'] ?? null) !== $identity->scopeHash || ($proposal['identitySession'] ?? null) !== $identity->sessionHash) {
			throw new Problem('The account, device, profile or scope changed. Review a new proposal.', 409);
		}
		return $identity;
	}

	private function proposal(string $id): array {
		$p = $this->state()['proposals'][$id] ?? null;
		if (!$p || $p['expires'] < time()) {
			throw new Problem('The proposal expired or was already consumed.', 410);
		}
		return $p;
	}

	private function assertPrivateEvent(string $ics): void {
		if ($ics === '' || strlen($ics) > 65536 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $ics)) {
			throw new Problem('Invalid iCalendar data.', 400);
		}
		if (preg_match('/(?:^|\r?\n)(?:ATTENDEE|ORGANIZER|METHOD)[;:]/i', $ics)) {
			throw new Problem('Attendee notifications and scheduling require a separate explicitly approved operation.', 400);
		}
		if (preg_match('/(?:^|\r?\n)(?:RRULE|RECURRENCE-ID|RDATE|EXDATE)[;:]/i', $ics)) {
			throw new Problem('Recurring resources and exceptions require a separate editor; no write sent.', 400);
		}
	}

	private function document(array $response): \DOMXPath {
		return DavResponse::document($response, 'urn:ietf:params:xml:ns:caldav');
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
			'collections' => $result, 'completeWithinConfiguredScope' => true, 'writesEnabled' => $policy['writesEnabled']];
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
		$canWrite = $collections['writesEnabled'] ?? false;
		$tools = ['nc_calendar_list', 'nc_calendar_read'];
		if ($canWrite) {
			$tools = [...$tools, 'nc_calendar_create', 'nc_calendar_patch', 'nc_calendar_delete'];
		}
		return [['provider' => 'calendar', 'apiVersion' => 'caldav-v1', 'policyVersion' => $collections['policyVersion'],
			'enabled' => true, 'tools' => $tools, 'conflict' => $canWrite ? 'conditional' : 'read-only',
			'history' => $canWrite ? 'audit-only' : 'not-applicable', 'refresh' => 'manual', 'canRead' => true, 'canWrite' => $canWrite]];
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

	public function event(string $collectionId, string $filename): array {
		$policy = $this->policy->calendar();
		$this->policy->assertDisclosure($policy);
		if (!in_array($collectionId, $policy['collections'], true)) {
			throw new Problem('The Calendar collection is outside the configured scope.', 404);
		}
		NativeRequest::segment($filename);
		$resp = $this->bridge->send(NativeRequest::calendarObject($policy['actor'], $collectionId, $filename));
		if ($resp['status'] !== 200) {
			throw new Problem('The calendar resource is not available.', 404);
		}
		$etag = trim($resp['etag'], '"');
		$this->policy->assertDisclosure($policy, CalendarClassification::sourceClass($resp['body'], $policy['sourceClass']));
		return ['etag' => $etag, 'content' => $resp['body']];
	}

	public function propose(string $operation, string $collectionId, string $filename, string $icsData, ?string $expectedEtag = null): array {
		$policy = $this->policy->calendar();
		$this->policy->assertDisclosure($policy);
		if (!($policy['writesEnabled'] ?? false)) {
			throw new Problem('Calendar mutations have been disabled by the administrator.', 403);
		}
		if (!in_array($collectionId, $policy['collections'], true)) {
			throw new Problem('The Calendar collection is outside the configured scope.', 404);
		}
		NativeRequest::segment($filename);
		if (!str_ends_with(strtolower($filename), '.ics')) {
			throw new Problem('A valid filename ending in .ics is required.', 400);
		}
		if (!in_array($operation, ['create', 'patch', 'delete'], true)) {
			throw new Problem('Unsupported calendar operation.', 400);
		}

		$identity = $this->journalIdentity($policy);

		if ($operation === 'create') {
			try {
				$existing = $this->bridge->send(NativeRequest::calendarObject($policy['actor'], $collectionId, $filename));
				if ($existing['status'] === 200) {
					throw new Problem('An event with this name already exists.', 409);
				}
			} catch (Problem $probe) {
				if ($probe->status !== 404) {
					throw $probe;
				}
			}
			$this->assertPrivateEvent($icsData);
			$expectedEtag = null;
		} elseif ($operation === 'patch') {
			if ($expectedEtag === null || $expectedEtag === '' || str_starts_with(trim($expectedEtag), 'W/') || str_starts_with(trim($expectedEtag), 'w/')) {
				throw new Problem('A strong native expectedEtag is required.', 400);
			}
			$current = $this->bridge->send(NativeRequest::calendarObject($policy['actor'], $collectionId, $filename));
			if ($current['status'] !== 200) {
				throw new Problem('The calendar resource is not available.', 404);
			}
			$currentEtag = DavResponse::etag($current['etag']);
			$expectedEtag = NativeRequest::strongEtag($expectedEtag);
			if ($currentEtag !== $expectedEtag) {
				throw new Problem('The calendar resource changed. Read it again before proposing a write.', 409);
			}
			$this->policy->assertDisclosure($policy, CalendarClassification::sourceClass($current['body'], $policy['sourceClass']));
			$this->assertPrivateEvent($current['body']);
			$this->assertPrivateEvent($icsData);
		} else { // delete
			if ($expectedEtag === null || $expectedEtag === '' || str_starts_with(trim($expectedEtag), 'W/') || str_starts_with(trim($expectedEtag), 'w/')) {
				throw new Problem('A strong native expectedEtag is required.', 400);
			}
			$current = $this->bridge->send(NativeRequest::calendarObject($policy['actor'], $collectionId, $filename));
			if ($current['status'] !== 200) {
				throw new Problem('The calendar resource is not available.', 404);
			}
			$currentEtag = DavResponse::etag($current['etag']);
			$expectedEtag = NativeRequest::strongEtag($expectedEtag);
			if ($currentEtag !== $expectedEtag) {
				throw new Problem('The calendar resource changed. Read it again before proposing a write.', 409);
			}
			$this->policy->assertDisclosure($policy, CalendarClassification::sourceClass($current['body'], $policy['sourceClass']));
			$this->assertPrivateEvent($current['body']);
			$icsData = '';
		}

		$p = [
			'id' => bin2hex(random_bytes(24)),
			'operation' => $operation,
			'toolName' => 'nc_calendar_' . $operation,
			'collectionId' => $collectionId,
			'filename' => $filename,
			'expectedEtag' => $expectedEtag,
			'content' => $icsData,
			'hash' => hash('sha256', $icsData),
			'target' => "Calendar {$collectionId}, event {$filename}",
			'expires' => time() + 600,
			'approved' => false,
			'identityScope' => $identity->scopeHash,
			'identitySession' => $identity->sessionHash,
		];

		$state = $this->state();
		$state['proposals'] = array_filter($state['proposals'] ?? [], fn ($item) => $item['expires'] > time());
		if (count($state['proposals']) >= 10) {
			throw new Problem('Too many pending proposals. Wait for them to expire.', 429);
		}
		$state['proposals'][$p['id']] = $p;
		$this->setState($state);

		return [
			'id' => $p['id'],
			'operation' => $p['operation'],
			'toolName' => $p['toolName'],
			'target' => $p['target'],
			'collectionId' => $collectionId,
			'filename' => $filename,
			'hash' => $p['hash'],
			'expectedEtag' => $expectedEtag,
			'expires' => $p['expires'],
		];
	}

	public function approve(string $id): array {
		$policy = $this->policy->calendar();
		if (!$policy['writesEnabled']) {
			throw new Problem('Calendar mutations have been disabled by the administrator.', 403);
		}
		$p = $this->proposal($id);
		$this->assertProposalIdentity($p, $policy);
		$state = $this->state();
		$state['proposals'][$id]['approved'] = true;
		$this->setState($state);
		return ['approved' => true];
	}

	public function commit(string $id): array {
		$policy = $this->policy->calendar();
		if (!$policy['writesEnabled']) {
			throw new Problem('Calendar mutations have been disabled by the administrator.', 403);
		}
		$p = $this->proposal($id);
		$identity = $this->assertProposalIdentity($p, $policy);
		if (!$p['approved']) {
			throw new Problem('This operation requires explicit approval.', 403);
		}

		$state = $this->state();
		unset($state['proposals'][$id]); // Single-use consumption
		$this->setState($state);

		$resource = new JournalResource('calendar', 'collection:' . $p['collectionId'] . '/event:' . $p['filename'], 'collection:' . $p['collectionId']);
		$journalEntry = $this->journal->prepare(
			$identity,
			$resource,
			$p['toolName'],
			['content' => $p['content']],
			[],
			['etag' => $p['expectedEtag']]
		);

		$this->journal->markDispatched($identity, $journalEntry['id'], $journalEntry['ticket']);

		try {
			if ($p['operation'] === 'create') {
				$req = NativeRequest::createCalendarObject($policy['actor'], $p['collectionId'], $p['filename'], $p['content']);
			} elseif ($p['operation'] === 'patch') {
				$req = NativeRequest::updateCalendarObject($policy['actor'], $p['collectionId'], $p['filename'], $p['content'], $p['expectedEtag']);
			} else {
				$req = NativeRequest::deleteCalendarObject($policy['actor'], $p['collectionId'], $p['filename'], $p['expectedEtag']);
			}
			$this->bridge->send($req);
		} catch (Problem $err) {
			if ($err->status === 412) {
				// The native condition refused this attempt. Settle only with a
				// fresh authorized revision; loss of readback remains uncertain.
				try {
					$current = $this->bridge->send(NativeRequest::calendarObject($policy['actor'], $p['collectionId'], $p['filename']));
					$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'unchanged', new VerifiedReadback(DavResponse::etag($current['etag'])));
				} catch (\Throwable) {
					$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'unknown');
				}
				throw new Problem('The calendar resource was modified by another actor.', 412);
			}
			$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'unknown');
			throw $err;
		}

		try {
			$verify = $this->bridge->send(NativeRequest::calendarObject($policy['actor'], $p['collectionId'], $p['filename']));
			if ($p['operation'] === 'delete') {
				throw new Problem('The calendar resource could not be confirmed deleted.', 409);
			}
			$finalEtag = trim(DavResponse::verifyContent($verify, $p['content']), '"');
			$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'verified', new VerifiedReadback($finalEtag));
			return [
				'status' => 'saved',
				'outcome' => 'verified',
				'etag' => $finalEtag,
				'target' => $p['target'],
				'collectionId' => $p['collectionId'],
				'filename' => $p['filename'],
			];
		} catch (Problem $verifyErr) {
			if ($p['operation'] === 'delete' && $verifyErr->status === 404) {
				$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'verified', new VerifiedReadback('absent'));
				return [
					'status' => 'deleted',
					'outcome' => 'verified',
					'target' => $p['target'],
					'collectionId' => $p['collectionId'],
					'filename' => $p['filename'],
				];
			}
			$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'unknown');
			throw $verifyErr;
		}
	}

	private function assertCurrent(array $policy): void {
		if ($this->policy->calendar()['policyVersion'] !== $policy['policyVersion']) {
			throw new Problem('The provider policy changed. Start a new request.', 409);
		}
	}
}
