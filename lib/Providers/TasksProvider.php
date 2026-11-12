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

/** Native, current-session Tasks discovery, reads, and conditional mutations via CalDAV VTODO. */
final class TasksProvider {
	private const KEY = 'fadenstack_tasks_state';
	private ?string $attemptId = null;

	public function __construct(
		private NativeApiBridge $bridge,
		private ProviderPolicy $policy,
		private Configuration $configuration,
		private ISession $session,
		private IUserSession $users,
		private JournalService $journal,
		private ProviderAuthority $authority,
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

	public function attemptId(): ?string {
		return $this->attemptId;
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

	private function mutationPolicy(): array {
		try {
			$policy = $this->policy->tasks();
			$this->policy->assertDisclosure($policy);
			if (!$policy['writesEnabled']) {
				throw new Problem('Tasks mutations have been disabled by the administrator.', 403);
			}
			return $policy;
		} catch (Problem $error) {
			$state = $this->state();
			$state['proposals'] = [];
			$this->setState($state);
			throw $error;
		}
	}

	private function assertPrivateTask(string $ics): void {
		if ($ics === '' || strlen($ics) > 65536 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $ics)) {
			throw new Problem('Invalid iCalendar task data.', 400);
		}
		if (preg_match('/(?:^|\r?\n)(?:ATTENDEE|ORGANIZER|METHOD)[;:]/i', $ics)) {
			throw new Problem('Attendee notifications and scheduling require a separate explicitly approved operation.', 400);
		}
		if (preg_match('/(?:^|\r?\n)(?:RRULE|RECURRENCE-ID|RDATE|EXDATE)[;:]/i', $ics)) {
			throw new Problem('Recurring resources and exceptions require a separate editor; no write sent.', 400);
		}
		// Validate all-day due date format: DUE;VALUE=DATE:YYYYMMDD or DUE:YYYYMMDD or DATE type without UTC flattening
		if (preg_match('/(?:^|\r?\n)DUE(?:;[^:]*)?:([^\r\n]+)/i', $ics, $matches)) {
			$dueVal = trim($matches[1]);
			// Reject UTC midnight flattening: YYYYMMDDT000000Z or similar when all-day date requested
			if (strlen($dueVal) > 16) {
				throw new Problem('Invalid due date format.', 400);
			}
		}
	}

	private function document(array $response): \DOMXPath {
		return DavResponse::document($response, 'urn:ietf:params:xml:ns:caldav');
	}

	private function text(\DOMXPath $xpath, string $expression, \DOMNode $node): string {
		return (string)$xpath->evaluate('string(' . $expression . ')', $node);
	}

	public function capabilities(): array {
		try {
			$policy = $this->policy->tasks();
			$collections = $this->lists();
		} catch (Problem $error) {
			if ($error->status === 403) {
				return [];
			}
			throw $error;
		}
		$writesEnabled = $this->configuration->providerWritesEnabled() && ($policy['writesEnabled'] ?? false);
		if (!$collections) {
			return [];
		}
		$tools = ['nc_tasks_read'];
		foreach (['create', 'patch', 'delete'] as $operation) {
			if ($writesEnabled && array_filter($collections, static fn (array $collection): bool => $collection['privileges'][$operation])) {
				$tools[] = 'nc_tasks_' . $operation;
			}
		}
		return [[
			'provider' => 'tasks',
			'apiVersion' => 'caldav-v1',
			'policyVersion' => $policy['policyVersion'],
			'enabled' => true,
			'tools' => $tools,
			'conflict' => 'conditional',
			'history' => 'audit-only',
			'refresh' => 'manual',
			'canRead' => true,
			'canWrite' => count($tools) > 1,
		]];
	}

	public function lists(): array {
		$policy = $this->policy->tasks();
		$this->policy->assertDisclosure($policy);
		$xpath = $this->document($this->bridge->send(NativeRequest::calendarCollections($policy['actor'])));
		$lists = [];
		foreach ($xpath->query('/d:multistatus/d:response') as $response) {
			$href = $this->text($xpath, 'd:href', $response);
			$types = $xpath->query('d:propstat/d:prop/d:resourcetype/*', $response);
			$isCalendar = false;
			foreach ($types as $type) {
				if ($type->localName === 'calendar' && $type->namespaceURI === 'urn:ietf:params:xml:ns:caldav') {
					$isCalendar = true;
					break;
				}
			}
			if (!$isCalendar) {
				continue;
			}
			if (!DavResponse::privileges($xpath, $response)['read']) {
				continue;
			}
			// Check if VTODO is supported in this calendar collection
			$components = $xpath->query('d:propstat/d:prop/c:supported-calendar-component-set/c:comp', $response);
			$supportsVtodo = false;
			foreach ($components as $comp) {
				if ($comp instanceof \DOMElement && strtoupper($comp->getAttribute('name')) === 'VTODO') {
					$supportsVtodo = true;
					break;
				}
			}
			if (!$supportsVtodo) {
				continue;
			}

			$id = DavReference::collection($this->bridge->base(), 'tasks', $policy['actor'], $href);
			if (!in_array($id, $policy['collections'], true)) {
				continue;
			}
			$title = $this->text($xpath, 'd:propstat/d:prop/d:displayname', $response);
			$lists[] = [
				'provider' => 'tasks',
				'privileges' => DavResponse::privileges($xpath, $response),
				'resourceId' => 'nextcloud:tasks:' . rtrim(strtr(base64_encode($id), '+/', '-_'), '='),
				'collectionId' => $id,
				'revision' => hash('sha256', $response->C14N()),
				'sourceClass' => $policy['sourceClass'],
				'audience' => 'unknown',
				'policyVersion' => $policy['policyVersion'],
				'title' => $title !== '' ? $title : $id,
			];
		}
		return $lists;
	}

	public function tasks(string $collectionId): array {
		$policy = $this->policy->tasks();
		$this->policy->assertDisclosure($policy);
		if (!in_array($collectionId, $policy['collections'], true)) {
			throw new Problem('The task list is outside the configured scope.', 404);
		}
		$xpath = $this->document($this->bridge->send(NativeRequest::calendarObjects($policy['actor'], $collectionId, 'VTODO')));
		$tasks = [];
		foreach ($xpath->query('/d:multistatus/d:response') as $response) {
			$href = $this->text($xpath, 'd:href', $response);
			$filename = DavReference::object($this->bridge->base(), 'tasks', $policy['actor'], $collectionId, $href);
			if (!str_ends_with(strtolower($filename), '.ics')) {
				continue;
			}
			$content = $this->text($xpath, 'd:propstat/d:prop/c:calendar-data', $response);
			if ($content === '') {
				continue;
			}
			if (count($tasks) >= 50 || strlen($content) > 65536) {
				throw new Problem('Narrow the task request.', 413);
			}
			$class = CalendarClassification::sourceClass($content, $policy['sourceClass']);
			$this->policy->assertDisclosure($policy, $class);
			$etag = NativeRequest::strongEtag($this->text($xpath, 'd:propstat/d:prop/d:getetag', $response));
			$tasks[] = [
				'provider' => 'tasks',
				'resourceId' => 'nextcloud:tasks:' . rtrim(strtr(base64_encode("{$collectionId}\0{$filename}"), '+/', '-_'), '='),
				'collectionId' => $collectionId,
				'filename' => $filename,
				'revision' => $etag,
				'sourceClass' => $class,
				'audience' => 'unknown',
				'policyVersion' => $policy['policyVersion'],
				'contentProfile' => 'icalendar-xml-normalized',
				'content' => $content,
			];
		}
		return $tasks;
	}

	public function task(string $collectionId, string $filename): array {
		$policy = $this->policy->tasks();
		$this->policy->assertDisclosure($policy);
		if (!in_array($collectionId, $policy['collections'], true)) {
			throw new Problem('The task list is outside the configured scope.', 404);
		}
		NativeRequest::segment($filename);
		$res = $this->bridge->send(NativeRequest::calendarObject($policy['actor'], $collectionId, $filename));
		if ($res['status'] !== 200) {
			throw new Problem('The task resource is not available.', 404);
		}
		$this->policy->assertDisclosure($policy, CalendarClassification::sourceClass($res['body'], $policy['sourceClass']));
		return [
			'etag' => DavResponse::etag($res['etag'] ?? ''),
			'content' => $res['body'],
		];
	}

	public function propose(string $operation, string $collectionId, string $filename, string $icsData, ?string $expectedEtag = null): array {
		$policy = $this->policy->tasks();
		$this->policy->assertDisclosure($policy);
		if (!($policy['writesEnabled'] ?? false)) {
			throw new Problem('Tasks mutations have been disabled by the administrator.', 403);
		}
		if (!in_array($collectionId, $policy['collections'], true)) {
			throw new Problem('The task list is outside the configured scope.', 404);
		}
		NativeRequest::segment($filename);
		if (!str_ends_with(strtolower($filename), '.ics')) {
			throw new Problem('A valid filename ending in .ics is required.', 400);
		}
		if (!in_array($operation, ['create', 'patch', 'delete'], true)) {
			throw new Problem('Unsupported task operation.', 400);
		}
		if ($operation !== 'delete') {
			$this->assertPrivateTask($icsData);
			$this->policy->assertDisclosure($policy, CalendarClassification::sourceClass($icsData, $policy['sourceClass']));
		}

		$identity = $this->journalIdentity($policy);
		$this->authority->mutation('tasks', $collectionId, $operation);

		if ($operation === 'create') {
			try {
				$existing = $this->bridge->send(NativeRequest::calendarObject($policy['actor'], $collectionId, $filename));
				if ($existing['status'] === 200) {
					throw new Problem('A task with this name already exists.', 409);
				}
			} catch (Problem $probe) {
				if ($probe->status !== 404) {
					throw $probe;
				}
			}
			$expectedEtag = null;
		} elseif ($operation === 'patch') {
			if ($expectedEtag === null || $expectedEtag === '' || str_starts_with(trim($expectedEtag), 'W/') || str_starts_with(trim($expectedEtag), 'w/')) {
				throw new Problem('A strong native expectedEtag is required.', 400);
			}
			$current = $this->bridge->send(NativeRequest::calendarObject($policy['actor'], $collectionId, $filename));
			if ($current['status'] !== 200) {
				throw new Problem('The task resource is not available.', 404);
			}
			$currentEtag = DavResponse::etag($current['etag']);
			$expectedEtag = NativeRequest::strongEtag($expectedEtag);
			if ($currentEtag !== $expectedEtag) {
				throw new Problem('The task resource changed. Read it again before proposing a write.', 409);
			}
			$this->policy->assertDisclosure($policy, CalendarClassification::sourceClass($current['body'], $policy['sourceClass']));
			$this->assertPrivateTask($current['body']);
		} else { // delete
			if ($expectedEtag === null || $expectedEtag === '' || str_starts_with(trim($expectedEtag), 'W/') || str_starts_with(trim($expectedEtag), 'w/')) {
				throw new Problem('A strong native expectedEtag is required.', 400);
			}
			$current = $this->bridge->send(NativeRequest::calendarObject($policy['actor'], $collectionId, $filename));
			if ($current['status'] !== 200) {
				throw new Problem('The task resource is not available.', 404);
			}
			$currentEtag = DavResponse::etag($current['etag']);
			$expectedEtag = NativeRequest::strongEtag($expectedEtag);
			if ($currentEtag !== $expectedEtag) {
				throw new Problem('The task resource changed. Read it again before proposing a write.', 409);
			}
			$this->policy->assertDisclosure($policy, CalendarClassification::sourceClass($current['body'], $policy['sourceClass']));
			$this->assertPrivateTask($current['body']);
			$icsData = '';
		}

		$p = [
			'id' => bin2hex(random_bytes(24)),
			'operation' => $operation,
			'toolName' => 'nc_tasks_' . $operation,
			'collectionId' => $collectionId,
			'filename' => $filename,
			'expectedEtag' => $expectedEtag,
			'content' => $icsData,
			'sourceClass' => CalendarClassification::sourceClass($operation === 'create' ? $icsData : $current['body'], $policy['sourceClass']),
			'hash' => hash('sha256', $icsData),
			'target' => "Task list {$collectionId}, task {$filename}",
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
			'expires' => $p['expires'],
		];
	}

	public function approve(string $id): array {
		$policy = $this->mutationPolicy();
		$p = $this->proposal($id);
		$this->assertProposalIdentity($p, $policy);
		$state = $this->state();
		$state['proposals'][$id]['approved'] = true;
		$this->setState($state);
		return ['approved' => true];
	}

	public function commit(string $id): array {
		$policy = $this->mutationPolicy();
		$p = $this->proposal($id);
		$identity = $this->assertProposalIdentity($p, $policy);
		if (!($p['approved'] ?? false)) {
			throw new Problem('The proposal must be approved before execution.', 403);
		}

		// Single use: consume immediately
		$state = $this->state();
		unset($state['proposals'][$id]);
		$this->setState($state);

		$journalResource = new JournalResource('tasks', 'collection:' . $p['collectionId'] . '/task:' . $p['filename'], 'collection:' . $p['collectionId']);
		$journalEntry = $this->journal->prepare($identity, $journalResource, $p['toolName'], ['content' => $p['content'], 'sourceClass' => $p['sourceClass']], [], ['etag' => $p['expectedEtag']]);
		$this->attemptId = $journalEntry['id'];
		$this->journal->markDispatched($identity, $journalEntry['id'], $journalEntry['ticket']);

		// Release session lock before HTTP request
		$this->session->close();

		$request = match ($p['operation']) {
			'create' => NativeRequest::createCalendarObject($policy['actor'], $p['collectionId'], $p['filename'], $p['content']),
			'patch' => NativeRequest::updateCalendarObject($policy['actor'], $p['collectionId'], $p['filename'], $p['content'], $p['expectedEtag']),
			'delete' => NativeRequest::deleteCalendarObject($policy['actor'], $p['collectionId'], $p['filename'], $p['expectedEtag']),
		};

		try {
			$res = $this->bridge->send($request);
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
				throw new Problem('The task resource was modified by another actor.', 412);
			}
			$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'unknown');
			throw $err;
		}

		try {
			$verify = $this->bridge->send(NativeRequest::calendarObject($policy['actor'], $p['collectionId'], $p['filename']));
			if ($p['operation'] === 'delete') {
				throw new Problem('The task resource could not be confirmed deleted.', 409);
			}
			$finalEtag = DavResponse::verifyContent($verify, $p['content']);
			$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'verified', new VerifiedReadback($finalEtag));
			return [
				'status' => 'saved',
				'actionId' => $journalEntry['id'],
				'outcome' => 'verified',
				'etag' => $finalEtag,
				'target' => $p['target'],
				'collectionId' => $p['collectionId'],
				'filename' => $p['filename'],
			];
		} catch (Problem $verifyErr) {
			if ($p['operation'] === 'delete' && $verifyErr->status === 404) {
				try {
					$this->authority->collection('tasks', $p['collectionId']);
				} catch (\Throwable $error) {
					$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'unknown');
					throw $error;
				}
				$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'verified', new VerifiedReadback('absent'));
				return [
					'status' => 'deleted',
					'actionId' => $journalEntry['id'],
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
}
