<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Identity\CloudIdentityVerifier;
use OCA\FadenstackAgent\Identity\CloudToolPolicy;
use OCA\FadenstackAgent\Identity\VerifiedCloudPrincipal;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCP\ISession;

/** Pair-scoped, one-use history previews. Session state contains no token or saved content. */
final class HistoryService {
	private const KEY = 'fadenstack_agent.history';

	public function __construct(
		private FileService $files,
		private Configuration $configuration,
		private CloudIdentityVerifier $cloud,
		private CloudToolPolicy $policy,
		private JournalService $journal,
		private FilesHistoryPlanner $planner,
		private FilesHistoryExecutor $executor,
		private ISession $session,
		private FilesHistoryReconciler $reconciler,
	) {
	}

	private function identity(#[\SensitiveParameter] string $token): array {
		$this->configuration->requireEnabled();
		if (!$this->configuration->historyEnabled()) {
			throw new Problem('Agent action history is not enabled on this instance.', 403);
		}
		$principal = $this->cloud->verify($token);
		return [$principal, $this->files->pairedHistoryIdentity($principal)];
	}

	private function state(): array {
		$state = $this->session->get(self::KEY);
		return is_array($state) ? array_filter($state, static fn ($p) => is_array($p) && ($p['expires'] ?? 0) > time()) : [];
	}

	private function assertDirection(string $direction): void {
		if (!in_array($direction, ['undo', 'redo', 'reconcile'], true)) {
			throw new Problem('Invalid history direction.', 400);
		}
		if (!in_array('nc_actions_' . $direction, $this->files->identity()['scopePolicy']['tools'] ?? [], true)) {
			throw new Problem('This history command is outside the configured native tool scope.', 403);
		}
	}

	/** Native history UI metadata, not a model tool or a generic snapshot endpoint. */
	public function listing(#[\SensitiveParameter] string $token): array {
		[, $identity] = $this->identity($token);
		$items = $this->journal->list($identity);
		$standardPolicy = ($this->files->identity()['scopePolicy']['sourceClasses'] ?? []) === ['standard'];
		usort($items, static fn ($a, $b) => $b['createdAt'] <=> $a['createdAt']);
		$result = [];
		foreach ($items as $item) {
			if ($item['operation'] !== 'nc_update_text_file' || !preg_match('/^file:([1-9][0-9]{0,14})$/D', $item['resource']['authorityReference'], $match)) {
				continue;
			}
			try {
				$file = $this->files->resource('nextcloud:files:' . $match[1]);
				$eligible = $standardPolicy && ($item['resource']['recovery'] ?? null) === JournalResource::PRIVATE_TEXT_RECOVERY
					&& $file['agentScope']['sourceClass'] === 'standard' && $file['agentScope']['audience'] === 'private';
				$command = $item['snapshotAvailable'] && in_array($item['state'], FilesHistoryReconciler::STATES, true) ? 'reconcile' : $item['journalEligibility'];
				$result[] = ['id' => $item['id'], 'name' => $file['name'], 'target' => $file['path'], 'state' => $item['state'],
					'eligible' => $eligible ? $command : 'none', 'version' => $item['version'], 'createdAt' => $item['createdAt']];
			} catch (Problem) {
				continue;
			}
			if (count($result) === 50) {
				break;
			}
		}
		return $result;
	}

	public function prepare(#[\SensitiveParameter] string $token, string $actionId, string $direction): array {
		[$principal, $identity] = $this->identity($token);
		$this->assertDirection($direction);
		$plan = $this->recoveryPlan($identity, $actionId, $direction);
		$policy = $this->policy->check($token, $principal, $direction, $actionId);
		$this->assertFresh($token, $identity);
		$plan = $plan instanceof FilesReconciliationPlan ? $this->reconciler->revalidate($identity, $plan) : $this->planner->revalidate($identity, $plan);
		$state = $this->state();
		if (count($state) >= 10) {
			throw new Problem('Too many pending history previews. Wait for them to expire.', 429);
		}
		$id = bin2hex(random_bytes(24));
		$state[$id] = ['actionId' => $actionId, 'direction' => $direction, 'digest' => $plan->digest,
			'scope' => $identity->scopeHash, 'binding' => $identity->sessionHash, 'expires' => time() + 600,
			'policy' => $policy, 'approved' => false];
		$this->session->set(self::KEY, $state);
		return ['id' => $id, 'actionId' => $actionId, 'direction' => $direction, 'digest' => $plan->digest,
			'target' => $plan->target, 'before' => $plan->before, 'content' => $plan->content, 'expires' => $state[$id]['expires'],
			...($plan instanceof FilesReconciliationPlan ? ['resolution' => ['outcome' => $plan->outcome, 'state' => $plan->settledState]] : [])];
	}

	private function load(JournalIdentity $identity, string $id, string $digest): array {
		if (!preg_match('/^[a-f0-9]{48}$/D', $id) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
			throw new Problem('The history preview is unavailable.', 410);
		}
		$pending = $this->state()[$id] ?? null;
		if ($pending && hash_equals($pending['scope'], $identity->scopeHash) && !hash_equals($pending['binding'], $identity->sessionHash)) {
			// Once this actor observes a device/profile/policy change, the old
			// approval stays invalid even if the previous configuration returns.
			$state = $this->state();
			unset($state[$id]);
			$this->session->set(self::KEY, $state);
		}
		if (!$pending || !hash_equals($pending['scope'], $identity->scopeHash) || !hash_equals($pending['binding'], $identity->sessionHash)) {
			throw new Problem('The history preview expired or its identity changed. Review it again.', 410);
		}
		if (!hash_equals($pending['digest'], $digest)) {
			throw new Problem('The history preview changed. Review it again.', 409);
		}
		return $pending;
	}

	private function plan(JournalIdentity $identity, array $pending): FilesHistoryPlan|FilesReconciliationPlan {
		$plan = $this->recoveryPlan($identity, $pending['actionId'], $pending['direction']);
		if (!hash_equals($pending['digest'], $plan->digest)) {
			throw new Problem('The saved action or native file changed. Review a new preview.', 409);
		}
		return $plan;
	}

	private function recoveryPlan(JournalIdentity $identity, string $actionId, string $direction): FilesHistoryPlan|FilesReconciliationPlan {
		$entry = $this->journal->describe($identity, $actionId);
		if (($entry['resource']['recovery'] ?? null) !== JournalResource::PRIVATE_TEXT_RECOVERY
			|| ($this->files->identity()['scopePolicy']['sourceClasses'] ?? []) !== ['standard']) {
			throw new JournalException('recovery_protection_unavailable', 422);
		}
		return $direction === 'reconcile' ? $this->reconciler->prepare($identity, $actionId) : $this->planner->prepare($identity, $actionId, $direction);
	}

	private function assertFresh(#[\SensitiveParameter] string $token, JournalIdentity $expected): VerifiedCloudPrincipal {
		[$principal, $current] = $this->identity($token);
		if ($current->scopeHash !== $expected->scopeHash || $current->sessionHash !== $expected->sessionHash) {
			throw new Problem('The account, device, profile or native scope changed. Review again.', 409);
		}
		return $principal;
	}

	public function approve(#[\SensitiveParameter] string $token, string $id, string $digest): array {
		[, $identity] = $this->identity($token);
		$pending = $this->load($identity, $id, $digest);
		$this->assertDirection($pending['direction']);
		$this->plan($identity, $pending);
		$state = $this->state();
		$state[$id]['approved'] = true;
		$this->session->set(self::KEY, $state);
		return ['approved' => true];
	}

	public function execute(#[\SensitiveParameter] string $token, string $id, string $digest): array {
		[$principal, $identity] = $this->identity($token);
		$pending = $this->load($identity, $id, $digest);
		$this->assertDirection($pending['direction']);
		if (!$pending['approved']) {
			throw new Problem('This history action requires individual approval.', 403);
		}
		// Consume before any dispatch attempt, including a policy/storage failure.
		$state = $this->state();
		unset($state[$id]);
		$this->session->set(self::KEY, $state);
		$plan = $this->plan($identity, $pending);
		$this->policy->check($token, $principal, $pending['direction'], $pending['actionId'], $pending['policy']);
		$this->assertFresh($token, $identity);
		$result = $plan instanceof FilesReconciliationPlan ? $this->reconciler->execute($identity, $plan) : $this->executor->execute($identity, $plan);
		return [...$result, 'operation' => $pending['direction'], 'file' => $this->files->resource('nextcloud:files:' . $result['fileId'])];
	}

	public function cancel(#[\SensitiveParameter] string $token, string $id, string $digest): array {
		[, $identity] = $this->identity($token);
		$this->load($identity, $id, $digest);
		$state = $this->state();
		unset($state[$id]);
		$this->session->set(self::KEY, $state);
		return ['cancelled' => true];
	}
}
