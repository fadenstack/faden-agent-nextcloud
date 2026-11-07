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
use OCP\Lock\ILockingProvider;

/** One-use native content proposals; Forms has independently checkpointed effects. */
final class ContentProvider {
	private const KEY = 'fadenstack_agent.content';
	private ?string $attempt = null;
	private bool $dispatched = false;
	private bool $partial = false;
	public function __construct(
		private ContentAuthority $native,
		private ContentPlan $plans,
		private Configuration $configuration,
		private IUserSession $users,
		private ISession $session,
		private JournalService $journal,
		private StructuredTickets $tickets,
		private ILockingProvider $locks,
	) {
	}
	public function attemptId(): ?string {
		return $this->attempt;
	}
	public function attemptOutcome(): string {
		return $this->partial ? 'partial' : ($this->dispatched ? 'unknown' : 'not-dispatched');
	}
	private function identity(): JournalIdentity {
		return new JournalIdentity($this->users->getUser()?->getUID() ?? throw new Problem('Sign in to Nextcloud.', 401), JournalIdentity::nativeScope(), hash('sha256', $this->session->getId()));
	}
	private function state(): array {
		$state = $this->session->get(self::KEY);
		return is_array($state) ? $state : [];
	}
	private function binding(array $resource): string {
		return StructuredProvider::digest([$this->identity()->userId, $this->session->getId(), $this->configuration->get(), $resource['policyVersion']]);
	}
	public function capabilities(): array {
		$result = [];
		foreach (ContentPlan::TOOLS as $provider => $names) {
			try {
				$policy = $this->native->policy($provider);
				$tools = [];
				foreach ($policy['collections'] as $id) {
					try {
						$value = $this->native->collection($provider, $id);
					} catch (Problem $e) {
						if (in_array($e->status, [403, 404], true)) {
							continue;
						} throw $e;
					}
					$tools[] = $names[0];
					if ($provider === 'forms' && $id === 'personal' && $policy['writesEnabled'] && $this->configuration->folderDispatchSupported()) {
						foreach ($value['data'] as $form) {
							try {
								$own = $this->native->collection('forms', (string)ContentAuthority::id($form['id'] ?? null));
							} catch (Problem $e) {
								if (in_array($e->status, [403, 404], true)) {
									continue;
								} throw $e;
							}
							if ($own['canWrite']) {
								$tools[] = 'nc_forms_submit_response';
								break;
							}
						}
					}
					if ($value['canWrite'] && $policy['writesEnabled'] && $this->configuration->folderDispatchSupported()) {
						foreach (array_slice($names, 1) as $name) {
							if ($provider === 'forms' && (($id === 'personal') !== ($name === 'nc_forms_create_form'))) {
								continue;
							}
							$tools[] = $name;
						}
					}
				}
				$tools = array_values(array_intersect(array_unique($tools), $policy['processing']['tools']));
				if ($tools) {
					$result[] = ['provider' => $provider, 'apiVersion' => $provider . '-' . ContentAuthority::VERSIONS[$provider] . '-session', 'policyVersion' => $policy['policyVersion'],
						'enabled' => true, 'tools' => $tools, 'conflict' => count($tools) > 1 ? 'native' : 'read-only', 'history' => 'audit-only', 'refresh' => 'child-reload', 'canRead' => true, 'canWrite' => count($tools) > 1];
				}
			} catch (Problem $e) {
				if (!in_array($e->status, [403, 404], true)) {
					throw $e;
				}
			}
		}
		return $result;
	}
	public function read(string $provider, array $args): array {
		if (!in_array('nc_' . $provider . '_read', $this->native->policy($provider)['processing']['tools'], true)) {
			throw new Problem('This native content read is outside the administrator tool scope.', 403);
		}
		return $this->plans->read($provider, $args);
	}
	private function validate(array $p): void {
		$now = $this->plans->prepare($p['toolName'], $p['args']);
		if (!hash_equals(StructuredProvider::digest(array_intersect_key($p, $now)), StructuredProvider::digest($now))) {
			throw new Problem('The native content, payload, permissions, audience or policy changed.', 409);
		}
	}
	public function propose(string $toolName, array $args, string $requestId): array {
		$identity = $this->identity();
		$this->tickets->start($identity, $requestId);
		try {
			$p = $this->plans->prepare($toolName, $args);
			$p['id'] = $requestId;
			$p['expires'] = time() + 600;
			$p['approved'] = false;
			$p['binding'] = $this->binding($p['resource']);
			$p['digest'] = StructuredProvider::digest($p);
			$state = array_filter($this->state(), static fn ($v) => $v['expires'] > time());
			if (count($state) >= 20) {
				throw new Problem('Too many pending native content previews.', 429);
			}
			$state[$requestId] = $p;
			$this->session->set(self::KEY, $state);
			if (!$this->tickets->move($identity, $requestId, ['preparing'], 'prepared')) {
				throw new Problem('The content preview was cancelled.', 410);
			}
			return array_intersect_key($p, array_flip(['id', 'digest', 'toolName', 'provider', 'target', 'before', 'payload', 'resource', 'display', 'limitation', 'history', 'expires']));
		} catch (\Throwable $error) {
			$this->tickets->cancel($identity, $requestId);
			throw $error;
		}
	}
	private function load(string $id, string $digest, array $states = ['prepared', 'approved']): array {
		if (!preg_match('/^[a-f0-9]{48}$/D', $id) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
			throw new Problem('Invalid native content preview.', 400);
		}
		$p = $this->state()[$id] ?? null;
		if (!$p || $p['expires'] <= time() || !hash_equals($p['digest'], $digest) || !hash_equals($p['binding'], $this->binding($p['resource'])) || !in_array($this->tickets->state($this->identity(), $id), $states, true)) {
			throw new Problem('The content preview expired, was cancelled or was consumed.', 410);
		}
		return $p;
	}
	public function approve(string $id, string $digest): array {
		$p = $this->load($id, $digest);
		$this->validate($p);
		if ($p['approved']) {
			return ['approved' => true];
		}
		if (!$this->tickets->move($this->identity(), $id, ['prepared'], 'approving')) {
			throw new Problem('The content approval was consumed or cancelled.', 410);
		}
		$authority = 'collection:' . $p['collectionId'];
		$entry = $this->journal->prepare($this->identity(), new JournalResource($p['provider'], $authority . '/action:' . $id, $authority), $p['toolName'], ['proposal' => $p, 'effects' => []], [], []);
		$p['actionId'] = $entry['id'];
		$p['ticket'] = $entry['ticket'];
		$p['approved'] = true;
		$state = $this->state();
		$state[$id] = $p;
		$this->session->set(self::KEY, $state);
		if (!$this->tickets->move($this->identity(), $id, ['approving'], 'approved')) {
			$this->journal->abort($this->identity(), $p['actionId'], $p['ticket']);
			throw new Problem('Content approval was cancelled.', 410);
		}
		return ['approved' => true];
	}
	public function cancel(string $id): array {
		$identity = $this->identity();
		$stopped = $this->tickets->state($identity, $id) === null ? $this->tickets->cancel($identity, $id)
			: $this->tickets->move($identity, $id, ['preparing', 'prepared', 'approving', 'approved', 'awaiting_step', 'dispatching', 'cancelled'], 'cancelled');
		$p = $this->state()[$id] ?? null;
		if ($stopped && isset($p['ticket'])) {
			try {
				$this->journal->abort($identity, $p['actionId'], $p['ticket']);
			} catch (\Throwable) { /* retain durable uncertainty */
			}
		}
		return ['cancelled' => $stopped, 'message' => 'Further dispatch is stopped. An in-flight request may still commit; inspect without repeating it.'];
	}
	public function commit(string $id, string $digest, int $expectedStep): array {
		if ($expectedStep < 0 || $expectedStep > 151) {
			throw new Problem('A bounded content step is required.', 400);
		}
		$p = $this->load($id, $digest, ['prepared', 'approved', 'awaiting_step']);
		if (!$p['approved']) {
			throw new Problem('Individual approval is required.', 403);
		}
		$this->attempt = $p['actionId'];
		$identity = $this->identity();
		$first = true;
		$replay = false;
		$lockCollection = $p['collectionId'] === 'personal' ? $identity->ownerHash . ':personal' : $p['collectionId'];
		$lock = hash('sha256', 'fadenstack:content:' . $p['provider'] . ':' . $lockCollection);
		$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$first = $this->tickets->state($identity, $id) === 'approved';
			$this->partial = !$first;
			$effects = $first ? [] : ($this->journal->preview($identity, $p['actionId'])['snapshot']['forward']['effects'] ?? []);
			if (count($effects) !== $expectedStep || (!$first && ($effects[$expectedStep - 1]['phase'] ?? '') !== 'observed') || ($p['toolName'] !== 'nc_forms_create_form' && $expectedStep !== 0)) {
				$replay = true;
				throw new Problem('This content step is out of order or already consumed. Inspect without repeating it.', 409);
			}
			if ($first) {
				$this->validate($p);
			}
			$value = $this->native->collection($p['provider'], $p['collectionId']);
			if (!$value['policy']['writesEnabled'] || !$value['canWrite'] || !hash_equals($value['authorityRevision'], $p['authority'])) {
				throw new Problem('Native content authority changed.', 409);
			}
			if ($p['toolName'] === 'nc_forms_create_form' && !$first) {
				$this->verifyPartialForm($p, $effects, $value['policy']);
			}
			if (!$this->tickets->move($identity, $id, [$first ? 'approved' : 'awaiting_step'], 'dispatching')) {
				throw new Problem('This content step was cancelled or dispatched.', 410);
			}
			if ($first) {
				$this->journal->markDispatched($identity, $p['actionId'], $p['ticket']);
			}
			if ($p['toolName'] === 'nc_forms_create_form') {
				return $this->formStep($p, $effects, $expectedStep, $value['policy']);
			}
			$this->dispatched = true;
			$response = $this->native->request($value['policy'], $p['provider'], $p['operation'], $p['provider'] === 'collectives' || $p['provider'] === 'forms' ? (int)$p['collectionId'] : 0, $p['itemId'], $p['payload']);
			if ($p['toolName'] === 'nc_forms_submit_response') {
				// Native returns HTTP 201 with no submission ID. Submit-only users
				// cannot read results. Never turn that into invented stored-answer proof.
				$this->journal->finish($identity, $p['actionId'], $p['ticket'], 'unknown');
				$this->tickets->move($identity, $id, ['dispatching'], 'finished');
				return ['outcome' => 'unknown', 'continue' => false, 'actionId' => $p['actionId'], 'provider' => 'forms', 'target' => $p['target'], 'history' => 'audit-only', 'result' => ['nativeAccepted' => true, 'storedAnswersVerified' => false], 'limitation' => 'The native API accepted the submission but returned no identifier. Inspect in Forms; do not submit again automatically.'];
			}
			$result = $this->verifySingle($p, $response, $value['policy']);
			return $this->finish($p, $result, StructuredProvider::digest($result));
		} catch (\Throwable $error) {
			if ($replay) {
				throw $error;
			}
			try {
				if ($this->dispatched || !$first) {
					$this->journal->finish($identity, $p['actionId'], $p['ticket'], 'unknown');
				} else {
					$this->journal->abort($identity, $p['actionId'], $p['ticket']);
				}
			} catch (\Throwable) { /* retain durable uncertainty */
			}
			$this->tickets->move($identity, $id, ['approved', 'awaiting_step', 'dispatching'], 'finished');
			throw $error;
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
	private function verifySingle(array $p, mixed $response, array $policy): array {
		if ($p['provider'] === 'notes') {
			$id = ContentAuthority::id($response['id'] ?? null);
			$actual = $this->native->request($policy, 'notes', 'note', 0, $id);
			foreach ($p['payload'] as $key => $expected) {
				if (($actual[$key] ?? null) !== $expected) {
					throw new Problem('The saved native note differs from the approved payload.', 503);
				}
			}
			return ['id' => $id, 'title' => $actual['title']];
		}
		if ($p['provider'] === 'bookmarks') {
			$id = ContentAuthority::id($response['item']['id'] ?? null);
			$actual = $this->native->request($policy, 'bookmarks', 'bookmark', 0, $id)['item'] ?? null;
			foreach ($p['payload'] as $key => $expected) {
				$observed = $actual[$key] ?? null;
				if ($key === 'tags' && is_array($observed)) {
					sort($observed, SORT_STRING);
					sort($expected, SORT_STRING);
				}
				if ($observed !== $expected) {
					throw new Problem('The saved native bookmark differs from the approved payload.', 503);
				}
			}
			return ['id' => $id, 'url' => $actual['url'], 'crawl' => 'queued-by-native-app'];
		}
		$id = $p['operation'] === 'delete_page' ? $p['itemId'] : ContentAuthority::id($response['page']['id'] ?? null);
		$pages = $this->native->request($policy, 'collectives', $p['operation'] === 'delete_page' ? 'trash' : 'pages', (int)$p['collectionId'])['pages'] ?? null;
		$pages = ContentAuthority::boundedList($pages, 100);
		$actual = array_values(array_filter($pages, static fn ($page) => $page['id'] === $id))[0] ?? null;
		if (!$actual || ($p['operation'] === 'create_page' && (($actual['title'] ?? null) !== $p['payload']['title'] || ($actual['parentId'] ?? null) !== $p['itemId']))) {
			throw new Problem('The native page result could not be verified.', 503);
		}
		return ['id' => $id, 'deleted' => $p['operation'] === 'delete_page', 'title' => $actual['title']];
	}
	private function formStep(array $p, array $effects, int $index, array $policy): array {
		$recipe = $p['steps'][$index] ?? throw new Problem('No remaining Forms steps.', 410);
		$formId = $index === 0 ? 0 : ContentAuthority::id($effects[0]['facts']['nativeId'] ?? null);
		$questionId = 0;
		$payload = [];
		if (isset($recipe['question'])) {
			$q = $p['payload']['questions'][$recipe['question']];
			if ($recipe['operation'] === 'create_question') {
				$payload = array_intersect_key($q, array_flip(['text', 'type']));
			} else {
				foreach ($p['steps'] as $i => $prior) {
					if ($prior['operation'] === 'create_question' && ($prior['question'] ?? null) === $recipe['question']) {
						$questionId = ContentAuthority::id($effects[$i]['facts']['nativeId'] ?? null);
					}
				}
				$payload = $recipe['operation'] === 'configure_question' ? ['keyValuePairs' => ['isRequired' => true]] : ['optionTexts' => $q['options']];
			}
		} elseif ($recipe['operation'] === 'configure_form') {
			$payload = ['keyValuePairs' => ['title' => $p['payload']['title']]];
		}
		$step = sprintf('form_%03d', $index);
		$this->journal->checkpointEffect($this->identity(), $p['actionId'], $p['ticket'], $step, 'dispatched');
		$this->dispatched = true;
		$response = $this->native->request($policy, 'forms', $recipe['operation'], $formId, $questionId, $payload);
		$nativeId = $recipe['operation'] === 'create_form' || $recipe['operation'] === 'create_question' ? ContentAuthority::id($response['id'] ?? null) : ($questionId ?: $formId);
		$this->journal->checkpointEffect($this->identity(), $p['actionId'], $p['ticket'], $step, 'observed', ['nativeId' => $nativeId]);
		$effects[] = ['step' => $step, 'phase' => 'observed', 'facts' => ['nativeId' => $nativeId]];
		$formId = $formId ?: $nativeId;
		$this->verifyPartialForm($p, $effects, $policy);
		if ($index + 1 === count($p['steps'])) {
			return $this->finish($p, ['id' => $formId, 'title' => $p['payload']['title'], 'private' => true], StructuredProvider::digest($effects));
		}
		$pending = $this->tickets->move($this->identity(), $p['id'], ['dispatching'], 'awaiting_step');
		return ['outcome' => $pending ? 'running' : 'partial', 'continue' => $pending, 'actionId' => $p['actionId'], 'provider' => 'forms', 'target' => $p['target'], 'nativeId' => $formId, 'step' => $step, 'history' => 'audit-only', 'limitation' => ContentPlan::LIMITATION];
	}
	private function verifyPartialForm(array $p, array $effects, array $policy): void {
		$formId = ContentAuthority::id($effects[0]['facts']['nativeId'] ?? null);
		$form = $this->native->request($policy, 'forms', 'metadata', $formId);
		ContentAuthority::assertFormMembership($form, $formId);
		if (!$this->native->privateForm($form, $policy['actor']) || !in_array('edit', $form['permissions'] ?? [], true)) {
			throw new Problem('The retained form is no longer private or editable. No subsequent step will be sent.', 409);
		}
		if (($form['state'] ?? null) !== 0 || ($form['expires'] ?? null) !== 0 || ($form['isAnonymous'] ?? null) !== false || ($form['submitMultiple'] ?? null) !== false || ($form['allowEditSubmissions'] ?? null) !== false) {
			throw new Problem('The partial form’s native submission settings changed. Inspect it without resuming.', 409);
		}
		$expectedTitle = count($effects) > 1 ? $p['payload']['title'] : '';
		if (($form['title'] ?? null) !== $expectedTitle || ($form['description'] ?? null) !== '') {
			throw new Problem('The partial native form changed. Inspect it without resuming.', 409);
		}
		$expected = [];
		foreach ($effects as $i => $effect) {
			$recipe = $p['steps'][$i];
			if (!isset($recipe['question'])) {
				continue;
			}
			$q = $p['payload']['questions'][$recipe['question']];
			if ($recipe['operation'] === 'create_question') {
				$expected[$recipe['question']] = ['id' => $effect['facts']['nativeId'], 'type' => $q['type'], 'text' => $q['text'], 'isRequired' => false, 'options' => []];
			} elseif ($recipe['operation'] === 'configure_question') {
				$expected[$recipe['question']]['isRequired'] = true;
			} else {
				$expected[$recipe['question']]['options'] = $q['options'];
			}
		}
		$questions = ContentAuthority::boundedList($form['questions'] ?? null);
		if (count($questions) !== count($expected)) {
			throw new Problem('The native partial question set changed.', 409);
		}
		foreach (array_values($expected) as $i => $wanted) {
			$actual = $questions[$i];
			foreach (['id', 'type', 'text', 'isRequired'] as $key) {
				if (($actual[$key] ?? null) !== $wanted[$key]) {
					throw new Problem('A native partial question changed.', 409);
				}
			}
			if (array_column($actual['options'] ?? [], 'text') !== $wanted['options']) {
				throw new Problem('Native option labels or order changed.', 409);
			}
		}
	}
	private function finish(array $p, array $result, string $revision): array {
		$base = 'collection:' . $p['collectionId'];
		$kind = ['notes' => 'note', 'bookmarks' => 'bookmark', 'forms' => 'form'][$p['provider']] ?? null;
		$actual = $kind ? new JournalResource($p['provider'], $base . '/action:' . $p['id'], $base . '/' . $kind . ':' . ContentAuthority::id($result['id'] ?? null)) : null;
		$this->journal->finish($this->identity(), $p['actionId'], $p['ticket'], 'verified', new VerifiedReadback($revision, $actual));
		$this->tickets->move($this->identity(), $p['id'], ['dispatching'], 'finished');
		$state = $this->state();
		unset($state[$p['id']]);
		$this->session->set(self::KEY, $state);
		return ['outcome' => 'verified', 'continue' => false, 'actionId' => $p['actionId'], 'provider' => $p['provider'], 'collectionId' => $p['collectionId'], 'target' => $p['target'], 'result' => $result, 'history' => 'audit-only', 'limitation' => ContentPlan::LIMITATION];
	}
	public function actions(): array {
		return array_map(static fn ($e) => ['id' => $e['id'], 'provider' => $e['resource']['provider'], 'name' => 'Native content action', 'target' => ucfirst($e['resource']['provider']), 'state' => $e['state'], 'eligible' => 'none', 'auditOnly' => true, 'version' => $e['version'], 'createdAt' => $e['createdAt']], $this->journal->list($this->identity(), null, array_keys(ContentPlan::TOOLS), 50));
	}
	public function inspect(string $id): array {
		$entry = $this->journal->describe($this->identity(), $id);
		if (!isset(ContentPlan::TOOLS[$entry['resource']['provider']])) {
			throw new Problem('Unavailable native content action.', 404);
		}
		$effects = $entry['snapshotAvailable'] ? ($this->journal->preview($this->identity(), $id)['snapshot']['forward']['effects'] ?? []) : [];
		if ($entry['resource']['provider'] === 'forms' && isset($effects[0]['facts']['nativeId'])) {
			$this->native->collection('forms', (string)ContentAuthority::id($effects[0]['facts']['nativeId']), true);
		}
		return ['action' => ['id' => $id, 'provider' => $entry['resource']['provider'], 'name' => 'Native content action', 'target' => ucfirst($entry['resource']['provider']), 'state' => $entry['state'], 'eligible' => 'none', 'auditOnly' => true, 'version' => $entry['version'], 'createdAt' => $entry['createdAt']], 'observed' => 'recorded-attempt', 'journalState' => $entry['state'], 'outcome' => $entry['state'] === 'applied' ? 'verified_committed' : 'unknown', 'effects' => $effects, 'repeatedDispatch' => false, 'message' => 'Historical attempts and known native identifiers only. Inspect the native app for current content. No partial construction or submission is repeated by inspection. No Undo is certified.'];
	}
}
