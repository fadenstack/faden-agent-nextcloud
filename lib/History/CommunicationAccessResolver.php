<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Providers\CommunicationAuthority;
use OCP\IUserSession;

/** Access registry stays independent of the executor/journal dependency graph. */
final class CommunicationAccessResolver implements AccessResolver {
	public function __construct(
		private IUserSession $users,
		private CommunicationAuthority $native,
	) {
	}
	public function assertAccess(JournalIdentity $identity, JournalResource $resource, string $purpose): void {
		if ($this->users->getUser()?->getUID() !== $identity->userId || !in_array($resource->provider, ['mail', 'talk'], true)
			|| in_array($purpose, ['undo', 'redo', 'revalidate'], true)) {
			throw new JournalException('permission_lost', 403);
		}
		$prefix = $resource->provider === 'mail' ? 'account:' : 'room:';
		if (!str_starts_with($resource->authorityReference, $prefix) || !preg_match('/^' . preg_quote($resource->authorityReference, '/') . '\/action:[a-f0-9]{48}$/D', $resource->reference)) {
			throw new JournalException('permission_lost', 403);
		}
		$id = substr($resource->authorityReference, strlen($prefix));
		$write = in_array($purpose, ['prepare', 'dispatch'], true);
		try {
			if ($resource->provider === 'mail' && !preg_match('/^[1-9][0-9]{0,14}$/D', $id)) {
				throw new \RuntimeException();
			}
			$value = $resource->provider === 'mail' ? $this->native->mailAccount((int)$id, !$write) : $this->native->talkRoom($id, !$write);
			if ($write && (!$value['policy']['writesEnabled'] || ($resource->provider === 'talk' && !$value['canWrite']))) {
				throw new \RuntimeException();
			}
		} catch (\Throwable) {
			throw new JournalException('permission_lost', 403);
		}
	}
}
