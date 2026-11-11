<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Providers\StructuredAuthority;
use OCP\IUserSession;

final class StructuredAccessResolver implements AccessResolver {
	public function __construct(
		private IUserSession $users,
		private StructuredAuthority $provider,
	) {
	}
	public function assertAccess(JournalIdentity $identity, JournalResource $resource, string $purpose): void {
		if ($this->users->getUser()?->getUID() !== $identity->userId || !in_array($resource->provider, ['tables', 'deck'], true)) {
			throw new JournalException('permission_lost', 403);
		}
		if (in_array($purpose, ['undo', 'redo', 'revalidate'], true)) {
			throw new JournalException('permission_lost', 403);
		}
		try {
			$this->provider->resource($resource, in_array($purpose, ['prepare', 'dispatch'], true));
		} catch (\Throwable) {
			throw new JournalException('permission_lost', 403);
		}
	}
}
