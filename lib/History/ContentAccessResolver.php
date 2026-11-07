<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Providers\ContentAuthority;
use OCP\IUserSession;

final class ContentAccessResolver implements AccessResolver {
	public function __construct(
		private IUserSession $users,
		private ContentAuthority $native,
	) {
	}
	public function assertAccess(JournalIdentity $identity, JournalResource $resource, string $purpose): void {
		if ($this->users->getUser()?->getUID() !== $identity->userId || !in_array($resource->provider, ['collectives', 'forms', 'notes', 'bookmarks'], true)
			|| in_array($purpose, ['undo', 'redo', 'revalidate'], true) || !preg_match('/^collection:(personal|[1-9][0-9]{0,14})(?:\/(note|bookmark|form):([1-9][0-9]{0,14}))?$/D', $resource->authorityReference, $m)
			|| !preg_match('/^collection:' . preg_quote($m[1], '/') . '\/action:[a-f0-9]{48}$/D', $resource->reference)) {
			throw new JournalException('permission_lost', 403);
		}
		try {
			$write = in_array($purpose, ['prepare', 'dispatch'], true);
			$value = $this->native->collection($resource->provider, $m[1], !$write);
			if (isset($m[2]) && $m[2] !== '') {
				$expected = ['notes' => 'note', 'bookmarks' => 'bookmark', 'forms' => 'form'][$resource->provider] ?? null;
				if ($m[2] !== $expected || $write) {
					throw new \RuntimeException();
				}
				if ($resource->provider === 'forms') {
					$this->native->collection('forms', $m[3], true);
				} else {
					$item = $this->native->request($value['policy'], $resource->provider, $m[2], 0, (int)$m[3]);
					if ($resource->provider === 'bookmarks') {
						$item = $item['item'] ?? [];
					}
					if ($resource->provider === 'bookmarks' && ($item['userId'] ?? null) !== $identity->userId) {
						throw new \RuntimeException();
					}
					if (($item['id'] ?? null) !== (int)$m[3]) {
						throw new \RuntimeException();
					}
				}
			}
			if ($write && (!$value['policy']['writesEnabled'] || !$value['canWrite'])) {
				throw new \RuntimeException();
			}
		} catch (\Throwable) {
			throw new JournalException('permission_lost', 403);
		}
	}
}
