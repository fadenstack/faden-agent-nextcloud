<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\BackgroundJob;

use OCA\FadenstackAgent\History\JournalService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Removes expired app-owned snapshots even when their owner no longer uses the assistant. */
final class ExpireJournal extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private JournalService $journal,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void {
		$this->journal->pruneExpired(1000);
	}
}
