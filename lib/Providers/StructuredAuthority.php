<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\Service\Problem;
use OCP\App\IAppManager;
use OCP\IUserSession;

/** Independent native authority; contains no journal dependency or private app-table access. */
final class StructuredAuthority {
	public function __construct(
		private NativeApiBridge $bridge,
		private ProviderPolicy $policies,
		private IAppManager $apps,
		private IUserSession $users,
	) {
	}
	public function policy(string $provider, bool $audit = false): array {
		$policy = $this->policies->structured($provider, $audit);
		$this->policies->assertDisclosure($policy);
		if (!$this->apps->isEnabledForUser($provider, $this->users->getUser())
			|| $this->apps->getAppVersion($provider) !== ($provider === 'tables' ? '2.4.0' : '1.19.1')) {
			throw new Problem('This native app version has not been enabled and tested.', 403);
		}
		return $policy;
	}
	public function request(array $policy, string $provider, string $operation, int $id, int $item = 0, int $stack = 0, array $payload = []): array {
		$response = $this->bridge->send(NativeRequest::structured($policy['actor'], $provider, $operation, $id, $item, $stack, $payload));
		try {
			$data = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			throw new Problem('Malformed native JSON response.', 503);
		}
		if (!is_array($data)) {
			throw new Problem('Malformed native JSON response.', 503);
		}
		if (isset($data['ocs'])) {
			if (!in_array($data['ocs']['meta']['statuscode'] ?? null, [100, 200], true) || ($data['ocs']['meta']['status'] ?? '') !== 'ok' || !is_array($data['ocs']['data'] ?? null)) {
				throw new Problem('The native OCS operation was refused.', 503);
			}
			$data = $data['ocs']['data'];
		}
		if (isset($data['message']) || isset($data['error'])) {
			throw new Problem('The native app returned an error.', 503);
		}
		return $data;
	}
	public function collection(string $provider, int $id, bool $audit = false): array {
		if ($id < 1 || $id > 999999999999999) {
			throw new Problem('Use a positive native identifier.', 400);
		}
		$policy = $this->policy($provider, $audit);
		if (!in_array((string)$id, $policy['collections'], true)) {
			throw new Problem('The native collection is outside the configured scope.', 403);
		}
		$data = $this->request($policy, $provider, 'metadata', $id);
		if (($data['id'] ?? null) !== $id || !is_string($data['title'] ?? null) || strlen($data['title']) > 2048) {
			throw new Problem('Unverified native collection.', 503);
		}
		if ($provider === 'tables') {
			$owner = ($data['ownership'] ?? '') === $policy['actor'];
			$rights = $owner ? array_fill_keys(['read', 'create', 'update', 'delete', 'manage'], true) : ($data['onSharePermissions'] ?? []);
		} else {
			$native = $data['permissions'] ?? [];
			$rights = ['read' => $native['PERMISSION_READ'] ?? false, 'create' => $native['PERMISSION_EDIT'] ?? false,
				'update' => $native['PERMISSION_EDIT'] ?? false, 'delete' => false, 'manage' => $native['PERMISSION_MANAGE'] ?? false];
		}
		if (($rights['read'] ?? null) !== true) {
			throw new Problem('Current native read permission is required.', 403);
		}
		foreach (['read', 'create', 'update', 'delete', 'manage'] as $key) {
			$rights[$key] = ($rights[$key] ?? false) === true && ($key === 'read' || !($data['archived'] ?? false));
		}
		return ['policy' => $policy, 'data' => $data, 'rights' => $rights, 'audience' => 'unknown',
			'authorityRevision' => StructuredProvider::digest([$id, $data['title'], $data['ownership'] ?? $data['owner'] ?? null,
				$rights, $data['acl'] ?? null, $data['onSharePermissions'] ?? null, $data['hasShares'] ?? null, $data['shareToken'] ?? null])];
	}
	public function resource(JournalResource $resource, bool $write = false): array {
		if (!in_array($resource->provider, ['tables', 'deck'], true)
			|| !preg_match('/^collection:([1-9][0-9]{0,14})(?:\/(row|column|card):([1-9][0-9]{0,14}))?$/D', $resource->authorityReference, $match)
			|| !preg_match('/^collection:' . $match[1] . '\\/(row|column|card):([1-9][0-9]{0,14}|[a-f0-9]{48})$/D', $resource->reference, $target)
			|| !in_array($target[1], $resource->provider === 'tables' ? ['row', 'column'] : ['card'], true)
			|| (isset($match[2]) && $match[2] !== '' && ($match[2] !== $target[1] || (strlen($target[2]) < 16 && $match[3] !== $target[2])))) {
			throw new Problem('Unavailable native action.', 404);
		}
		$value = $this->collection($resource->provider, (int)$match[1], !$write);
		if ($write && !$value['policy']['writesEnabled']) {
			throw new Problem('Native writes are disabled.', 403);
		}
		return $value;
	}
}
