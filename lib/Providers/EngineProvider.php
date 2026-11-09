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
use OCP\Lock\LockedException;

/** Existing journal and tickets; one approved engine effect, never a retry. */
final class EngineProvider {
	private const KEY = 'fadenstack_agent.engine_proposals';
	private ?string $attempt = null;
	private bool $dispatched = false;
	public function __construct(
		private EngineAuthority $native,
		private EngineDraftPlan $plans,
		private EngineReads $reads,
		private EngineBroker $broker,
		private EngineProvenance $provenance,
		private ProviderPolicy $policies,
		private Configuration $configuration,
		private IUserSession $users,
		private ISession $session,
		private JournalService $journal,
		private StructuredTickets $tickets,
		private ILockingProvider $locks,
		private EngineRunPlan $runs,
		private N8nExecutePlan $n8nRuns,
	) {
	}
	public function attemptId(): ?string {
		return $this->attempt;
	}
	public function attemptOutcome(): string {
		return $this->dispatched ? 'unknown' : 'not-dispatched';
	}
	private function identity(): JournalIdentity {
		return new JournalIdentity($this->users->getUser()?->getUID() ?? throw new Problem('Sign in to Nextcloud.', 401), JournalIdentity::nativeScope(), hash('sha256', $this->session->getId()));
	}
	private function state(): array {
		$value = $this->session->get(self::KEY);
		return is_array($value) ? $value : [];
	}
	private function binding(array $p): string {
		return StructuredProvider::digest([$this->identity()->userId, $this->session->getId(), $this->configuration->get(), $p['resource']['policyVersion']]);
	}
	public function capabilities(): array {
		$rows = $this->reads->capabilities();
		foreach ($rows as &$row) {
			$policy = $this->policies->engine($row['provider']);
			if ($row['provider'] === 'n8n' && $policy['writesEnabled'] && EngineRunPlan::canMove($policy)
				&& $this->configuration->folderDispatchSupported() && in_array(N8nExecutePlan::TOOL, $policy['processing']['tools'], true)) {
				foreach ($this->n8nRuns->targetRows() as $target) {
					try {
						$this->n8nRuns->prepare(['targetId' => $target['id'], 'dataJson' => '{}'], str_repeat('0', 48));
						$row['tools'][] = N8nExecutePlan::TOOL;
						$row['canWrite'] = true;
						$row['conflict'] = 'native';
						$row['history'] = 'audit-only';
						break;
					} catch (Problem) { /* Unconfigured/unauthorized secrets grant no execution tool. */
					}
				}
			}
			if ($row['provider'] === 'windmill' && $policy['writesEnabled'] && EngineRunPlan::canMove($policy)
				&& $this->configuration->folderDispatchSupported() && in_array(EngineRunPlan::TOOL, $policy['processing']['tools'], true)) {
				foreach ($policy['collections'] as $id) {
					foreach ($this->runs->targets($id) as $reference) {
						try {
							$this->runs->prepare(['connectionId' => $id, 'reference' => $reference, 'argsJson' => '{}'], str_repeat('0', 48));
							$row['tools'][] = EngineRunPlan::TOOL;
							$row['canWrite'] = true;
							$row['conflict'] = 'native';
							$row['history'] = 'audit-only';
							break 2;
						} catch (Problem) { /* No unverified run capability. */
						}
					}
				}
			}
			$tool = EngineDraftPlan::TOOLS[$row['provider']];
			if (!$policy['writesEnabled'] || !EngineDraftPlan::canMove($policy) || !$this->configuration->folderDispatchSupported() || !in_array($tool, $policy['processing']['tools'], true)) {
				continue;
			}
			foreach ($policy['collections'] as $id) {
				try {
					$this->native->principal($this->native->connection($row['provider'], $id, true));
					$row['tools'][] = $tool;
					$row['canWrite'] = true;
					$row['conflict'] = 'native';
					$row['history'] = 'audit-only';
					break;
				} catch (Problem) { /* One missing credential never grants another native connection. */
				}
			}
		}
		return $rows;
	}
	private function prepare(string $tool, array $args, string $id): array {
		return match ($tool) {
			EngineRunPlan::TOOL => $this->runs->prepare($args, $id),
			N8nExecutePlan::TOOL => $this->n8nRuns->prepare($args, $id),
			default => $this->plans->prepare($tool, $args, $id),
		};
	}
	private static function execution(array $p): bool {
		return in_array($p['toolName'], [EngineRunPlan::TOOL, N8nExecutePlan::TOOL], true);
	}
	public function propose(string $toolName, array $args, string $requestId, #[\SensitiveParameter] string $cloudToken): array {
		$identity = $this->identity();
		$this->tickets->start($identity, $requestId);
		try {
			$p = $this->prepare($toolName, $args, $requestId);
			$run = self::execution($p);
			if (!$run) {
				$value = EngineJson::decode($p['unsignedJson']);
				$signed = $this->provenance->sign($p['provider'], EngineDraft::source($p['provider'], $value), $cloudToken);
				$p['payload'] = ['definitionJson' => EngineDraft::mark($p['provider'], $value, $signed, isset($p['copySource']) ? ['origin' => $p['sourceOrigin']] : null)];
			}
			$p['display'] = [['label' => 'Nextcloud account', 'before' => null, 'after' => $identity->userId],
				['label' => 'Engine account / connection', 'before' => null, 'after' => $p['enginePrincipal'] . ' / ' . $p['connectionId']]];
			if (isset($p['copySource'])) {
				$p['display'][] = ['label' => 'Copy source / kind', 'before' => null, 'after' => $p['copySource']['reference'] . ' / ' . $p['copySource']['kind']];
				$p['display'][] = ['label' => 'Copy source revision', 'before' => null, 'after' => $p['copySource']['revision']];
				$p['display'][] = ['label' => 'Copy behavior', 'before' => null, 'after' => 'New inactive copy. Unspecified draft fields and native prior origin retained. Top-level replacements are explicit; nested fields are not merged. Current native source is rechecked before dispatch; native editors are not locked. Prior origin is unverified history.'];
			}
			if ($toolName === N8nExecutePlan::TOOL) {
				$p['display'][] = ['label' => 'Workflow', 'before' => null, 'after' => $p['targetLabel'] . ' (' . $p['target'] . ')'];
				$p['display'][] = ['label' => 'Published webhook target', 'before' => null, 'after' => $p['webhook']['id'] . ' /webhook/' . $p['webhook']['path']];
				$p['display'][] = ['label' => 'Native authentication', 'before' => null, 'after' => ($p['webhook']['authentication'] ?? null) === 'oauth2'
					? 'User-bound OAuth; native execution history may retain the bearer token.' : 'Header Auth / ' . $p['webhook']['credentialId']];
			}
			$p['display'] = [...$p['display'], ...($run ? [
				['label' => 'Deployed flow version', 'before' => null, 'after' => $p['payload']['version']],
				['label' => 'Job / action correlation', 'before' => null, 'after' => $p['payload']['jobId'] ?? $requestId],
				['label' => 'Final run inputs', 'before' => null, 'after' => $p['payload']['argsJson'] ?? $p['payload']['bodyJson']],
				['label' => 'Approved deployed workflow JSON', 'before' => null, 'after' => $p['payload']['workflowJson']],
				['label' => 'Effect audience', 'before' => null, 'after' => 'Unknown; native credentials and external destinations may be used.'],
			] : [
				['label' => 'Final workflow JSON including AI origin', 'before' => null, 'after' => $p['payload']['definitionJson']],
				['label' => 'AI origin', 'before' => null, 'after' => 'Signed and stored inside the workflow.'],
			])];
			$p['id'] = $requestId;
			$p['expires'] = time() + 600;
			$p['approved'] = false;
			$p['binding'] = $this->binding($p);
			$p['digest'] = StructuredProvider::digest($p);
			$state = array_filter($this->state(), static fn ($v) => $v['expires'] > time());
			if (count($state) >= 20) {
				throw new Problem('Too many pending engine previews.', 429);
			}
			$state[$requestId] = $p;
			$this->session->set(self::KEY, $state);
			if (!$this->tickets->move($identity, $requestId, ['preparing'], 'prepared')) {
				throw new Problem('The engine preview was stopped.', 410);
			}
			return array_intersect_key($p, array_flip(['id', 'digest', 'toolName', 'provider', 'target', 'before', 'payload', 'resource', 'destinationAudience', 'display', 'limitation', 'history', 'expires']));
		} catch (\Throwable $error) {
			$this->tickets->cancel($identity, $requestId);
			throw $error;
		}
	}
	private function load(string $id, string $digest): array {
		if (!preg_match('/^[a-f0-9]{48}$/D', $id) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
			throw new Problem('Invalid engine preview.', 400);
		}
		$p = $this->state()[$id] ?? null;
		if (!$p || $p['expires'] <= time() || !hash_equals($p['digest'], $digest) || !hash_equals($p['binding'], $this->binding($p))
			|| !in_array($this->tickets->state($this->identity(), $id), ['prepared', 'approved'], true)) {
			throw new Problem('The engine preview expired, was stopped or consumed.', 410);
		}
		return $p;
	}
	private function validate(array $p): void {
		$now = $this->prepare($p['toolName'], $p['args'], $p['id']);
		if (!hash_equals(StructuredProvider::digest(array_intersect_key($p, $now)), StructuredProvider::digest($now))) {
			throw new Problem('The engine account, connection, target, source, payload or policy changed.', 409);
		}
	}
	private function resource(array $p): JournalResource {
		return new JournalResource($p['provider'], 'action:' . $p['id'], json_encode([$p['connectionId'], $p['endpointStamp'], $p['enginePrincipal'],
			...($p['toolName'] === N8nExecutePlan::TOOL ? ['n8n-execution-v1'] : [])], JSON_THROW_ON_ERROR));
	}
	public function approve(string $id, string $digest): array {
		$p = $this->load($id, $digest);
		$this->validate($p);
		if ($p['approved']) {
			return ['approved' => true];
		}
		$identity = $this->identity();
		if (!$this->tickets->move($identity, $id, ['prepared'], 'approving')) {
			throw new Problem('This engine approval was consumed or stopped.', 410);
		}
		try {
			$entry = $this->journal->prepare($identity, $this->resource($p), $p['toolName'], ['proposal' => $p, 'sourceClass' => $p['resource']['sourceClass']], [], []);
			$p['actionId'] = $entry['id'];
			$p['ticket'] = $entry['ticket'];
			$p['approved'] = true;
			$state = $this->state();
			$state[$id] = $p;
			$this->session->set(self::KEY, $state);
			if (!$this->tickets->move($identity, $id, ['approving'], 'approved')) {
				$this->journal->abort($identity, $p['actionId'], $p['ticket']);
				throw new Problem('The engine approval was stopped.', 410);
			}
		} catch (\Throwable $error) {
			$this->tickets->cancel($identity, $id);
			throw $error;
		}
		return ['approved' => true];
	}
	public function cancel(string $id): array {
		$identity = $this->identity();
		$stopped = $this->tickets->state($identity, $id) === null ? $this->tickets->cancel($identity, $id) : $this->tickets->move($identity, $id, ['preparing', 'prepared', 'approving', 'approved', 'dispatching', 'cancelled'], 'cancelled');
		$p = $this->state()[$id] ?? null;
		if ($stopped && isset($p['ticket'])) {
			try {
				$this->journal->abort($identity, $p['actionId'], $p['ticket']);
			} catch (\Throwable) { /* Retain uncertainty. */
			}
		}
		return ['cancelled' => $stopped, 'message' => 'Further engine dispatch is stopped. An in-flight request can still commit. Inspect without repeating it.'];
	}
	private function observe(array $p, array $connection): array {
		if ($p['toolName'] === N8nExecutePlan::TOOL) {
			return $this->n8nRuns->inspect($p, $connection);
		}
		if ($p['toolName'] === EngineRunPlan::TOOL) {
			try {
				$job = $this->broker->request(EngineRequest::windmillJob($connection['actor'], $connection['endpoint'], $p['payload']['jobId']), $connection['revision']);
			} catch (Problem $error) {
				if ($error->status === 404) {
					return ['present' => false, 'matches' => false, 'executionState' => 'unknown'];
				}
				throw $error;
			}
			return EngineRunPlan::observe($p, $job);
		}
		if ($p['provider'] === 'windmill') {
			$saved = $this->native->ownDraft($connection, $p['target']);
			if ($saved === null) {
				return ['present' => false, 'matches' => false];
			}
			$actual = $saved['value'] ?? null;
			return ['present' => true, 'matches' => $actual instanceof \stdClass && EngineDraft::matches($p['payload']['definitionJson'], $actual), 'reference' => $p['target']];
		}
		$items = $this->native->n8nByName($connection, $p['target']);
		if (count($items) !== 1) {
			return ['present' => count($items) > 0, 'matches' => false];
		}
		$actual = $this->native->definition($connection, $items[0]->id);
		EngineAuthority::assertN8nOwner($actual, $p['enginePrincipal']);
		return ['present' => true, 'matches' => ($actual['active'] ?? null) === false && ($actual['activeVersionId'] ?? null) === null
			&& EngineDraft::matches($p['payload']['definitionJson'], (object)$actual), 'reference' => $items[0]->id];
	}
	public function commit(string $id, string $digest): array {
		$p = $this->load($id, $digest);
		if (!$p['approved']) {
			throw new Problem('Individual engine approval is required.', 403);
		}
		$this->attempt = $p['actionId'];
		$identity = $this->identity();
		$lock = hash('sha256', 'fadenstack:engine:' . $identity->ownerHash . ':' . $p['connectionId']);
		try {
			$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			$this->dispatched = !in_array($this->tickets->state($identity, $id), ['prepared', 'approved', 'cancelled'], true);
			throw new Problem('Another engine attempt is in progress. Inspect without repeating it.', 409);
		}
		try {
			$this->validate($p);
			$connection = $this->native->connection($p['provider'], $p['connectionId'], true);
			if (!$this->tickets->move($identity, $id, ['approved'], 'dispatching')) {
				throw new Problem('This engine action was stopped or consumed.', 410);
			}
			$this->journal->markDispatched($identity, $p['actionId'], $p['ticket']);
			$marked = true;
			$run = self::execution($p);
			$request = match ($p['toolName']) {
				EngineRunPlan::TOOL => EngineRequest::windmillRun($connection['actor'], $connection['endpoint'], $p['payload']['version'], $p['payload']['jobId'], $p['payload']['argsJson']),
				N8nExecutePlan::TOOL => EngineRequest::n8nWebhook($connection['actor'], $connection['endpoint'], $p['webhook'], $p['payload']['bodyJson'], $p['enginePrincipal']),
				default => $p['provider'] === 'windmill' ? EngineRequest::saveOwnDraft($connection['actor'], $connection['endpoint'], $p['target'], $p['payload']['definitionJson'])
					: EngineRequest::createN8nDraft($connection['actor'], $connection['endpoint'], $p['payload']['definitionJson']),
			};
			$ack = $this->broker->request($request, $p['connectionRevision'], function () use ($identity, $id): void {
				if ($this->tickets->state($identity, $id) !== 'dispatching') {
					throw new Problem('Stop prevented this engine dispatch.', 410);
				}
			});
			$this->dispatched = true;
			if ($p['toolName'] === EngineRunPlan::TOOL && ($ack['jobId'] ?? null) !== $p['payload']['jobId']) {
				throw new Problem('The unique native job acknowledgement could not be verified.', 503);
			}
			if (!$run && $p['provider'] === 'windmill' && (($ack['status'] ?? null) !== 'saved' || ($ack['path'] ?? null) !== $p['target'])) {
				throw new Problem('The native draft save was not confirmed.', 409);
			}
			if (!$run && $p['provider'] === 'n8n' && (!is_string($ack['id'] ?? null) || ($ack['name'] ?? null) !== $p['target'] || ($ack['active'] ?? null) !== false)) {
				throw new Problem('The native inactive draft was not confirmed.', 503);
			}
			if ($p['toolName'] === N8nExecutePlan::TOOL && (!is_string($ack['executionId'] ?? null) || !preg_match('/^[1-9][0-9]{0,18}$/D', $ack['executionId']) || ($ack['correlation'] ?? null) !== $p['id'])) {
				throw new Problem('The native webhook execution acknowledgement could not be verified.', 503);
			}
			$result = $p['toolName'] === N8nExecutePlan::TOOL ? $this->n8nRuns->inspect($p, $connection, $ack['executionId']) : $this->observe($p, $connection);
			if (!$result['matches'] || (!$run && $p['provider'] === 'n8n' && $result['reference'] !== $ack['id'])) {
				throw new Problem('The saved draft differs from the final approved graph or origin record.', 503);
			}
			$this->journal->finish($identity, $p['actionId'], $p['ticket'], 'verified', new VerifiedReadback(StructuredProvider::digest($result)));
			$this->tickets->move($identity, $id, ['dispatching'], 'finished');
			$state = $this->state();
			unset($state[$id]);
			$this->session->set(self::KEY, $state);
			return ['outcome' => 'verified', 'continue' => false, 'actionId' => $p['actionId'], 'provider' => $p['provider'], 'collectionId' => $p['connectionId'], 'target' => $p['target'],
				'result' => $run ? $result : ['reference' => $result['reference'], 'inactiveDraft' => true, 'originStored' => true], 'history' => 'audit-only', 'limitation' => $p['limitation']];
		} catch (\Throwable $error) {
			$this->dispatched = $this->broker->effectAttempted();
			try {
				if ($this->dispatched) {
					$this->journal->finish($identity, $p['actionId'], $p['ticket'], 'unknown');
				} elseif (isset($marked)) {
					$current = $this->native->connection($p['provider'], $p['connectionId'], false, true);
					if ($current['endpoint']['stamp'] !== $p['endpointStamp'] || $this->native->principal($current) !== $p['enginePrincipal']) {
						throw new Problem('The engine identity changed.', 409);
					}
					if (!self::execution($p)) {
						$this->native->absent($current, $p['target']);
					}
					$this->journal->finish($identity, $p['actionId'], $p['ticket'], 'unchanged', new VerifiedReadback(StructuredProvider::digest(['target' => $p['target'], 'present' => false, 'effectAttempted' => false])));
				} else {
					$this->journal->abort($identity, $p['actionId'], $p['ticket']);
				}
			} catch (\Throwable) {
				try {
					$this->journal->abort($identity, $p['actionId'], $p['ticket']);
				} catch (\Throwable) { /* Retain durable uncertainty. */
				}
			}
			$this->tickets->move($identity, $id, ['approved', 'dispatching'], 'finished');
			throw $error;
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
	public function actions(): array {
		return array_map(fn ($e) => $this->item($e), $this->journal->list($this->identity(), null, array_keys(EngineDraftPlan::TOOLS), 50));
	}
	private function item(array $entry): array {
		$provider = $entry['resource']['provider'];
		$requestId = substr($entry['resource']['reference'], 7);
		$run = in_array($entry['operation'] ?? null, [EngineRunPlan::TOOL, N8nExecutePlan::TOOL], true);
		return ['id' => $entry['id'], 'provider' => $provider, 'operation' => $entry['operation'], 'name' => ($provider === 'n8n' ? 'n8n' : 'Windmill') . ($run ? ' run' : ' draft'),
			'target' => ($run ? 'Run ' : 'Draft ') . substr($requestId, 0, 12), 'requestId' => $requestId,
			'state' => $entry['state'], 'eligible' => 'none', 'auditOnly' => true, 'version' => $entry['version'], 'createdAt' => $entry['createdAt']];
	}
	public function inspect(string $id): array {
		$identity = $this->identity();
		$entry = $this->journal->describe($identity, $id);
		if (!isset(EngineDraftPlan::TOOLS[$entry['resource']['provider']])) {
			throw new Problem('Unavailable engine action.', 404);
		}
		$observed = 'recorded-attempt';
		$execution = [];
		if ($entry['snapshotAvailable']) {
			$p = $this->journal->preview($identity, $id)['snapshot']['forward']['proposal'];
			$connection = $this->native->connection($p['provider'], $p['connectionId'], false, true);
			$current = $this->observe($p, $connection);
			if (self::execution($p)) {
				$observed = $current['matches'] ? 'job_' . $current['executionState'] : 'job_unconfirmed';
				$execution = [...(isset($p['payload']['jobId']) ? ['jobId' => $p['payload']['jobId']] : ['executionId' => $current['executionId'] ?? null]),
					'executionState' => $current['executionState'], 'dispatchVerified' => $current['matches'], 'externalEffectsVerified' => false];
			} else {
				$observed = !$current['present'] ? 'draft_absent' : ($current['matches'] ? 'matches_saved_draft' : 'different_or_ambiguous');
			}
		}
		return ['action' => $this->item($entry),
			'observed' => $observed, 'journalState' => $entry['state'], 'outcome' => $entry['state'] === 'applied' ? 'verified_committed' : (in_array($entry['state'], ['cancelled', 'failed'], true) ? 'not_dispatched' : 'unknown'), 'repeatedDispatch' => false,
			...$execution, 'message' => 'Read-only inspection; the original dispatch record is retained. Native job success does not verify external effects. No retry, deployment, execution or Undo is authorized.'];
	}
	/** Existing read tool, scoped to an authorized encrypted run record. */
	public function readJob(string $provider, array $args): array {
		if (array_keys($args) !== ['actionId'] || !is_string($args['actionId']) || !preg_match('/^[a-f0-9]{32}$/D', $args['actionId'])) {
			throw new Problem('Choose one recorded Windmill action ID.', 400);
		}
		$p = $this->journal->preview($this->identity(), $args['actionId'])['snapshot']['forward']['proposal'];
		if (!self::execution($p) || $p['provider'] !== $provider) {
			throw new Problem('Choose a recorded run from this engine provider.', 400);
		}
		$source = $this->reads->read($provider, ['connectionId' => $p['connectionId']])['resource'];
		$result = $this->inspect($args['actionId']);
		$filename = EngineJson::encode((object)$args);
		$source['filename'] = $filename;
		$source['resourceId'] = 'nextcloud:' . $provider . ':' . rtrim(strtr(base64_encode($filename), '+/', '-_'), '=');
		$source['revision'] = StructuredProvider::digest([$result, $source['revision']]);
		return [...$result, 'provider' => $provider, 'connectionId' => $p['connectionId'], 'resource' => $source];
	}
}
