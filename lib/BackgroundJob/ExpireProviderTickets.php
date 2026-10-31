<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\BackgroundJob;

use OCA\FadenstackAgent\Providers\StructuredTickets;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class ExpireProviderTickets extends TimedJob {
	public function __construct(ITimeFactory $time, private StructuredTickets $tickets) { parent::__construct($time); $this->setInterval(300); }
	protected function run($argument): void { $this->tickets->prune(); }
}
