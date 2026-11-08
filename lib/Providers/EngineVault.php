<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\AppInfo\Application;
use OCA\FadenstackAgent\Service\Problem;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;

/** Per-native-user encrypted credentials. Never put these in an action snapshot. */
final class EngineVault {
	private const PREFIX = 'engine_connection_';
	private const DOMAIN = 'fadenstack-engine-connection-v1';
	public const MAX_AGE = 2592000;
	public function __construct(
		private IConfig $config,
		private IUserSession $users,
		private ICrypto $crypto,
		private EngineEndpoint $endpoints,
		private ILockingProvider $locks,
		private ?ITimeFactory $clock = null,
	) {
	}
	private function actor(): string {
		return $this->users->getUser()?->getUID() ?? throw new Problem('Sign in to Nextcloud.', 401);
	}
	private function now(): int {
		return $this->clock?->getTime() ?? time();
	}
	private function raw(string $actor, string $id): string {
		return $this->config->getUserValue($actor, Application::APP_ID, self::PREFIX . EngineEndpoint::id($id), '');
	}
	public function status(string $id): array {
		$actor = $this->actor();
		$raw = $this->raw($actor, $id);
		$endpoint = $this->endpoints->all()[EngineEndpoint::id($id)] ?? null;
		if ($endpoint === null) {
			if ($raw === '') {
				throw new Problem('This workflow connection is unavailable.', 403);
			}
			return ['id' => $id, 'provider' => 'unavailable', 'label' => $id, 'baseUrl' => '', 'workspace' => '', 'version' => '',
				'revision' => hash('sha256', $raw), 'connected' => false, 'expiresAt' => null, 'setupAllowed' => false,
				'keyAccess' => 'The administrator removed this endpoint. Disconnect your retained credential.'];
		}
		$value = null;
		try {
			$value = $this->open($actor, $endpoint, $raw);
		} catch (Problem) { /* Do not expose secret material or crypto exceptions. */
		}
		return ['id' => $id, 'provider' => $endpoint['provider'], 'label' => $endpoint['label'], 'baseUrl' => $endpoint['baseUrl'],
			'workspace' => $endpoint['workspace'], 'version' => $endpoint['version'], 'revision' => hash('sha256', $raw),
			'connected' => $value !== null, 'expiresAt' => $value['expiresAt'] ?? null, 'setupAllowed' => true,
			'keyAccess' => $endpoint['provider'] === 'n8n' ? 'Non-Enterprise API keys can access all resources and capabilities of the connected n8n account.' : 'Use a scoped Windmill user token for the required resources and actions.'];
	}
	public function listing(): array {
		$ids = array_keys($this->endpoints->all());
		foreach ($this->config->getUserKeys($this->actor(), Application::APP_ID) as $key) {
			if (str_starts_with($key, self::PREFIX)) {
				$id = substr($key, strlen(self::PREFIX));
				if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $id) && !in_array($id, $ids, true)) {
					$ids[] = $id;
				}
			}
		}
		if (count($ids) > 64) {
			throw new Problem('Ask the administrator to clean up old workflow connection records.', 413);
		}
		return array_map(fn ($id) => $this->status($id), $ids);
	}
	private function open(string $actor, array $endpoint, string $raw): array {
		try {
			if ($raw === '' || strlen($raw) > 65536) {
				throw new \UnexpectedValueException();
			}
			$value = json_decode($this->crypto->decrypt($raw), true, 8, JSON_THROW_ON_ERROR);
			if (!is_array($value) || ($value['domain'] ?? null) !== self::DOMAIN || ($value['actor'] ?? null) !== $actor
				|| ($value['connection'] ?? null) !== $endpoint['id'] || ($value['stamp'] ?? null) !== $endpoint['stamp']
				|| !is_int($value['expiresAt'] ?? null) || $value['expiresAt'] <= $this->now() || !is_string($value['apiToken'] ?? null)) {
				throw new \UnexpectedValueException();
			}
			self::token($value['apiToken']);
			$hooks = $value['webhooks'] ?? [];
			if (!is_array($hooks) || count($hooks) > 16) {
				throw new \UnexpectedValueException();
			}
			foreach ($hooks as $id => $hook) {
				EngineEndpoint::id($id);
				if (!is_array($hook) || !is_string($hook['stamp'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $hook['stamp'])) {
					throw new \UnexpectedValueException();
				}
				self::token($hook['secret'] ?? null);
			}
			return $value;
		} catch (\Throwable) {
			throw new Problem('Reconnect this workflow connection with your own engine token.', 403);
		}
	}
	public static function token(mixed $token): string {
		if (!is_string($token) || strlen($token) < 8 || strlen($token) > 8192 || preg_match('/[^\x21-\x7e]/', $token)) {
			throw new Problem('Use an engine API token without spaces or control characters.', 400);
		}
		return $token;
	}
	/** Server-internal only: controller and tools must never serialize this result. */
	public function resolve(string $id): array {
		$actor = $this->actor();
		$endpoint = $this->endpoints->get($id);
		$raw = $this->raw($actor, $id);
		$value = $this->open($actor, $endpoint, $raw);
		return ['actor' => $actor, 'endpoint' => $endpoint, 'apiToken' => $value['apiToken'], 'webhooks' => $value['webhooks'] ?? [], 'revision' => hash('sha256', $raw)];
	}
	/** Metadata only; no remote call or effect is used to test a webhook key. */
	public function webhookStatus(array $target): array {
		try {
			$c = $this->resolve($target['connectionId']);
			$stored = ($c['webhooks'][$target['id']]['stamp'] ?? null) === $target['stamp'];
		} catch (Problem) {
			$stored = false;
		}
		return ['id' => $target['id'], 'connectionId' => $target['connectionId'], 'workflowId' => $target['workflowId'], 'path' => $target['path'], 'secretStored' => $stored];
	}
	/** Human only. The API key remains inside the existing encrypted envelope. */
	public function replaceWebhook(array $target, string $expectedRevision, ?string $secret): array {
		$c = $this->resolve($target['connectionId']);
		if ($c['endpoint']['provider'] !== 'n8n' || $c['revision'] !== $expectedRevision) {
			throw new Problem('Reload your own n8n connection before changing its webhook key.', 409);
		}
		$hooks = $c['webhooks'];
		if ($secret === null) {
			unset($hooks[$target['id']]);
		} else {
			$hooks[$target['id']] = ['stamp' => $target['stamp'], 'secret' => self::token($secret)];
		}
		return $this->replace($target['connectionId'], $expectedRevision, $c['apiToken'], $hooks);
	}
	/** Human-only connection setup; remote validity is checked separately before saving. */
	public function replace(string $id, string $expectedRevision, ?string $apiToken, array $webhooks = []): array {
		$actor = $this->actor();
		$endpoint = $apiToken === null ? ($this->endpoints->all()[EngineEndpoint::id($id)] ?? null) : $this->endpoints->get($id);
		if ($endpoint === null && $this->raw($actor, $id) === '') {
			throw new Problem('This workflow connection is unavailable.', 403);
		}
		if (!preg_match('/^[a-f0-9]{64}$/D', $expectedRevision)) {
			throw new Problem('Reload the workflow connection before changing it.', 409);
		}
		if ($apiToken !== null) {
			self::token($apiToken);
		}
		if (count($webhooks) > 16 || ($webhooks && ($apiToken === null || $endpoint['provider'] !== 'n8n'))) {
			throw new Problem('Use a bounded own-user n8n webhook connection.', 400);
		}
		$key = hash('sha256', 'fadenstack:engine-vault:' . $actor . ':' . $id);
		$this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			if ($this->actor() !== $actor || ($this->endpoints->all()[$id]['stamp'] ?? null) !== ($endpoint['stamp'] ?? null)
				|| !hash_equals($expectedRevision, hash('sha256', $this->raw($actor, $id)))) {
				throw new Problem('The account, endpoint or connection changed. Reload before reconnecting.', 409);
			}
			// Keep a fresh encrypted tombstone on disconnect. An old approval can
			// never match an absent/recreated credential generation.
			$sealed = $this->crypto->encrypt(json_encode(['domain' => self::DOMAIN, 'actor' => $actor, 'connection' => $id,
				'stamp' => $endpoint['stamp'] ?? '', 'nonce' => bin2hex(random_bytes(16)), 'apiToken' => $apiToken,
				'webhooks' => $webhooks,
				'expiresAt' => $this->now() + self::MAX_AGE], JSON_THROW_ON_ERROR));
			$this->config->setUserValue($actor, Application::APP_ID, self::PREFIX . $id, $sealed);
			return $this->status($id);
		} finally {
			$this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
