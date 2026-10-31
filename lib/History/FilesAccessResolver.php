<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;

/** Payload access only. Exact native mutation permissions stay in the typed broker. */
final class FilesAccessResolver implements AccessResolver {
	public function __construct(
		private IRootFolder $root,
		private IUserSession $users,
		private ScopePolicy $scope,
	) {
	}

	public function assertAccess(JournalIdentity $identity, JournalResource $resource, string $purpose): void {
		if ($resource->provider !== 'files' || $this->users->getUser()?->getUID() !== $identity->userId
			|| !preg_match('/^(file|folder):([1-9][0-9]{0,14})$/D', $resource->authorityReference, $match)) {
			throw new JournalException('permission_lost', 403);
		}
		try {
			$nodes = $this->root->getUserFolder($identity->userId)->getById((int)$match[2]);
			foreach ($nodes as $node) {
				if (!$node->isReadable()) {
					continue;
				}
				if ($match[1] === 'folder' && $node instanceof Folder) {
					$this->scope->describeNode($node, in_array($purpose, ['prepare', 'dispatch'], true) ? 'write' : 'read');
					return;
				}
				if ($match[1] === 'file' && $node instanceof File) {
					// Dispatch intent reveals no payload. A typed Files broker may already
					// hold its authorized r+ write stream; opening a second read would
					// conflict with that same native lock. Preview/readback still probe.
					$grant = $this->scope->describeNode($node, 'history', $purpose !== 'dispatch');
					if ($purpose === 'revalidate' && (!$node->isUpdateable() || !$node->getStorage()->isLocal() || $node->isEncrypted()
						|| $grant['sourceClass'] !== 'standard' || $grant['audience'] !== 'private')) {
						throw new JournalException('permission_lost', 403);
					}
					return;
				}
			}
		} catch (\Throwable) {
			// A missing/moved/revoked storage object must not reveal saved content.
		}
		throw new JournalException('permission_lost', 403);
	}
}
