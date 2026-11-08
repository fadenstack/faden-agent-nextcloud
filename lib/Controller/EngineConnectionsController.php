<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Controller;

use OCA\FadenstackAgent\Providers\EngineBroker;
use OCA\FadenstackAgent\Providers\EngineVault;
use OCA\FadenstackAgent\Providers\EngineWebhookTargets;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** Human settings only. No agent tool can store/read credentials or choose a URL. */
final class EngineConnectionsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private FileService $files,
		private EngineVault $vault,
		private EngineBroker $broker,
		private EngineWebhookTargets $targets,
	) {
		parent::__construct($appName, $request);
	}
	private function run(callable $operation, bool $connect = false): DataResponse {
		try {
			if ($this->request->getHeader('OCS-APIRequest') !== '' || !$this->request->passesCSRFCheck()) {
				throw new Problem('A valid Nextcloud request token is required.', 403);
			}
			$binding = $this->request->getHeader('X-Fadenstack-Session');
			if ($connect) {
				$this->files->check($binding);
			} else {
				// Turning dispatch off must never prevent native credential revocation.
				$this->files->checkSession($binding);
			}
			$response = new DataResponse($operation());
		} catch (Problem $error) {
			$response = new DataResponse(['message' => $error->getMessage()], $error->status);
		} catch (\Throwable) {
			// Do not log a transport exception/argument dump containing an API key.
			$response = new DataResponse(['message' => 'The workflow connection could not be changed. Reload before trying again.'], 503);
		}
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}
	#[NoAdminRequired]
	public function listing(): DataResponse {
		return $this->run(fn () => ['connections' => $this->vault->listing()]);
	}
	#[NoAdminRequired]
	public function connect(string $id, string $revision, string $token): DataResponse {
		return $this->run(function () use ($id, $revision, $token): array {
			$this->broker->probe($id, $token);
			return $this->vault->replace($id, $revision, $token);
		}, true);
	}
	#[NoAdminRequired]
	public function disconnect(string $id, string $revision): DataResponse {
		return $this->run(fn () => $this->vault->replace($id, $revision, null));
	}
	#[NoAdminRequired]
	public function webhooks(): DataResponse {
		return $this->run(fn () => ['targets' => array_values(array_map(fn ($t) => $this->vault->webhookStatus($t), $this->targets->all()))]);
	}
	#[NoAdminRequired]
	public function webhook(string $targetId, string $revision, ?string $secret = null): DataResponse {
		return $this->run(function () use ($targetId, $revision, $secret): array {
			$t = $this->targets->get($targetId);
			return $this->vault->replaceWebhook($t, $revision, $secret);
		}, $secret !== null);
	}
}
