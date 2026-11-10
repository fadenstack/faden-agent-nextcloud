<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;
use OCP\ISession;

/** Human-only consent and explicit refresh; existing encrypted vault, no new journal. */
final class N8nOAuthService {
	public function __construct(
		private N8nOAuthConfiguration $configuration,
		private N8nOAuthClient $client,
		private EngineVault $vault,
		private EngineAuthority $authority,
		private ISession $session,
	) {
	}
	private function context(string $targetId): array {
		$entry = $this->configuration->get($targetId);
		$c = $this->authority->connection('n8n', $entry['target']['connectionId'], true);
		if (!in_array(N8nExecutePlan::TOOL, $c['policy']['processing']['tools'], true) || !EngineRunPlan::canMove($c['policy'])) {
			throw new Problem('This native OAuth execution target is outside the current agent scope.', 403);
		}
		$principal = $this->authority->principal($c);
		$flow = $this->authority->definition($c, $entry['target']['workflowId']);
		EngineAuthority::assertN8nOwner($flow, $principal);
		N8nExecutePlan::publishedHook($flow, $entry['target']);
		return compact('entry', 'c', 'principal');
	}
	public function listing(): array {
		$rows = [];
		foreach ($this->configuration->all() as $id => $entry) {
			$state = 'disconnected';
			$expires = null;
			try {
				$c = $this->vault->resolve($entry['target']['connectionId']);
				$subject = EngineAuthority::n8nVerifiedSubject($c['apiToken']);
				$g = $c['oauth'][$id] ?? null;
				if ($g !== null) {
					$state = $g['state'] === 'ready' && $g['stamp'] === $entry['stamp'] && $subject !== null && $g['subject'] === $subject
						? ($g['expiresAt'] > time() + 30 ? 'connected' : 'expired') : 'reconnect';
					$expires = $g['expiresAt'];
				}
				$p = $c['oauthPending'];
				if (($p['targetId'] ?? null) === $id) {
					$state = $p['stage'] === 'waiting' && $p['expiresAt'] > time() && $p['stamp'] === $entry['stamp'] ? 'pending' : 'reconnect';
				}
			} catch (Problem) { /* Native local disconnect remains available separately. */
			}
			$rows[] = ['id' => $id, 'connectionId' => $entry['target']['connectionId'], 'workflowId' => $entry['target']['workflowId'], 'issuer' => $entry['binding']->issuer, 'state' => $state, 'expiresAt' => $expires];
		}
		return $rows;
	}
	public function start(string $targetId, string $expectedRevision): array {
		['entry' => $e, 'c' => $c, 'principal' => $principal] = $this->context($targetId);
		if ($c['revision'] !== $expectedRevision || ($c['oauthPending'] !== null && $c['oauthPending']['expiresAt'] > time())) {
			throw new Problem('Reload or cancel the pending OAuth connection before starting another.', 409);
		}
		$this->client->discover($e['binding']);
		$p = ['targetId' => $targetId, 'state' => bin2hex(random_bytes(32)), 'verifier' => bin2hex(random_bytes(32)),
			'session' => hash('sha256', $this->session->getId()), 'stamp' => $e['stamp'], 'principal' => $principal,
			'policy' => $c['policy']['policyVersion'], 'expiresAt' => time() + 600, 'stage' => 'waiting'];
		$grants = $c['oauth'];
		unset($grants[$targetId]);
		$this->vault->changeOAuth($e['target']['connectionId'], $expectedRevision, $grants, $p);
		return ['authorizationUrl' => N8nOAuthClient::authorization($e['binding'], $p['verifier'], $p['state'])];
	}
	/** Authenticated top-level GET. State is the CSRF defense, never a session substitute. */
	public function complete(string $id, string $state, string $iss, ?string $code, ?string $error): void {
		$c = $this->vault->resolve($id);
		$p = $c['oauthPending'];
		if ($p === null || $p['stage'] !== 'waiting' || $p['expiresAt'] <= time() || !preg_match('/^[a-f0-9]{64}$/D', $state)
			|| !hash_equals($p['state'], $state) || !hash_equals($p['session'], hash('sha256', $this->session->getId()))) {
			throw new Problem('This OAuth connection expired, was cancelled or belongs to another native session.', 410);
		}
		$e = $this->configuration->get($p['targetId']);
		if ($iss !== $e['binding']->issuer || $p['stamp'] !== $e['stamp'] || ($code !== null) === ($error !== null)) {
			throw new Problem('The OAuth issuer, callback or target changed.', 409);
		}
		if ($error !== null) {
			$this->vault->changeOAuth($id, $c['revision'], $c['oauth'], null);
			throw new Problem('Native OAuth consent was not granted. No workflow was run.', 403);
		}
		['c' => $current, 'principal' => $principal] = $this->context($p['targetId']);
		if ($current['revision'] !== $c['revision'] || $principal !== $p['principal'] || $current['policy']['policyVersion'] !== $p['policy']) {
			throw new Problem('The native OAuth identity, credential or scope changed.', 409);
		}
		$revision = '';
		$grant = $this->client->exchange($e['binding'], $code, $p['verifier'], $principal, function () use ($c, $p, $id, &$revision): void {
			$p['stage'] = 'exchanging';
			$revision = $this->vault->changeOAuth($id, $c['revision'], $c['oauth'], $p)['revision'];
		});
		$this->saveGrant($p['targetId'], $e, $principal, $p['policy'], $revision, $grant);
	}
	private function saveGrant(string $targetId, array $entry, string $principal, string $policy, string $revision, array $grant): array {
		['entry' => $now, 'c' => $c, 'principal' => $owner] = $this->context($targetId);
		if ($now['stamp'] !== $entry['stamp'] || $owner !== $principal || $c['policy']['policyVersion'] !== $policy || $c['revision'] !== $revision) {
			throw new Problem('The native OAuth connection changed after exchange. Reconnect; the exchange will not be repeated.', 409);
		}
		$grants = $c['oauth'];
		$grants[$targetId] = ['state' => 'ready', 'stamp' => $entry['stamp'], 'subject' => $principal,
			'accessToken' => $grant['accessToken'], 'refreshToken' => $grant['refreshToken'], 'expiresAt' => $grant['expiresAt']];
		return $this->vault->changeOAuth($entry['target']['connectionId'], $revision, $grants, null);
	}
	public function refresh(string $targetId, string $expectedRevision): array {
		['entry' => $e, 'c' => $c, 'principal' => $principal] = $this->context($targetId);
		$g = $c['oauth'][$targetId] ?? null;
		if ($c['revision'] !== $expectedRevision || $c['oauthPending'] !== null || $g === null || $g['state'] !== 'ready' || $g['stamp'] !== $e['stamp'] || $g['subject'] !== $principal) {
			throw new Problem('Reconnect or cancel the pending OAuth attempt before refreshing.', 409);
		}
		$this->client->discover($e['binding']);
		$revision = '';
		$grant = $this->client->refresh($e['binding'], $g['refreshToken'], $principal, function () use ($c, $targetId, $e, &$revision): void {
			$grants = $c['oauth'];
			$grants[$targetId]['state'] = 'refreshing';
			$revision = $this->vault->changeOAuth($e['target']['connectionId'], $c['revision'], $grants, null)['revision'];
		});
		return $this->saveGrant($targetId, $e, $principal, $c['policy']['policyVersion'], $revision, $grant);
	}
	/** Local revocation always precedes any optional native account cleanup. */
	public function remove(string $id, string $targetId, string $revision): array {
		EngineEndpoint::id($targetId);
		$c = $this->vault->resolve($id);
		$grants = $c['oauth'];
		unset($grants[$targetId]);
		$pending = ($c['oauthPending']['targetId'] ?? null) === $targetId ? null : $c['oauthPending'];
		return $this->vault->changeOAuth($id, $revision, $grants, $pending);
	}
}
