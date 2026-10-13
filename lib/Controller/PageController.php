<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IURLGenerator $urls,
	) {
		parent::__construct($appName, $request);
	}
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): RedirectResponse {
		// A direct/new-tab link opens Files with the drawer. Normal app-menu clicks
		// are handled by the global shell script and never leave the current app.
		return new RedirectResponse($this->urls->linkToRoute('files.view.index', ['fadenstack-agent' => '1']));
	}
}
