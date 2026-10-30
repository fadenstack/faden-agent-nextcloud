<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\Service\Problem;
use OCP\ISession;
use OCP\IUserSession;

/** Native-owner audit only. Does not impersonate a cloud principal or expose saved bodies. */
final class ProviderAuditService {
	public function __construct(private JournalService $journal, private ProviderAuthority $authority,
		private ProviderPolicy $policy, private IUserSession $users, private ISession $session) {
	}

	private function identity(): JournalIdentity {
		$uid = $this->users->getUser()?->getUID();
		if (!$uid) {
			throw new Problem('Sign in to Nextcloud.', 401);
		}
		return new JournalIdentity($uid, JournalIdentity::nativeScope(), hash('sha256', $this->session->getId()));
	}

	private function item(JournalIdentity $identity, array $item): array {
		$resource = JournalResource::fromArray($item['resource']);
		$target = $this->authority->target($resource);
		$current = $this->authority->inspect($resource);
		$policy = $this->policy->audit($resource->provider);
		if ($item['snapshotAvailable']) {
			$snapshot = $this->journal->preview($identity, $item['id'])['snapshot'];
			$forward = $snapshot['forward'];
			$class = $forward['sourceClass'] ?? (isset($forward['content']) && $forward['content'] !== '' ? CalendarClassification::sourceClass($forward['content'], $policy['sourceClass']) : 'unknown');
			$this->policy->assertDisclosure($policy, $class);
		} elseif (!$current['present']) {
			// Expired legacy deletion metadata cannot establish its original classification.
			throw new Problem('Earlier action metadata cannot be safely disclosed.', 403);
		}
		return ['id' => $item['id'], 'provider' => $resource->provider, 'name' => $target['filename'],
			'target' => $target['collectionId'] . '/' . $target['filename'], 'state' => $item['state'],
			'eligible' => 'none', 'auditOnly' => true, 'version' => $item['version'], 'createdAt' => $item['createdAt']];
	}

	public function listing(): array {
		$identity = $this->identity();
		$items = $this->journal->list($identity, null, ['calendar', 'tasks', 'contacts'], 50);
		usort($items, static fn (array $a, array $b): int => $b['createdAt'] <=> $a['createdAt']);
		$result = [];
		foreach ($items as $item) {
			if (!in_array($item['resource']['provider'], ['calendar', 'tasks', 'contacts'], true)) {
				continue;
			}
			try {
				$result[] = $this->item($identity, $item);
			} catch (\Throwable) {
				continue; // No titles or references from unverifiable/revoked resources.
			}
			if (count($result) >= 50) {
				break;
			}
		}
		return $result;
	}

	public function inspect(string $id): array {
		if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
			throw new Problem('Unavailable provider action.', 404);
		}
		$identity = $this->identity();
		$item = $this->journal->describe($identity, $id);
		$view = $this->item($identity, $item);
		$resource = JournalResource::fromArray($item['resource']);
		$current = $this->authority->inspect($resource);
		$observed = $current['present'] ? 'present' : 'absent';
		if ($current['present'] && $item['snapshotAvailable']) {
			$snapshot = $this->journal->preview($identity, $id)['snapshot'];
			if (isset($snapshot['forward']['content']) && hash_equals(hash('sha256', $snapshot['forward']['content']), hash('sha256', $current['content']))) {
				$observed = 'matches_saved_content';
			} else {
				$observed = 'different_content';
			}
		}
		$attempts = array_map(static fn (array $attempt): array => array_intersect_key($attempt, array_flip(['version', 'outcome', 'state', 'created_at'])), $this->journal->attempts($identity, $id));
		return ['action' => $view, 'observed' => $observed, 'journalState' => $item['state'], 'attempts' => $attempts,
			'outcome' => in_array($item['state'], ['applied', 'undone'], true) ? 'verified_committed' : 'unknown',
			'repeatedDispatch' => false, 'historySupport' => 'audit-only',
			'message' => in_array($item['state'], ['applied', 'undone'], true)
				? 'The recorded commitment is verified. Inspection reports the current native state and sends no write.'
				: 'The original completion remains uncertain. Inspection sends no write and does not authorize a retry.'];
	}
}
