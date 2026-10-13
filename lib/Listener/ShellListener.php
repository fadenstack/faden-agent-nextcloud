<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Listener;

use OCA\FadenstackAgent\AppInfo\Application;
use OCA\FadenstackAgent\Service\Configuration;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\IContentSecurityPolicyManager;
use OCP\Util;

/** @implements IEventListener<BeforeTemplateRenderedEvent> */
class ShellListener implements IEventListener {
	public function __construct(
		private Configuration $config,
		private IInitialState $state,
		private IContentSecurityPolicyManager $csp,
	) {
	}
	public function handle(Event $event): void {
		if (!$event instanceof BeforeTemplateRenderedEvent || !$event->isLoggedIn() || $event->getResponse()->getRenderAs() !== 'user') {
			return;
		}
		$config = $this->config->get();
		$this->state->provideInitialState('config', $config);
		if ($config['enabled'] && $config['serverUrl']) {
			$policy = new ContentSecurityPolicy();
			$policy->addAllowedConnectDomain(Configuration::origin($config['serverUrl'], $config['development']));
			$policy->addAllowedWorkerSrcDomain("'self'");
			$this->csp->addDefaultPolicy($policy);
		}
		Util::addInitScript(Application::APP_ID, Application::APP_ID . '-assistant');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-assistant');
	}
}
