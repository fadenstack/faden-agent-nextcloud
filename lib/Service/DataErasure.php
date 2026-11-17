<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Service;

use OCA\FadenstackAgent\AppInfo\Application;
use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\SqlJournalStore;
use OCA\FadenstackAgent\Providers\StructuredTickets;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Erases what the app keeps about people (GDPR Art. 5(1)(e) and Art. 17): the undo journal with its encrypted
 * snapshots, open provider tickets, and each user's sealed workflow-engine credentials. The organisation's settings
 * (server address, agent, policies) are not personal data and stay, so a switched-off app comes back paired.
 * Files the agent wrote keep their AI marking: that belongs to the file, not to the app.
 */
final class DataErasure {
	private const TABLES = ['fdn_journal', 'fdn_jr_alloc', 'fdn_jr_attempts', 'fdn_jr_owners', 'fdn_jr_resources',
		'fdn_jr_barriers', 'fdn_jr_control', 'fdn_provider_tickets'];

	public function __construct(
		private IDBConnection $db,
		private SqlJournalStore $journal,
		private StructuredTickets $tickets,
		private IConfig $config,
	) {
	}

	/**
	 * A deleted Nextcloud user: their journal rows and open tickets. Nextcloud itself deletes the user's preferences,
	 * which hold the sealed engine credentials. Returns the number of journal actions erased.
	 */
	public function forUser(string $uid): int {
		if (!$this->installed()) {
			return 0;
		}
		$owner = JournalIdentity::ownerHashOf($uid);
		$count = $this->journal->atomic(fn (): int => $this->journal->eraseOwner($owner));
		$this->tickets->erase($owner);
		return $count;
	}

	/**
	 * The app is switched off or removed. Nextcloud runs the same uninstall steps for both, so nothing here may break
	 * a later re-enable: the tables stay (empty), and the organisation's settings stay.
	 */
	public function everything(): void {
		if ($this->installed()) {
			$this->journal->atomic(function (): void {
				$this->journal->eraseAll();
			});
			$this->tickets->erase(null);
		}
		$this->config->deleteAppFromAllUsers(Application::APP_ID);
	}

	private function installed(): bool {
		foreach (self::TABLES as $table) {
			if (!$this->db->tableExists($table)) {
				return false;
			}
		}
		return true;
	}
}
