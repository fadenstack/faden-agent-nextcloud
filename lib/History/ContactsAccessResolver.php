<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Providers\ProviderAuthority;
use OCA\FadenstackAgent\Providers\ProviderPolicy;
use OCP\IUserSession;

final class ContactsAccessResolver implements AccessResolver {
	public function __construct(
		private IUserSession $users,
		private ProviderPolicy $policy,
		private ProviderAuthority $authority,
	) {
	}

	public function assertAccess(JournalIdentity $identity, JournalResource $resource, string $purpose): void {
		if ($resource->provider !== 'contacts' || $this->users->getUser()?->getUID() !== $identity->userId) {
			throw new JournalException('permission_lost', 403);
		}
		if (!str_starts_with($resource->authorityReference, 'book:')) {
			throw new JournalException('permission_lost', 403);
		}
		$bookId = substr($resource->authorityReference, strlen('book:'));
		try {
			$policy = in_array($purpose, ['prepare', 'dispatch'], true) ? $this->policy->contacts() : $this->policy->audit('contacts');
			if (in_array($purpose, ['prepare', 'dispatch'], true) && !$policy['writesEnabled']) {
				throw new JournalException('permission_lost', 403);
			}
			if (!in_array($bookId, $policy['collections'], true)) {
				throw new JournalException('permission_lost', 403);
			}
			$this->policy->assertDisclosure($policy);
			$this->authority->inspect($resource);
		} catch (\Throwable) {
			throw new JournalException('permission_lost', 403);
		}
	}
}
