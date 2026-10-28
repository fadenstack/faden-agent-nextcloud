<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Controller;

use OCA\FadenstackAgent\History\HistoryService;
use OCA\FadenstackAgent\History\JournalException;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class HistoryController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private FileService $files,
		private HistoryService $history,
	) {
		parent::__construct($appName, $request);
	}

	private function run(callable $operation): DataResponse {
		try {
			$this->files->check($this->request->getHeader('X-Fadenstack-Session'));
			$response = new DataResponse($operation($this->request->getHeader('X-Fadenstack-Agent-Token')));
		} catch (JournalException $error) {
			$response = new DataResponse(['message' => 'The action is unavailable, changed or blocked by another action. Inspect its current state.', 'code' => $error->reason], $error->status);
		} catch (Problem $error) {
			$response = new DataResponse(['message' => $error->getMessage()], $error->status);
		} catch (\Throwable) {
			$response = new DataResponse(['message' => 'The history operation could not be confirmed. Inspect the native file and history before retrying.'], 500);
		}
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}

	#[NoAdminRequired]
	#[UseSession]
	public function listing(): DataResponse {
		return $this->run(fn (#[\SensitiveParameter] string $token) => $this->history->listing($token));
	}

	#[NoAdminRequired]
	#[UseSession]
	public function availability(): DataResponse {
		return $this->run(fn (#[\SensitiveParameter] string $token) => $this->history->availability($token));
	}

	#[NoAdminRequired]
	#[UseSession]
	public function prepare(string $actionId, string $direction): DataResponse {
		return $this->run(fn (#[\SensitiveParameter] string $token) => $this->history->prepare($token, $actionId, $direction));
	}

	#[NoAdminRequired]
	#[UseSession]
	public function approve(string $id, string $digest): DataResponse {
		return $this->run(fn (#[\SensitiveParameter] string $token) => $this->history->approve($token, $id, $digest));
	}

	#[NoAdminRequired]
	#[UseSession]
	public function execute(string $id, string $digest): DataResponse {
		return $this->run(fn (#[\SensitiveParameter] string $token) => $this->history->execute($token, $id, $digest));
	}

	#[NoAdminRequired]
	#[UseSession]
	public function cancel(string $id, string $digest): DataResponse {
		return $this->run(fn (#[\SensitiveParameter] string $token) => $this->history->cancel($token, $id, $digest));
	}
}
