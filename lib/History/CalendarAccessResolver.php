<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Providers\ProviderPolicy;
use OCA\FadenstackAgent\Providers\ProviderAuthority;
use OCP\IUserSession;

final class CalendarAccessResolver implements AccessResolver {
	public function __construct(
		private IUserSession $users,
		private ProviderPolicy $policy,
		private ProviderAuthority $authority,
	) {
	}

	public function assertAccess(JournalIdentity $identity, JournalResource $resource, string $purpose): void {
		if ($resource->provider !== 'calendar' || $this->users->getUser()?->getUID() !== $identity->userId) {
			throw new JournalException('permission_lost', 403);
		}
		if (!str_starts_with($resource->authorityReference, 'collection:')) {
			throw new JournalException('permission_lost', 403);
		}
		$collectionId = substr($resource->authorityReference, strlen('collection:'));
		try {
			$policy = in_array($purpose, ['prepare', 'dispatch'], true) ? $this->policy->calendar() : $this->policy->audit('calendar');
			if (in_array($purpose, ['prepare', 'dispatch'], true) && !$policy['writesEnabled']) {
				throw new JournalException('permission_lost', 403);
			}
			if (!in_array($collectionId, $policy['collections'], true)) {
				throw new JournalException('permission_lost', 403);
			}
			$this->policy->assertDisclosure($policy);
			$this->authority->inspect($resource);
		} catch (\Throwable) {
			throw new JournalException('permission_lost', 403);
		}
	}
}
