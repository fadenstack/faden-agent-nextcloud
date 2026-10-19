<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Listener;

use OCA\FadenstackAgent\Service\ProvenanceService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;

/**
 * Nextcloud copies a file's bytes but not its metadata: the copy of a file the agent wrote, or of a folder holding
 * such files, gets their signed records here, so the copies stay marked as made with AI.
 *
 * @template-implements IEventListener<NodeCopiedEvent>
 */
class CopyListener implements IEventListener {
	public function __construct(
		private ProvenanceService $provenance,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof NodeCopiedEvent) {
			return;
		}
		$this->provenance->copy($event->getSource(), $event->getTarget());
	}
}
