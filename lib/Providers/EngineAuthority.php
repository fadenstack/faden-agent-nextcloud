<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;

/** Current native-user connection and native-engine account; never a model principal. */
final class EngineAuthority {
	public function __construct(
		private EngineVault $vault,
		private EngineBroker $broker,
		private ProviderPolicy $policies,
	) {
	}
	/** Server internal. Never serialize the returned credential-bearing connection. */
	public function connection(string $provider, string $id, bool $write = false, bool $audit = false): array {
		$policy = $this->policies->engine($provider, $audit);
		$this->policies->assertDisclosure($policy);
		if (!in_array($id, $policy['collections'], true) || ($write && !$policy['writesEnabled'])) {
			throw new Problem('The workflow connection is outside the current native scope.', 403);
		}
		$value = $this->vault->resolve($id);
		if ($value['actor'] !== $policy['actor'] || $value['endpoint']['provider'] !== $provider) {
			throw new Problem('The workflow account or provider changed.', 403);
		}
		return [...$value, 'policy' => $policy];
	}
	public function principal(array $connection): string {
		$verified = $this->broker->probe($connection['endpoint']['id'], $connection['apiToken'], $connection['revision']);
		if ($connection['endpoint']['provider'] === 'windmill') {
			return $verified['principal'];
		}
		// Only after this exact key has been accepted by the pinned native API.
		// n8n 2.43.8 matches the stored key, checks its issuer/signature/expiry,
		// and binds server-generated JWTs to the native user's ID. Legacy opaque
		// keys remain read-only. We never infer an owner from an unaccepted JWT.
		$parts = explode('.', $connection['apiToken']);
		try {
			if (count($parts) !== 3 || !preg_match('/^[A-Za-z0-9_-]+$/D', $parts[1])) {
				throw new \UnexpectedValueException();
			}
			$payload = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
			$claims = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
			if (($claims['iss'] ?? null) !== 'n8n' || ($claims['aud'] ?? null) !== 'public-api'
				|| !is_string($claims['sub'] ?? null) || !preg_match('/^[a-f0-9-]{36}$/D', $claims['sub'])
				|| (isset($claims['exp']) && (!is_int($claims['exp']) || $claims['exp'] <= time()))) {
				throw new \UnexpectedValueException();
			}
			return $claims['sub'];
		} catch (\Throwable) {
			throw new Problem('For n8n writes, reconnect with a current native user API key whose account can be verified.', 403);
		}
	}
	public static function assertN8nOwner(array $definition, string $principal): void {
		$shared = $definition['shared'] ?? null;
		if (!is_array($shared) || count($shared) !== 1 || !$shared[0] instanceof \stdClass
			|| ($shared[0]->role ?? null) !== 'workflow:owner' || !($shared[0]->project ?? null) instanceof \stdClass
			|| ($shared[0]->project->type ?? null) !== 'personal' || ($shared[0]->project->creatorId ?? null) !== $principal) {
			throw new Problem('This operation requires the connected user\'s own personal n8n workflow.', 403);
		}
	}
	public function ownDraft(array $connection, string $path): ?array {
		return $this->broker->request(EngineRequest::ownDraft($connection['actor'], $connection['endpoint'], $path), $connection['revision']);
	}
	public function n8nByName(array $connection, string $name): array {
		$result = $this->broker->request(EngineRequest::findN8nDraft($connection['actor'], $connection['endpoint'], $name), $connection['revision']);
		$items = $result['data'] ?? null;
		if (!is_array($items) || !array_is_list($items) || count($items) > 2 || ($result['nextCursor'] ?? null) !== null) {
			throw new Problem('The native draft name could not be resolved unambiguously.', 503);
		}
		foreach ($items as $item) {
			if (!$item instanceof \stdClass || ($item->name ?? null) !== $name || !is_string($item->id ?? null)) {
				throw new Problem('The native engine did not apply the supported name filter.', 503);
			}
		}
		return $items;
	}
	public function definition(array $connection, string $reference): array {
		$result = $this->broker->request(EngineRequest::definition($connection['actor'], $connection['endpoint'], $reference), $connection['revision']);
		if (($result[$connection['endpoint']['provider'] === 'windmill' ? 'path' : 'id'] ?? null) !== $reference) {
			throw new Problem('The engine returned a different workflow.', 503);
		}
		return $result;
	}
	public function absent(array $connection, string $target): void {
		if ($connection['endpoint']['provider'] === 'windmill') {
			if ($this->ownDraft($connection, $target) !== null) {
				throw new Problem('A native draft already exists at this generated path.', 409);
			}
			try {
				$this->definition($connection, $target);
			} catch (Problem $error) {
				if ($error->status === 404) {
					return;
				}
				throw $error;
			}
			throw new Problem('A deployed flow already exists at this generated path.', 409);
		}
		if ($this->n8nByName($connection, $target)) {
			throw new Problem('A native workflow already uses this generated draft name.', 409);
		}
	}
	public static function secretGuard(string $json, array $connection): void {
		foreach ([$connection['apiToken'], ...array_column($connection['webhooks'] ?? [], 'secret')] as $secret) {
			if (str_contains($json, substr(EngineJson::encode($secret), 1, -1))) {
				throw new Problem('The connection credential cannot be included in workflow content.', 403);
			}
		}
	}
}
