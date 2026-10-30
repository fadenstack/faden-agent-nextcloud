<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Providers\ProviderPolicy;
use OCP\IUserSession;

final class TasksAccessResolver implements AccessResolver {
	public function __construct(
		private IUserSession $users,
		private ProviderPolicy $policy,
	) {
	}

	public function assertAccess(JournalIdentity $identity, JournalResource $resource, string $purpose): void {
		if ($resource->provider !== 'tasks' || $this->users->getUser()?->getUID() !== $identity->userId) {
			throw new JournalException('permission_lost', 403);
		}
		if (!str_starts_with($resource->authorityReference, 'collection:')) {
			throw new JournalException('permission_lost', 403);
		}
		$collectionId = substr($resource->authorityReference, strlen('collection:'));
		try {
			$policy = $this->policy->tasks();
			if (!in_array($collectionId, $policy['collections'], true)) {
				throw new JournalException('permission_lost', 403);
			}
			$this->policy->assertDisclosure($policy);
		} catch (\Throwable) {
			throw new JournalException('permission_lost', 403);
		}
	}
}
