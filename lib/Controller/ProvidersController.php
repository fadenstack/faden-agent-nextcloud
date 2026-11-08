<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Controller;

use OCA\FadenstackAgent\Providers\CalendarProvider;
use OCA\FadenstackAgent\Providers\CommunicationProvider;
use OCA\FadenstackAgent\Providers\ContactsProvider;
use OCA\FadenstackAgent\Providers\ContentProvider;
use OCA\FadenstackAgent\Providers\EngineProvider;
use OCA\FadenstackAgent\Providers\EngineReads;
use OCA\FadenstackAgent\Providers\ProviderAuditService;
use OCA\FadenstackAgent\Providers\StructuredProvider;
use OCA\FadenstackAgent\Providers\TasksProvider;
use OCA\FadenstackAgent\Providers\WorkflowProvider;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** CSRF and native session are required; never hold UseSession across a sibling request. */
final class ProvidersController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private FileService $files,
		private CalendarProvider $calendar,
		private TasksProvider $tasks,
		private ContactsProvider $contacts,
		private ProviderAuditService $audit,
		private StructuredProvider $structured,
		private CommunicationProvider $communications,
		private ContentProvider $content,
		private WorkflowProvider $workflows,
		private EngineReads $engines,
		private EngineProvider $engineActions,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	private function run(callable $operation): DataResponse {
		try {
			$this->assertCsrf();
			$this->files->check($this->request->getHeader('X-Fadenstack-Session'));
			$response = new DataResponse($operation());
		} catch (Problem $error) {
			$response = new DataResponse(['message' => $error->getMessage(), ...$this->attempt()], $error->status);
		} catch (\Throwable $error) {
			$this->logger->error('Native provider runtime failure ({class} at {file}:{line}).', [
				'app' => 'fadenstack_agent', 'class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine(),
			]);
			$response = new DataResponse(['message' => 'The provider result could not be verified.', ...$this->attempt()], 503);
		}
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}

	/** Native OCS headers also exempt IRequest::passesCSRFCheck itself.
	 * These typed cookie-session routes are not OCS endpoints: refuse that mode
	 * so the public native check always verifies the actual token.
	 */
	private function assertCsrf(): void {
		if ($this->request->getHeader('OCS-APIRequest') !== '' || !$this->request->passesCSRFCheck()) {
			throw new Problem('A valid Nextcloud request token is required.', 403);
		}
	}

	private function attempt(): array {
		if ($this->engineActions->attemptId()) {
			return ['actionId' => $this->engineActions->attemptId(), 'outcome' => $this->engineActions->attemptOutcome(), 'inspectionAvailable' => true];
		}
		$id = $this->calendar->attemptId() ?? $this->tasks->attemptId() ?? $this->contacts->attemptId() ?? $this->structured->attemptId() ?? $this->communications->attemptId() ?? $this->content->attemptId() ?? $this->workflows->attemptId();
		return $id ? ['actionId' => $id, 'outcome' => $id === $this->structured->attemptId() ? $this->structured->attemptOutcome() : ($id === $this->communications->attemptId() ? $this->communications->attemptOutcome() : ($id === $this->content->attemptId() ? $this->content->attemptOutcome() : ($id === $this->workflows->attemptId() ? $this->workflows->attemptOutcome() : 'unknown'))), 'inspectionAvailable' => true] : [];
	}

	/** Audit remains available after dispatch is disabled; native session + CSRF still apply. */
	private function auditRun(callable $operation): DataResponse {
		try {
			$this->assertCsrf();
			$this->files->checkSession($this->request->getHeader('X-Fadenstack-Session'));
			$response = new DataResponse($operation());
		} catch (\Throwable $error) {
			$this->logger->warning('Native provider inspection failure ({class} at {file}:{line}).', [
				'app' => 'fadenstack_agent', 'class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine(),
			]);
			$response = new DataResponse(['message' => 'The native action could not be inspected with current access.'], 403);
		}
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}

	#[NoAdminRequired]
	public function actions(): DataResponse {
		return $this->auditRun(fn () => array_merge($this->audit->listing(), $this->structured->actions(), $this->communications->actions(), $this->content->actions(), $this->workflows->actions(), $this->engineActions->actions()));
	}

	#[NoAdminRequired]
	public function inspectAction(string $id): DataResponse {
		return $this->auditRun(function () use ($id): array {
			try {
				return $this->audit->inspect($id);
			} catch (\Throwable) {
				try {
					return $this->structured->inspect($id);
				} catch (\Throwable) {
					try {
						return $this->communications->inspect($id);
					} catch (\Throwable) {
						try {
							return $this->content->inspect($id);
						} catch (\Throwable) {
							try {
								return $this->workflows->inspect($id);
							} catch (\Throwable) {
								return $this->engineActions->inspect($id);
							}
						}
					}
				}
			}
		});
	}

	#[NoAdminRequired]
	public function capabilities(): DataResponse {
		return $this->run(function (): array {
			$calendar = $this->calendar->capabilities();
			$tasks = $this->tasks->capabilities();
			// The published discovery tool covers both CalDAV component families.
			// This facade grants collection discovery only, never event access/writes.
			if (!$calendar && $tasks && $this->tasks->lists()) {
				$calendar = [[...$tasks[0], 'provider' => 'calendar', 'tools' => ['nc_calendar_list'],
					'conflict' => 'read-only', 'history' => 'not-applicable', 'canWrite' => false, 'discoveryOnly' => true]];
			}
			return ['providers' => array_merge($calendar, $tasks, $this->contacts->capabilities(), $this->structured->capabilities(), $this->communications->capabilities(), $this->content->capabilities(), $this->workflows->capabilities(), $this->engineActions->capabilities())];
		});
	}

	#[NoAdminRequired]
	public function engineRead(string $provider, array $args = []): DataResponse {
		return $this->run(fn () => $provider === 'windmill' && array_key_exists('actionId', $args) ? $this->engineActions->readJob($args) : $this->engines->read($provider, $args));
	}
	#[NoAdminRequired]
	public function enginePropose(string $toolName, array $args, string $requestId): DataResponse {
		return $this->run(fn () => $this->engineActions->propose($toolName, $args, $requestId, $this->request->getHeader('X-Fadenstack-Agent-Token')));
	}
	#[NoAdminRequired]
	public function engineApprove(string $id, string $digest): DataResponse {
		return $this->run(fn () => $this->engineActions->approve($id, $digest));
	}
	#[NoAdminRequired]
	public function engineCommit(string $id, string $digest): DataResponse {
		return $this->run(fn () => $this->engineActions->commit($id, $digest));
	}
	#[NoAdminRequired]
	public function engineCancel(string $id): DataResponse {
		return $this->auditRun(fn () => $this->engineActions->cancel($id));
	}
	#[NoAdminRequired]
	public function workflowRead(string $provider, array $args = []): DataResponse {
		return $this->run(fn () => $this->workflows->read($provider, $args));
	}
	#[NoAdminRequired]
	public function workflowPropose(string $toolName, array $args, string $requestId): DataResponse {
		return $this->run(fn () => $this->workflows->propose($toolName, $args, $requestId));
	}
	#[NoAdminRequired]
	public function workflowApprove(string $id, string $digest): DataResponse {
		return $this->run(fn () => $this->workflows->approve($id, $digest));
	}
	#[NoAdminRequired]
	public function workflowCommit(string $id, string $digest): DataResponse {
		return $this->run(fn () => $this->workflows->commit($id, $digest));
	}
	#[NoAdminRequired]
	public function workflowCancel(string $id): DataResponse {
		return $this->auditRun(fn () => $this->workflows->cancel($id));
	}
	#[NoAdminRequired]
	public function contentRead(string $provider, array $args = []): DataResponse {
		return $this->run(fn () => $this->content->read($provider, $args));
	}
	#[NoAdminRequired]
	public function contentPropose(string $toolName, array $args, string $requestId): DataResponse {
		return $this->run(fn () => $this->content->propose($toolName, $args, $requestId));
	}
	#[NoAdminRequired]
	public function contentApprove(string $id, string $digest): DataResponse {
		return $this->run(fn () => $this->content->approve($id, $digest));
	}
	#[NoAdminRequired]
	public function contentCommit(string $id, string $digest, int $step): DataResponse {
		return $this->run(fn () => $this->content->commit($id, $digest, $step));
	}
	#[NoAdminRequired]
	public function contentCancel(string $id): DataResponse {
		return $this->auditRun(fn () => $this->content->cancel($id));
	}
	#[NoAdminRequired]
	public function communicationRead(string $provider, array $args = []): DataResponse {
		return $this->run(fn () => $this->communications->read($provider, $args));
	}
	#[NoAdminRequired]
	public function communicationPropose(string $toolName, array $args, string $requestId): DataResponse {
		return $this->run(fn () => $this->communications->propose($toolName, $args, $requestId));
	}
	#[NoAdminRequired]
	public function communicationApprove(string $id, string $digest): DataResponse {
		return $this->run(fn () => $this->communications->approve($id, $digest));
	}
	#[NoAdminRequired]
	public function communicationCommit(string $id, string $digest, int $step): DataResponse {
		return $this->run(fn () => $this->communications->commit($id, $digest, $step));
	}
	#[NoAdminRequired]
	public function communicationCancel(string $id): DataResponse {
		return $this->auditRun(fn () => $this->communications->cancel($id));
	}

	#[NoAdminRequired]
	public function structuredRead(string $provider, int $collectionId): DataResponse {
		return $this->run(fn () => $this->structured->read($provider, $collectionId));
	}

	#[NoAdminRequired]
	public function structuredPropose(string $toolName, array $args, string $requestId): DataResponse {
		return $this->run(fn () => $this->structured->propose($toolName, $args, $requestId));
	}

	#[NoAdminRequired]
	public function structuredApprove(string $id, string $digest): DataResponse {
		return $this->run(fn () => $this->structured->approve($id, $digest));
	}

	#[NoAdminRequired]
	public function structuredCommit(string $id, string $digest): DataResponse {
		return $this->run(fn () => $this->structured->commit($id, $digest));
	}

	#[NoAdminRequired]
	public function structuredCancel(string $id, string $digest = ''): DataResponse {
		return $this->auditRun(fn () => $this->structured->cancel($id, $digest));
	}

	// --- Calendar ---

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

	// --- Tasks ---

	#[NoAdminRequired]
	public function tasksLists(): DataResponse {
		return $this->run(fn () => ['lists' => $this->tasks->lists()]);
	}

	#[NoAdminRequired]
	public function tasksItems(string $collectionId): DataResponse {
		return $this->run(fn () => ['tasks' => $this->tasks->tasks($collectionId)]);
	}

	#[NoAdminRequired]
	public function taskItem(string $collectionId, string $filename): DataResponse {
		return $this->run(fn () => $this->tasks->task($collectionId, $filename));
	}

	#[NoAdminRequired]
	public function tasksPropose(string $operation, string $collectionId, string $filename, string $icsData = '', ?string $expectedEtag = null): DataResponse {
		return $this->run(fn () => $this->tasks->propose($operation, $collectionId, $filename, $icsData, $expectedEtag));
	}

	#[NoAdminRequired]
	public function tasksApprove(string $id): DataResponse {
		return $this->run(fn () => $this->tasks->approve($id));
	}

	#[NoAdminRequired]
	public function tasksCommit(string $id): DataResponse {
		return $this->run(fn () => $this->tasks->commit($id));
	}

	// --- Contacts ---

	#[NoAdminRequired]
	public function contactsBooks(): DataResponse {
		return $this->run(fn () => ['books' => $this->contacts->books()]);
	}

	#[NoAdminRequired]
	public function contactsFind(string $query): DataResponse {
		return $this->run(fn () => $this->contacts->find($query));
	}

	#[NoAdminRequired]
	public function contactsRead(string $bookId, ?string $filename = null): DataResponse {
		return $this->run(fn () => $this->contacts->read($bookId, $filename));
	}

	#[NoAdminRequired]
	public function contactsPropose(string $operation, string $bookId, string $filename, string $vcardData = '', ?string $expectedEtag = null): DataResponse {
		return $this->run(fn () => $this->contacts->propose($operation, $bookId, $filename, $vcardData, $expectedEtag));
	}

	#[NoAdminRequired]
	public function contactsApprove(string $id): DataResponse {
		return $this->run(fn () => $this->contacts->approve($id));
	}

	#[NoAdminRequired]
	public function contactsCommit(string $id): DataResponse {
		return $this->run(fn () => $this->contacts->commit($id));
	}
}
