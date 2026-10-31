<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\DAV;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** @implements IEventListener<SabrePluginAddEvent> */
class SabrePluginListener implements IEventListener {
	public function __construct(
		private FolderCreateGuardPlugin $guardPlugin,
		private FileOperationsGuardPlugin $fileOperations,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof SabrePluginAddEvent) {
			$event->getServer()->addPlugin($this->guardPlugin);
			$event->getServer()->addPlugin($this->fileOperations);
		}
	}
}
