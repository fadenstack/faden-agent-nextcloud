<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Listener;

use OCA\FadenstackAgent\Service\DataErasure;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * When a Nextcloud account is deleted, its undo history and open tickets go with it (GDPR Art. 17) rather than
 * waiting out the 7- and 30-day expiry. A failure never blocks the deletion: it is logged, and expiry still applies.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private DataErasure $erasure,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		try {
			$count = $this->erasure->forUser($event->getUser()->getUID());
			$this->logger->info('Agent history of a deleted account erased.', ['app' => 'fadenstack_agent', 'changes' => $count]);
		} catch (\Throwable $error) {
			$this->logger->warning('Agent history of a deleted account could not be erased now; it expires within 30 days.',
				['app' => 'fadenstack_agent', 'exception' => $error]);
		}
	}
}
