<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Files;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\IManager;
use OCP\Share\IShare;

/** Preserve native sharing checks while suppressing core mail on this request only. */
final class QuietShare {
	public function __construct(private IManager $shares, private IEventDispatcher $events) {}
	public function create(IShare $requested): IShare {
		$requested->setMailSend(false);
		// NC35 can reuse an existing share created concurrently, including its
		// old mailSend=true flag. The public post-create event runs before core
		// mail dispatch. Change only that request-local object's mail flag, not
		// stored permissions/attributes or unrelated shares. Remove in finally.
		$quiet = static function (ShareCreatedEvent $event) use ($requested): void {
			$share = $event->getShare();
			if ($share->getNodeId() === $requested->getNodeId() && $share->getShareType() === IShare::TYPE_USER
				&& $share->getSharedBy() === $requested->getSharedBy() && $share->getSharedWith() === $requested->getSharedWith()) $share->setMailSend(false);
		};
		$this->events->addListener(ShareCreatedEvent::class, $quiet, PHP_INT_MIN);
		try { return $this->shares->createShare($requested); }
		finally { $this->events->removeListener(ShareCreatedEvent::class, $quiet); }
	}
}
