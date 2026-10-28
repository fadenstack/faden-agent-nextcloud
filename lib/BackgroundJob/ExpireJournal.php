<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\BackgroundJob;

use OCA\FadenstackAgent\History\JournalService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Removes expired app-owned snapshots even when their owner no longer uses the assistant. */
final class ExpireJournal extends TimedJob {
	public function __construct(
		private ITimeFactory $retentionClock,
		private JournalService $journal,
		private ?LoggerInterface $logger = null,
	) {
		parent::__construct($retentionClock);
		$this->setInterval(3600);
	}

	protected function run($argument): void {
		$count = $this->journal->pruneExpired(1000);
		if ($count > 0) {
			$this->logger?->info('Agent history retention completed.', ['app' => 'fadenstack_agent', 'scope' => 'global', 'changes' => $count, 'time' => $this->retentionClock->getTime()]);
		}
	}
}
