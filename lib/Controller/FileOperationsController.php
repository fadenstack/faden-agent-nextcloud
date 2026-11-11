<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Controller;

use OCA\FadenstackAgent\Files\Audit;
use OCA\FadenstackAgent\Files\Operations;
use OCA\FadenstackAgent\Identity\CloudIdentityVerifier;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\ISession;

final class FileOperationsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private Operations $operations,
		private FileService $files,
		private CloudIdentityVerifier $cloud,
		private Configuration $configuration,
		private ISession $session,
		private Audit $audit,
	) {
		parent::__construct($appName, $request);
	}
	private function run(callable $action, bool $cleanup = false): DataResponse {
		try {
			$this->session->reopen();
			$this->files->checkSession($this->request->getHeader('X-Fadenstack-Session'));
			if (!$cleanup) {
				$this->configuration->requireEnabled();
				$token = $this->request->getHeader('X-Fadenstack-Agent-Token');
				if ($token !== '' || $this->configuration->historyEnabled()) {
					$this->files->bindCloudPrincipal($this->cloud->verify($token));
				}
			}
			$response = new DataResponse($action());
		} catch (Problem $error) {
			$response = new DataResponse(['message' => $error->getMessage(), 'actionId' => $this->operations->attemptId()], $error->status);
		} catch (\Throwable) {
			$response = new DataResponse(['message' => 'The native Files result could not be confirmed. Inspect before retrying.', 'actionId' => $this->operations->attemptId()], 503);
		}
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}
	#[NoAdminRequired]
	public function capabilities(): DataResponse {
		return $this->run(fn () => ['tools' => $this->operations->capabilities()]);
	}
	#[NoAdminRequired]
	public function actions(): DataResponse {
		return $this->run(fn () => $this->audit->listing());
	}
	#[NoAdminRequired]
	public function inspect(string $id): DataResponse {
		return $this->run(fn () => $this->audit->inspect($id));
	}
	#[NoAdminRequired]
	public function propose(string $operation, string $sourceResourceId, string $expectedEtag, string $destinationResourceId = '', string $destinationName = '', string $recipientUserId = '', string $clientRequestId = ''): DataResponse {
		return $this->run(fn () => $this->operations->propose($operation, $sourceResourceId, $expectedEtag, $destinationResourceId, $destinationName, $recipientUserId, $clientRequestId));
	}
	#[NoAdminRequired]
	public function approve(string $id, string $digest): DataResponse {
		return $this->run(fn () => $this->operations->approve($id, $digest));
	}
	#[NoAdminRequired]
	public function cancel(string $id): DataResponse {
		return $this->run(fn () => $this->operations->cancel($id), true);
	}
	#[NoAdminRequired]
	public function commit(string $id, string $digest): DataResponse {
		return $this->run(fn () => $this->operations->commit($id, $digest, $this->request->getHeader('X-Fadenstack-Session'), $this->request->getHeader('X-Fadenstack-Agent-Token')));
	}
}
