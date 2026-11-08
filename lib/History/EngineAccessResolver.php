<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Providers\EngineAuthority;
use OCP\IUserSession;

/** Audit only. Rotation to the same engine account retains authorized inspection. */
final class EngineAccessResolver implements AccessResolver {
	public function __construct(
		private IUserSession $users,
		private EngineAuthority $native,
	) {
	}
	public function assertAccess(JournalIdentity $identity, JournalResource $resource, string $purpose): void {
		if ($this->users->getUser()?->getUID() !== $identity->userId || !in_array($resource->provider, ['windmill', 'n8n'], true)
			|| in_array($purpose, ['undo', 'redo', 'revalidate'], true) || !preg_match('/^action:[a-f0-9]{48}$/D', $resource->reference)) {
			throw new JournalException('permission_lost', 403);
		}
		try {
			$parts = json_decode($resource->authorityReference, true, 4, JSON_THROW_ON_ERROR);
			if (!is_array($parts) || !array_is_list($parts) || !in_array(count($parts), [3, 4], true) || count(array_filter($parts, 'is_string')) !== count($parts)
				|| (count($parts) === 4 && ($resource->provider !== 'n8n' || $parts[3] !== 'n8n-execution-v1'))) {
				throw new \UnexpectedValueException();
			}
			$write = in_array($purpose, ['prepare', 'dispatch'], true);
			$connection = $this->native->connection($resource->provider, $parts[0], $write, !$write);
			if ($connection['endpoint']['stamp'] !== $parts[1] || $this->native->principal($connection) !== $parts[2]) {
				throw new \UnexpectedValueException();
			}
		} catch (\Throwable) {
			throw new JournalException('permission_lost', 403);
		}
	}
}
