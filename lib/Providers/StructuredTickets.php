<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\Service\Problem;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/** Atomic one-use dispatch/cancellation latch. This is not another action journal. */
final class StructuredTickets {
	public function __construct(
		private IDBConnection $db,
		private ILockingProvider $locks,
	) {
	}
	private function key(string $id): void {
		if (!preg_match('/^[a-f0-9]{48}$/D', $id)) {
			throw new Problem('Invalid native request identifier.', 400);
		}
	}
	private function query(JournalIdentity $identity, string $id): \OCP\DB\QueryBuilder\IQueryBuilder {
		$this->key($id);
		$q = $this->db->getQueryBuilder();
		$q->where($q->expr()->eq('ticket_id', $q->createNamedParameter($id)))
			->andWhere($q->expr()->eq('owner_hash', $q->createNamedParameter($identity->ownerHash)))
			->andWhere($q->expr()->eq('session_hash', $q->createNamedParameter($identity->sessionHash)))
			->andWhere($q->expr()->gt('expires_at', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT)));
		return $q;
	}
	public function state(JournalIdentity $identity, string $id): ?string {
		$q = $this->query($identity, $id);
		$value = $q->select('state')->from('fdn_provider_tickets')->executeQuery()->fetchOne();
		return is_string($value) ? $value : null;
	}
	public function start(JournalIdentity $identity, string $id, string $state = 'preparing'): void {
		$this->key($id);
		if (!in_array($state, ['preparing', 'cancelled'], true)) {
			throw new Problem('Invalid native ticket state.', 400);
		}
		// Native database lock keys are limited to 64 characters.
		$lock = hash('sha256', 'fadenstack:provider-ticket-quota:' . $identity->ownerHash);
		// This lock covers metadata insertion only. Native locks are non-blocking:
		// allow concurrent Stop/preview requests to finish their short quota check.
		// No provider effect is dispatched or retried while waiting here.
		$deadline = microtime(true) + 2.0;
		while (true) {
			try {
				$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
				break;
			} catch (LockedException) {
				if (microtime(true) >= $deadline) {
					throw new Problem('Native request metadata is busy. No action was dispatched.', 503);
				}
				usleep(25000);
			}
		}
		try {
			$q = $this->db->getQueryBuilder();
			$q->delete('fdn_provider_tickets')->where($q->expr()->eq('owner_hash', $q->createNamedParameter($identity->ownerHash)))
				->andWhere($q->expr()->lte('expires_at', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT)))->executeStatement();
			$q = $this->db->getQueryBuilder();
			$count = (int)$q->select($q->func()->count('*'))->from('fdn_provider_tickets')->where($q->expr()->eq('owner_hash', $q->createNamedParameter($identity->ownerHash)))->executeQuery()->fetchOne();
			if ($count >= 128) {
				throw new Problem('Too many recent native requests. Wait for their expiry.', 429);
			}
			$q = $this->db->getQueryBuilder();
			$active = (int)$q->select($q->func()->count('*'))->from('fdn_provider_tickets')->where($q->expr()->eq('owner_hash', $q->createNamedParameter($identity->ownerHash)))
				->andWhere($q->expr()->in('state', $q->createNamedParameter(['preparing', 'prepared', 'approving', 'approved', 'awaiting_step'], IQueryBuilder::PARAM_STR_ARRAY)))->executeQuery()->fetchOne();
			if ($active >= 20 && $state !== 'cancelled') {
				throw new Problem('Too many pending native approvals.', 429);
			}
			$q = $this->db->getQueryBuilder();
			try {
				$q->insert('fdn_provider_tickets')->values(['ticket_id' => $q->createNamedParameter($id), 'owner_hash' => $q->createNamedParameter($identity->ownerHash),
					'session_hash' => $q->createNamedParameter($identity->sessionHash), 'state' => $q->createNamedParameter($state), 'expires_at' => $q->createNamedParameter(time() + 900, IQueryBuilder::PARAM_INT)])->executeStatement();
			} catch (\Throwable) {
				throw new Problem('This native request was cancelled or already used.', 410);
			}
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
	public function move(JournalIdentity $identity, string $id, array $from, string $to): bool {
		if (array_diff($from, ['preparing', 'prepared', 'approving', 'approved', 'dispatching', 'awaiting_step', 'finished', 'cancelled']) || !in_array($to, ['prepared', 'approving', 'approved', 'dispatching', 'awaiting_step', 'finished', 'cancelled'], true)) {
			throw new Problem('Invalid native ticket transition.', 400);
		}
		$q = $this->query($identity, $id);
		return $q->update('fdn_provider_tickets')->set('state', $q->createNamedParameter($to))
			->andWhere($q->expr()->in('state', $q->createNamedParameter($from, IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement() === 1;
	}
	public function cancel(JournalIdentity $identity, string $id): bool {
		if ($this->state($identity, $id) === null) {
			try {
				$this->start($identity, $id, 'cancelled');
				return true;
			} catch (Problem $error) {
				if ($error->status !== 410) {
					throw $error;
				}
			}
		}
		return $this->move($identity, $id, ['preparing', 'prepared', 'approving', 'approved', 'cancelled'], 'cancelled');
	}
	public function prune(): int {
		$q = $this->db->getQueryBuilder();
		$ids = $q->select('ticket_id')->from('fdn_provider_tickets')->where($q->expr()->lte('expires_at', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT)))->setMaxResults(1000)->executeQuery()->fetchFirstColumn();
		if (!$ids) {
			return 0;
		}
		$q = $this->db->getQueryBuilder();
		return $q->delete('fdn_provider_tickets')->where($q->expr()->in('ticket_id', $q->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement();
	}
}
