<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Controller;

use OCA\FadenstackAgent\Providers\CalendarProvider;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** CSRF and native session are required; never hold UseSession across a sibling request. */
final class ProvidersController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private FileService $files,
		private CalendarProvider $calendar,
	) {
		parent::__construct($appName, $request);
	}

	private function run(callable $operation): DataResponse {
		try {
			$this->files->check($this->request->getHeader('X-Fadenstack-Session'));
			$response = new DataResponse($operation());
		} catch (Problem $error) {
			$response = new DataResponse(['message' => $error->getMessage()], $error->status);
		} catch (\Throwable) {
			$response = new DataResponse(['message' => 'The provider result could not be verified.'], 503);
		}
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}

	#[NoAdminRequired]
	public function capabilities(): DataResponse {
		return $this->run(fn () => ['providers' => $this->calendar->capabilities()]);
	}

	#[NoAdminRequired]
	public function calendarCollections(): DataResponse {
		return $this->run(fn () => $this->calendar->collections());
	}

	#[NoAdminRequired]
	public function calendarEvents(string $collectionId): DataResponse {
		return $this->run(fn () => $this->calendar->events($collectionId));
	}

	#[NoAdminRequired]
	public function calendarEvent(string $collectionId, string $filename): DataResponse {
		return $this->run(fn () => $this->calendar->event($collectionId, $filename));
	}

	#[NoAdminRequired]
	public function calendarPropose(string $operation, string $collectionId, string $filename, string $icsData = '', ?string $expectedEtag = null): DataResponse {
		return $this->run(fn () => $this->calendar->propose($operation, $collectionId, $filename, $icsData, $expectedEtag));
	}

	#[NoAdminRequired]
	public function calendarApprove(string $id): DataResponse {
		return $this->run(fn () => $this->calendar->approve($id));
	}

	#[NoAdminRequired]
	public function calendarCommit(string $id): DataResponse {
		return $this->run(fn () => $this->calendar->commit($id));
	}
}
