<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Files;

use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;

/** Typed Files audit projections. Never returns encrypted snapshots or authorizes an inverse. */
final class Audit {
	private const OPERATIONS = ['nc_create_text_file', 'nc_update_text_file', 'nc_files_create_folder', 'nc_files_copy', 'nc_files_move', 'nc_files_share_readonly'];
	public function __construct(private FileService $files, private JournalService $journal, private IRootFolder $root, private IUserSession $users, private ScopePolicy $scope) {}
	private function identity(): JournalIdentity { return $this->files->journalIdentity(); }
	private function project(JournalIdentity $identity, array $entry): array {
		if ($entry['resource']['provider'] !== 'files' || !in_array($entry['operation'], self::OPERATIONS, true)
			|| ($entry['resource']['recovery'] ?? null) === JournalResource::PRIVATE_TEXT_RECOVERY) throw new Problem('This is not an audit-only Files action.', 404);
		$home = $this->root->getUserFolder($this->users->getUser()->getUID());
		$authority = $entry['resource']['authorityReference'];
		if (!preg_match('/^(file|folder):([1-9][0-9]{0,14})$/D', $authority, $match)) throw new Problem('Unavailable Files action.', 404);
		$node = $home->getById((int)$match[2])[0] ?? null;
		if (!$node instanceof File && !$node instanceof Folder) throw new Problem('The native authority is unavailable.', 403);
		$this->scope->describeNode($node, 'read');
		$observed = 'present';
		if ($match[1] === 'folder' && preg_match('/^parent:' . $match[2] . '\/name:(.+)$/Du', $entry['resource']['reference'], $parts)) {
			if (!$node instanceof Folder) throw new Problem('Unavailable Files target.', 403);
			FileService::folderName($parts[1]);
			if ($node->nodeExists($parts[1])) {
				$node = $node->get($parts[1]);
				$this->scope->describeNode($node, 'read');
			} else {
				// A parent alone cannot authorize historical child names/classification.
				$forward = $this->journal->preview($identity, $entry['id'])['snapshot']['forward'];
				if (($forward['sourceClass'] ?? 'unknown') !== 'standard' || ($this->files->identity()['scopePolicy']['sourceClasses'] ?? []) !== ['standard']) throw new Problem('Earlier target disclosure is unavailable.', 403);
				$observed = 'absent';
				return ['item' => $this->item($entry, $parts[1], rtrim($home->getRelativePath($node->getPath()), '/') . '/' . $parts[1]), 'observed' => $observed];
			}
		}
		$item = $this->item($entry, $node->getName(), $home->getRelativePath($node->getPath()));
		$item['fileUrl'] = $this->files->resource('nextcloud:files:' . $node->getId())['url'];
		return ['item' => $item, 'observed' => $observed];
	}
	private function item(array $entry, string $name, string $path): array {
		return ['id' => $entry['id'], 'provider' => 'files', 'operation' => $entry['operation'], 'name' => $name, 'target' => $path,
			'state' => $entry['state'], 'eligible' => 'none', 'auditOnly' => true, 'version' => $entry['version'], 'createdAt' => $entry['createdAt']];
	}
	public function listing(): array {
		$identity = $this->identity(); $result = [];
		foreach ($this->journal->list($identity, null, ['files'], 50) as $entry) {
			try { $result[] = $this->project($identity, $entry)['item']; } catch (\Throwable) { continue; }
		}
		return $result;
	}
	public function inspect(string $id): array {
		if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new Problem('Unavailable Files action.', 404);
		$identity = $this->identity(); $entry = $this->journal->describe($identity, $id);
		$projection = $this->project($identity, $entry);
		return ['action' => $projection['item'], 'observed' => $projection['observed'], 'journalState' => $entry['state'],
			'outcome' => $entry['state'] === 'applied' ? 'verified_committed' : 'unknown', 'repeatedDispatch' => false,
			'message' => $entry['state'] === 'applied' ? 'The original commitment was verified. Current presence does not prove that content or sharing is unchanged. Inspection sends no write.' : 'The original completion remains uncertain. Current presence or absence does not authorize an automatic retry. Inspection sends no write.'];
	}
}
