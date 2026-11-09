<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Controller;

use OCA\FadenstackAgent\Providers\N8nOAuthService;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;

/** Native human controls, never SDK/model tools. No credential is returned. */
final class EngineOauthController extends Controller {
	private const RESULT = 'fadenstack_agent.oauth_result';
	public function __construct(
		string $appName,
		IRequest $request,
		private N8nOAuthService $oauth,
		private FileService $files,
		private ISession $session,
		private IURLGenerator $urls,
	) {
		parent::__construct($appName, $request);
	}
	private function run(callable $call, bool $setup = false): DataResponse {
		try {
			if ($this->request->getHeader('OCS-APIRequest') !== '' || !$this->request->passesCSRFCheck()) {
				throw new Problem('A valid Nextcloud request token is required.', 403);
			}
			$setup ? $this->files->check($this->request->getHeader('X-Fadenstack-Session')) : $this->files->checkSession($this->request->getHeader('X-Fadenstack-Session'));
			$r = new DataResponse($call());
		} catch (\Throwable $e) {
			$r = new DataResponse(['message' => $e instanceof Problem ? $e->getMessage() : 'OAuth is unconfirmed. Reload and reconnect; no automatic retry was made.'], $e instanceof Problem ? $e->status : 503);
		}
		$r->addHeader('Cache-Control', 'no-store');
		return $r;
	}
	#[NoAdminRequired]
	public function listing(): DataResponse {
		return $this->run(fn () => ['targets' => $this->oauth->listing()]);
	}
	#[NoAdminRequired]
	public function start(string $targetId, string $revision): DataResponse {
		return $this->run(fn () => $this->oauth->start($targetId, $revision), true);
	}
	#[NoAdminRequired]
	public function refresh(string $targetId, string $revision): DataResponse {
		return $this->run(fn () => $this->oauth->refresh($targetId, $revision), true);
	}
	#[NoAdminRequired]
	public function remove(string $id, string $targetId, string $revision): DataResponse {
		return $this->run(fn () => $this->oauth->remove($id, $targetId, $revision));
	}
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function callback(string $id, string $state = '', string $iss = '', ?string $code = null, ?string $error = null): RedirectResponse {
		try {
			$this->oauth->complete($id, $state, $iss, $code, $error);
			$result = ['success' => true];
		} catch (\Throwable $e) {
			$result = ['error' => $e instanceof Problem ? $e->getMessage() : 'OAuth is unconfirmed. Reconnect without repeating the exchange.'];
		}
		$this->session->set(self::RESULT, [...$result, 'expiresAt' => time() + 120]);
		$r = new RedirectResponse($this->urls->linkToRoute('fadenstack_agent.engine_oauth.result'), 303);
		$r->addHeader('Cache-Control', 'no-store');
		$r->addHeader('Referrer-Policy', 'no-referrer');
		return $r;
	}
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function result(): TemplateResponse {
		$value = $this->session->get(self::RESULT);
		$this->session->remove(self::RESULT);
		if (!is_array($value) || ($value['expiresAt'] ?? 0) <= time()) {
			$value = ['error' => 'This connection result expired. Reload the connections in your original chat.'];
		}
		$r = new TemplateResponse('fadenstack_agent', 'engine-oauth', $value);
		$r->addHeader('Cache-Control', 'no-store');
		$r->addHeader('Referrer-Policy', 'no-referrer');
		return $r;
	}
}
