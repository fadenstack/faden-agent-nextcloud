<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Providers\WorkflowAuthority;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserSession;

final class WorkflowAccessResolver implements AccessResolver {
	public function __construct(
		private IUserSession $users,
		private WorkflowAuthority $native,
	) {
	}
	public function assertAccess(JournalIdentity $identity, JournalResource $resource, string $purpose): void {
		if ($this->users->getUser()?->getUID() !== $identity->userId || !in_array($resource->provider, ['whiteboard', 'flow'], true)
			|| in_array($purpose, ['undo', 'redo', 'revalidate'], true) || !preg_match('/^action:[a-f0-9]{48}$/D', $resource->reference)) {
			throw new JournalException('permission_lost', 403);
		}
		try {
			$write = in_array($purpose, ['prepare', 'dispatch'], true);
			$policy = $this->native->policy($resource->provider, !$write);
			if ($write && !$policy['writesEnabled']) {
				throw new \RuntimeException();
			}
			if ($resource->provider === 'flow') {
				if ($resource->authorityReference !== 'personal') {
					throw new \RuntimeException();
				}
				$this->native->rules($policy);
			} else {
				if (!preg_match('/^(file|folder):([1-9][0-9]{0,14})$/D', $resource->authorityReference, $m)) {
					throw new \RuntimeException();
				}
				$value = $this->native->node($policy, 'nextcloud:files:' . $m[2], $write ? 'write' : 'read');
				if (($m[1] === 'folder' && !$value['node'] instanceof Folder) || ($m[1] === 'file' && (!$value['node'] instanceof File || !str_ends_with($value['node']->getName(), '.whiteboard'))) || ($write && $m[1] !== 'folder')) {
					throw new \RuntimeException();
				}
			}
		} catch (\Throwable) {
			throw new JournalException('permission_lost', 403);
		}
	}
}
