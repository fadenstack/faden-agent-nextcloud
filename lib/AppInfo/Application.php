<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\AppInfo;

use OCA\FadenstackAgent\Listener\CopyListener;
use OCA\FadenstackAgent\Listener\ShellListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'fadenstack_agent';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, ShellListener::class);
		// Copies of the files the agent wrote keep their AI mark (the signed record in the file metadata).
		$context->registerEventListener(NodeCopiedEvent::class, CopyListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
