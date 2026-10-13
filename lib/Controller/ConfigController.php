<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Controller;

use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ConfigController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private Configuration $config,
		private FileService $files,
	) {
		parent::__construct($appName, $request);
	}
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession]
	public function get(): DataResponse {
		$r = new DataResponse($this->files->identity());
		$r->addHeader('Cache-Control', 'no-store');
		return $r;
	}
	// Nextcloud's default middleware requires an administrator and a valid CSRF token.
	public function save(string $serverUrl, string $agent, bool $enabled = false, bool $development = false): DataResponse {
		try {
			return new DataResponse($this->config->save($serverUrl, $agent, $enabled, $development));
		} catch (Problem $e) {
			return new DataResponse(['message' => $e->getMessage()], $e->status);
		}
	}
}
