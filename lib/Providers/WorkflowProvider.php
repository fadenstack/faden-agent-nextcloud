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

/** One exact approval, one durable dispatch, independent native readback. */
final class WorkflowProvider {
	private const KEY = 'fadenstack_agent.workflow';
	private ?string $attempt = null;
	private bool $dispatched = false;
	public function __construct(
		private WorkflowAuthority $native,
		private WorkflowPlan $plans,
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
		return $this->dispatched ? 'unknown' : 'not-dispatched';
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
		foreach (WorkflowPlan::TOOLS as $provider => $names) {
			try {
				$policy = $this->native->policy($provider);
				if ($provider === 'flow') {
					$this->native->rules($policy);
				}
				$tools = $policy['writesEnabled'] && $this->configuration->folderDispatchSupported() ? $names : [$names[0]];
				if ($provider === 'whiteboard') {
					$flags = $this->native->boardScopeFlags($policy);
					if (!$flags['read']) {
						$tools = array_values(array_diff($tools, [$names[0]]));
					}
					if (!$flags['create']) {
						$tools = array_values(array_diff($tools, [$names[1]]));
					}
				}
				$tools = array_values(array_intersect($tools, $policy['processing']['tools']));
				if ($tools) {
					$result[] = ['provider' => $provider, 'apiVersion' => $provider . '-' . WorkflowAuthority::VERSIONS[$provider] . '-session', 'policyVersion' => $policy['policyVersion'], 'enabled' => true, 'tools' => $tools,
						'conflict' => count(array_diff($tools, [$names[0]])) ? ($provider === 'whiteboard' ? 'conditional' : 'native') : 'read-only', 'history' => 'audit-only', 'refresh' => $provider === 'whiteboard' ? 'manual' : 'child-reload', 'canRead' => in_array($names[0], $tools, true), 'canWrite' => count(array_diff($tools, [$names[0]])) > 0];
				}
			} catch (Problem $error) {
				if (!in_array($error->status, [403, 404, 413, 429, 503], true)) {
					throw $error;
				}
				// A missing/limited native provider never disables other providers.
			}
		}
		return $result;
	}
	public function read(string $provider, array $args): array {
		if (!in_array('nc_' . $provider . '_read', $this->native->policy($provider)['processing']['tools'], true)) {
			throw new Problem('This workflow read is outside the administrator tool scope.', 403);
		}
		return $this->plans->read($provider, $args);
	}
	private function validate(array $proposal): void {
		$now = $this->plans->prepare($proposal['toolName'], $proposal['args']);
		if (!hash_equals(StructuredProvider::digest(array_intersect_key($proposal, $now)), StructuredProvider::digest($now))) {
			throw new Problem('Native target, payload, conditions, tag, permissions, audience or policy changed.', 409);
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
				throw new Problem('Too many pending workflow approvals.', 429);
			}
			$state[$requestId] = $p;
			$this->session->set(self::KEY, $state);
			if (!$this->tickets->move($identity, $requestId, ['preparing'], 'prepared')) {
				throw new Problem('The workflow preview was stopped.', 410);
			}
			return array_intersect_key($p, array_flip(['id', 'digest', 'toolName', 'provider', 'target', 'before', 'payload', 'resource', 'display', 'limitation', 'history', 'expires']));
		} catch (\Throwable $error) {
			$this->tickets->cancel($identity, $requestId);
			throw $error;
		}
	}
	private function load(string $id, string $digest): array {
		if (!preg_match('/^[a-f0-9]{48}$/D', $id) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
			throw new Problem('Invalid workflow preview.', 400);
		}
		$p = $this->state()[$id] ?? null;
		if (!$p || $p['expires'] <= time() || !hash_equals($p['digest'], $digest) || !hash_equals($p['binding'], $this->binding($p['resource'])) || !in_array($this->tickets->state($this->identity(), $id), ['prepared', 'approved'], true)) {
			throw new Problem('The workflow preview expired, was cancelled or consumed.', 410);
		}
		return $p;
	}
	public function approve(string $id, string $digest): array {
		$p = $this->load($id, $digest);
		$this->validate($p);
		if ($p['approved']) {
			return ['approved' => true];
		}
		$identity = $this->identity();
		if (!$this->tickets->move($identity, $id, ['prepared'], 'approving')) {
			throw new Problem('The workflow approval was consumed or stopped.', 410);
		}
		try {
			$authority = $p['provider'] === 'whiteboard' ? 'folder:' . $p['payload']['folderId'] : 'personal';
			$entry = $this->journal->prepare($identity, new JournalResource($p['provider'], 'action:' . $id, $authority), $p['toolName'], ['proposal' => $p, 'sourceClass' => $p['resource']['sourceClass']], [], []);
			$p['actionId'] = $entry['id'];
			$p['ticket'] = $entry['ticket'];
			$p['approved'] = true;
			$state = $this->state();
			$state[$id] = $p;
			$this->session->set(self::KEY, $state);
			if (!$this->tickets->move($identity, $id, ['approving'], 'approved')) {
				$this->journal->abort($identity, $p['actionId'], $p['ticket']);
				throw new Problem('Workflow approval was stopped.', 410);
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
			} catch (\Throwable) { /* retain durable uncertainty */
			}
		}
		return ['cancelled' => $stopped, 'message' => 'Further dispatch is stopped. An in-flight request or automation may still commit. Inspect without repeating it.'];
	}
	public function commit(string $id, string $digest): array {
		$p = $this->load($id, $digest);
		if (!$p['approved']) {
			throw new Problem('Individual approval is required.', 403);
		}
		$this->attempt = $p['actionId'];
		$identity = $this->identity();
		$lock = hash('sha256', 'fadenstack:workflow:' . $p['provider'] . ':' . $identity->ownerHash);
		try {
			$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			$this->dispatched = !in_array($this->tickets->state($identity, $id), ['prepared', 'approved', 'cancelled'], true);
			throw new Problem('Another workflow attempt is in progress. Inspect without repeating it.', 409);
		}
		$folder = null;
		try {
			$policy = $this->native->policy($p['provider']);
			if ($p['provider'] === 'whiteboard') {
				$value = $this->native->destination($policy, 'nextcloud:files:' . $p['payload']['folderId'], $p['payload']['name']);
				$value['node']->lock(ILockingProvider::LOCK_SHARED);
				$folder = $value['node'];
			}
			$this->validate($p);
			if (!$this->tickets->move($identity, $id, ['approved'], 'dispatching')) {
				throw new Problem('This workflow request was stopped or dispatched.', 410);
			}
			$this->journal->markDispatched($identity, $p['actionId'], $p['ticket']);
			$this->dispatched = true;
			if ($p['provider'] === 'whiteboard') {
				$response = $this->native->createBoard($policy, $p['payload']['folderPath'], $p['payload']['name']);
				if ($response['status'] !== 201) {
					throw new Problem('Native board creation could not be confirmed.', 503);
				}
				$value = $this->native->board($policy, $p['target']);
				if (!hash_equals(hash('sha256', WorkflowPlan::EMPTY_BOARD), $value['digest']) || $value['node']->getParent()->getId() !== $p['payload']['folderId']) {
					throw new Problem('The created board differs from the approved destination.', 503);
				}
				$result = ['id' => $value['node']->getId(), 'folderId' => $p['payload']['folderId'], 'resourceId' => $value['scope']['resourceId'], 'path' => $p['target'], 'storedOnly' => true];
				$actual = new JournalResource('whiteboard', 'action:' . $id, 'file:' . $result['id']);
			} else {
				$response = $this->native->request($policy, $p['operation'], $p['itemId'], $p['payload']);
				$nativeId = $p['operation'] === 'create' ? ContentAuthority::id($response['id'] ?? null) : $p['itemId'];
				$actualRule = $this->native->rules($policy)[$nativeId] ?? null;
				if ($p['operation'] === 'delete') {
					if ($response !== true || $actualRule !== null) {
						throw new Problem('Native rule deletion could not be verified.', 503);
					}
				} elseif (!$actualRule || self::recipeDigest($actualRule) !== self::recipeDigest($p['payload'])) {
					throw new Problem('The saved Flow rule differs from the approved payload.', 503);
				}
				$result = ['id' => $nativeId, 'deleted' => $p['operation'] === 'delete', 'active' => $actualRule ? count($actualRule['checks']) === 1 : false, 'blockedDraft' => $actualRule ? count($actualRule['checks']) === 2 : false];
				$actual = null;
			}
			$this->journal->finish($identity, $p['actionId'], $p['ticket'], 'verified', new VerifiedReadback(StructuredProvider::digest($result), $actual));
			$this->tickets->move($identity, $id, ['dispatching'], 'finished');
			$state = $this->state();
			unset($state[$id]);
			$this->session->set(self::KEY, $state);
			return ['outcome' => 'verified', 'continue' => false, 'actionId' => $p['actionId'], 'provider' => $p['provider'], 'collectionId' => 'personal', 'target' => $p['target'], 'result' => $result, 'history' => 'audit-only', 'limitation' => $p['limitation']];
		} catch (\Throwable $error) {
			try {
				if ($this->dispatched) {
					$this->journal->finish($identity, $p['actionId'], $p['ticket'], 'unknown');
				} else {
					$this->journal->abort($identity, $p['actionId'], $p['ticket']);
				}
			} catch (\Throwable) { /* retain durable uncertainty */
			}
			$this->tickets->move($identity, $id, ['approved', 'dispatching'], 'finished');
			throw $error;
		} finally {
			if ($folder !== null) {
				$folder->unlock(ILockingProvider::LOCK_SHARED);
			}
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
	private static function recipeDigest(array $rule): string {
		return StructuredProvider::digest(WorkflowPlan::validateRecipe($rule));
	}
	public function actions(): array {
		return array_map(static fn ($e) => ['id' => $e['id'], 'provider' => $e['resource']['provider'], 'name' => 'Native workflow action', 'target' => ucfirst($e['resource']['provider']), 'state' => $e['state'], 'eligible' => 'none', 'auditOnly' => true, 'version' => $e['version'], 'createdAt' => $e['createdAt']], $this->journal->list($this->identity(), null, array_keys(WorkflowPlan::TOOLS), 50));
	}
	public function inspect(string $id): array {
		$entry = $this->journal->describe($this->identity(), $id);
		if (!isset(WorkflowPlan::TOOLS[$entry['resource']['provider']])) {
			throw new Problem('Unavailable native workflow action.', 404);
		}
		return ['action' => ['id' => $id, 'provider' => $entry['resource']['provider'], 'name' => 'Native workflow action', 'target' => ucfirst($entry['resource']['provider']), 'state' => $entry['state'], 'eligible' => 'none', 'auditOnly' => true, 'version' => $entry['version'], 'createdAt' => $entry['createdAt']], 'observed' => 'recorded-attempt', 'journalState' => $entry['state'], 'outcome' => $entry['state'] === 'applied' ? 'verified_committed' : 'unknown', 'repeatedDispatch' => false, 'message' => 'Historical commitment only. Inspect the native saved board or personal rule for its current state. Inspection never repeats a write. No Undo is certified.'];
	}
}
