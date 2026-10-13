<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Settings;

use OCA\FadenstackAgent\AppInfo\Application;
use OCA\FadenstackAgent\Service\Configuration;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private Configuration $config,
		private IInitialState $state,
	) {
	}
	public function getForm(): TemplateResponse {
		$this->state->provideInitialState('admin-config', $this->config->get());
		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-admin');
		return new TemplateResponse(Application::APP_ID, 'admin');
	}
	public function getSection(): string {
		return 'additional';
	}
	public function getPriority(): int {
		return 50;
	}
}
