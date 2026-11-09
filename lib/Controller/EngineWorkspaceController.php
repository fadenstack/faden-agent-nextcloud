<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Controller;

use OCA\FadenstackAgent\Providers\EngineWorkspace;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

final class EngineWorkspaceController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private EngineWorkspace $workspaces,
		private FileService $files,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function listing(): DataResponse {
		try {
			if ($this->request->getHeader('OCS-APIRequest') !== '' || !$this->request->passesCSRFCheck()) {
				throw new Problem('A valid Nextcloud request token is required.', 403);
			}
			$this->files->check($this->request->getHeader('X-Fadenstack-Session'));
			$response = new DataResponse(['workspaces' => $this->workspaces->listing()]);
		} catch (Problem $error) {
			$response = new DataResponse(['message' => $error->getMessage()], $error->status);
		} catch (\Throwable) {
			$response = new DataResponse(['message' => 'Native workflow workspaces are unavailable.'], 503);
		}
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}

	/** Authenticated read-only navigation; no credentials enter the frame URL. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function page(string $id): TemplateResponse {
		try {
			$workspace = $this->workspaces->resolve($id);
			$response = new TemplateResponse('fadenstack_agent', 'engine-workspace', ['workspace' => $workspace]);
			$policy = new ContentSecurityPolicy();
			$policy->addAllowedFrameDomain($workspace['origin']);
			$response->setContentSecurityPolicy($policy);
		} catch (\Throwable $error) {
			$response = new TemplateResponse('fadenstack_agent', 'engine-workspace', ['error' => $error instanceof Problem ? $error->getMessage() : 'Native workflow workspace unavailable.']);
			$response->setStatus($error instanceof Problem ? $error->status : 503);
		}
		$response->addHeader('Cache-Control', 'no-store');
		$response->addHeader('Referrer-Policy', 'no-referrer');
		return $response;
	}
}
