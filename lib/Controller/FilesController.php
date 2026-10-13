<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Controller;

use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

class FilesController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private FileService $files,
	) {
		parent::__construct($appName, $request);
	}

	private function run(callable $operation): Response {
		try {
			$this->files->check($this->request->getHeader('X-Fadenstack-Session'));
			$result = $operation();
			$response = $result instanceof Response ? $result : new DataResponse($result);
		} catch (Problem $e) {
			$response = new DataResponse(['message' => $e->getMessage()], $e->status);
		} catch (\OCP\Files\NotFoundException $e) {
			$response = new DataResponse(['message' => 'The file is unavailable.'], 404);
		} catch (\OCP\Files\ForbiddenException $e) {
			$response = new DataResponse(['message' => 'Permission denied.'], 403);
		} catch (\OCP\Files\NotPermittedException $e) {
			$response = new DataResponse(['message' => 'Permission denied.'], 403);
		} catch (\OCP\Lock\LockedException $e) {
			$response = new DataResponse(['message' => 'The file is busy. Try again.'], 409);
		} catch (\Throwable $e) {
			$response = new DataResponse(['message' => 'The file operation failed. No success is claimed.'], 500);
		}
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}

	#[NoAdminRequired]
	#[UseSession]
	public function select(int $fileId): Response {
		return $this->run(fn () => $this->files->select($fileId));
	}
	#[NoAdminRequired]
	#[UseSession]
	public function read(string $contextId): Response {
		return $this->run(fn () => $this->files->read($contextId));
	}
	#[NoAdminRequired]
	#[UseSession]
	public function content(string $contextId): Response {
		return $this->run(fn () => new DataDownloadResponse($this->files->pdf($contextId), 'context.pdf', 'application/pdf'));
	}
	#[NoAdminRequired]
	#[UseSession]
	public function listing(string $contextId): Response {
		return $this->run(fn () => $this->files->listing($contextId));
	}
	#[NoAdminRequired]
	#[UseSession]
	public function propose(string $contextId, string $operation, string $filename = '', string $content = '', string $expectedEtag = ''): Response {
		return $this->run(fn () => $this->files->propose($contextId, $operation, $filename, $content, $expectedEtag));
	}
	#[NoAdminRequired]
	#[UseSession]
	public function approve(string $id): Response {
		return $this->run(fn () => $this->files->approve($id));
	}
	#[NoAdminRequired]
	#[UseSession]
	public function commit(string $id): Response {
		return $this->run(fn () => $this->files->commit($id));
	}
}
