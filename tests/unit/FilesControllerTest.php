<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCA\FadenstackAgent\Controller\FilesController;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\SearchAccessService;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

/**
 * How the controller answers a refused file operation: a file another app holds locked (Nextcloud Text with
 * files_lock while the file is open) is 423 Locked, told apart from a file changed since the read (409); a problem
 * keeps its own status and words.
 */
class FilesControllerTest extends TestCase {
	private function controller(\Throwable $error): FilesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('binding');
		$files = $this->createMock(FileService::class);
		$files->method('commit')->willThrowException($error);
		return new FilesController('fadenstack_agent', $request, $files, $this->createMock(SearchAccessService::class));
	}

	public function testALockedFileIsAnsweredAsLocked(): void {
		$response = $this->controller(new LockedException('/alice/files/Team note.md'))->commit('p1', 1, 'text');
		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(423, $response->getStatus());
		$this->assertSame(['message' => 'The file is busy. Try again.'], $response->getData());
	}

	public function testAChangedFileStaysAConflict(): void {
		$response = $this->controller(new Problem('Another user changed the file. Read it again; no changes were saved.', 409))->commit('p1', 1, 'text');
		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['message' => 'Another user changed the file. Read it again; no changes were saved.'], $response->getData());
	}

	public function testAnythingElseClaimsNoSuccess(): void {
		$response = $this->controller(new \RuntimeException('disk full'))->commit('p1', 1, 'text');
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['message' => 'The file operation failed. No success is claimed.'], $response->getData());
	}
}
