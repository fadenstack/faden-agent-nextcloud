<?php

declare(strict_types=1);

if (getenv('FADEN_DISPOSABLE_TEST') !== 'foundation-20261026') {
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (\Throwable $error): never {
	fwrite(STDERR, $error::class . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n");
	fwrite(STDERR, json_encode($GLOBALS['checks'] ?? [], JSON_THROW_ON_ERROR) . "\n");
	exit(1);
});

use OCA\FadenstackAgent\History\FilesHistoryExecutor;
use OCA\FadenstackAgent\History\FilesHistoryPlan;
use OCA\FadenstackAgent\History\FilesHistoryPlanner;
use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\VerifiedReadback;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use OCP\Share\IManager;

$session = Server::get(IUserSession::class);
$user = Server::get(IUserManager::class)->get('faden-workflow-lab');
$session->setUser($user);
$home = Server::get(IRootFolder::class)->getUserFolder($user->getUID());
$folder = $home->newFolder('Typed history execution ' . bin2hex(random_bytes(5)));
$journal = Server::get(JournalService::class);
$planner = Server::get(FilesHistoryPlanner::class);
$executor = Server::get(FilesHistoryExecutor::class);
$broker = Server::get(FileService::class);
$checks = [];
$assert = function (string $name, bool $condition) use (&$checks): void {
	$checks[] = ['case' => $name, 'pass' => $condition];
	if (!$condition) {
		throw new RuntimeException('Failed check ' . count($checks));
	}
};
$denied = function (string $name, callable $work, array $statuses = [409]) use ($assert): void {
	try {
		$work();
	} catch (Problem $error) {
		$assert($name, in_array($error->status, $statuses, true));
		return;
	}
	$assert($name, false);
};
$before = "Before: ä\n";
$after = "# Agent text\n\nAfter: 日本語\n";
$applied = function (string $name, ?string $original = null, ?string $replacement = null) use ($broker, $folder, $before, $after, $user): array {
	$file = $folder->newFile($name, $original ?? $before);
	$context = $broker->select($file->getId());
	$read = $broker->read($context['id']);
	$proposal = $broker->propose($context['id'], 'update', '', $replacement ?? $after, $read['file']['etag']);
	$broker->approve($proposal['id']);
	$committed = $broker->commit($proposal['id']);
	$plan = $broker->prepareHistory($committed['actionId'], 'undo');
	$identity = new JournalIdentity($user->getUID(), $plan->scopeHash, $broker->identity()['binding']);
	return [$file, $plan, $identity, $committed];
};

// This is native executor acceptance with an explicitly invoked test driver.
// It does not simulate an SDK approval or establish cloud principal binding.
[$file, $undo, $identity, $committed] = $applied('roundtrip.md');
if ($committed['file']['size'] !== strlen($after) || $committed['file']['etag'] !== $undo->expectedEtag) {
	fwrite(STDERR, json_encode(['size' => $committed['file']['size'], 'expectedSize' => strlen($after),
		'metadataEtag' => $committed['file']['etag'], 'storageEtag' => $undo->expectedEtag], JSON_THROW_ON_ERROR) . "\n");
}
$assert('the original broker returns current native size and revision after a guarded update', $committed['file']['size'] === strlen($after)
	&& $committed['file']['etag'] === $undo->expectedEtag);
$result = $executor->execute($identity, $undo);
$assert('actual broker action Undo restores exact UTF-8 bytes on the same native file', $file->getContent() === $before && $result['fileId'] === $file->getId() && $result['state'] === 'undone');
$entry = $journal->preview($identity, $undo->actionId);
$assert('verified Undo retains both typed recipes and records the storage revision', $entry['snapshot']['forward']['content'] === $after && $entry['snapshot']['inverse']['content'] === $before && $entry['snapshot']['revision'] === $result['revision']);
$denied('a consumed Undo plan cannot run twice', fn () => $executor->execute($identity, $undo));
$redo = $planner->prepare($identity, $undo->actionId, 'redo');
$result = $executor->execute($identity, $redo);
$assert('Redo reapplies exact recorded content with no new generation or native ID', $file->getContent() === $after && $result['fileId'] === $file->getId() && $result['state'] === 'applied');
$metadata = $broker->resource('nextcloud:files:' . $file->getId());
$assert('Redo updates native file metadata to the verified bytes and storage revision', $metadata['size'] === strlen($after) && $metadata['etag'] === $result['revision']);
$attempts = $journal->attempts($identity, $undo->actionId);
$assert('original apply, Undo and Redo each retain an independent verified attempt', count(array_filter($attempts, fn ($a) => $a['outcome'] === 'applied')) === 2 && count(array_filter($attempts, fn ($a) => $a['outcome'] === 'undone')) === 1);
$denied('a consumed Redo plan cannot run twice', fn () => $executor->execute($identity, $redo));

[$empty, $emptyUndo, $emptyIdentity] = $applied('empty-original.txt', '', 'Generated from empty');
$executor->execute($emptyIdentity, $emptyUndo);
$assert('Undo supports an empty original without skipping native truncation', $empty->getContent() === '');
$executor->execute($emptyIdentity, $planner->prepare($emptyIdentity, $emptyUndo->actionId, 'redo'));
$assert('Redo after an empty original restores the generated bytes', $empty->getContent() === 'Generated from empty');
$assert('native metadata reflects nonempty Redo after a zero-byte Undo', $broker->resource('nextcloud:files:' . $empty->getId())['size'] === strlen('Generated from empty'));

[$cleared, $clearedUndo, $clearedIdentity] = $applied('empty-output.md', 'Original before empty output', '');
$executor->execute($clearedIdentity, $clearedUndo);
$assert('Undo restores an original after an empty generated output', $cleared->getContent() === 'Original before empty output');
$executor->execute($clearedIdentity, $planner->prepare($clearedIdentity, $clearedUndo->actionId, 'redo'));
$assert('Redo to empty output verifies zero bytes rather than a false write success', $cleared->getContent() === '');
$assert('native metadata reflects zero-byte Redo rather than the original size', $broker->resource('nextcloud:files:' . $cleared->getId())['size'] === 0);

[$human, $humanUndo, $humanIdentity] = $applied('human-edit.md');
$human->putContent('Independent human edit');
$denied('later human bytes block Undo before any journal dispatch', fn () => $executor->execute($humanIdentity, $humanUndo));
$assert('a refused Undo preserves both human bytes and the original applied entry', $human->getContent() === 'Independent human edit' && $journal->preview($humanIdentity, $humanUndo->actionId)['state'] === 'applied');

[$stale, $staleUndo, $staleIdentity] = $applied('stale-version.md');
$claim = $journal->claim($staleIdentity, $staleUndo->actionId, 'undo', $staleUndo->version);
$denied('another tab holding the action claim blocks the executor', fn () => $executor->execute($staleIdentity, $staleUndo));
$journal->abort($staleIdentity, $staleUndo->actionId, $claim['ticket']);
$denied('a cancelled competing attempt still invalidates an old approval plan', fn () => $executor->execute($staleIdentity, $staleUndo));
$assert('cancelled competing attempts retain native bytes and the action', $stale->getContent() === $after && $journal->preview($staleIdentity, $staleUndo->actionId)['state'] === 'applied');

[$forged, $trusted, $forgedIdentity] = $applied('forged-plan.md');
$changed = new FilesHistoryPlan($trusted->actionId, $trusted->direction, $trusted->version, $trusted->scopeHash,
	$trusted->fileId, $trusted->parentId, $trusted->target, $trusted->expectedEtag, $trusted->before, 'Unapproved replacement');
$denied('a caller-created plan cannot substitute a different inverse body', fn () => $executor->execute($forgedIdentity, $changed));
$assert('rejected substituted bytes never reach native storage', $forged->getContent() === $after);

[$renamed, $renamedUndo, $renamedIdentity] = $applied('rename-before.md');
$renamed->move($folder->getPath() . '/rename-after.md');
$denied('the same native ID under another name cannot be silently retargeted', fn () => $executor->execute($renamedIdentity, $renamedUndo));
$assert('refused retargeting preserves the renamed file', $renamed->getContent() === $after);

[$restricted, $restrictedUndo, $restrictedIdentity] = $applied('restricted.md');
$config = Server::get(IConfig::class);
$old = $config->getAppValue('fadenstack_agent', ScopePolicy::CONFIG_KEY, '');
try {
	$config->setAppValue('fadenstack_agent', ScopePolicy::CONFIG_KEY, json_encode(['revision' => 'executor-read-only',
		'sources' => [['kind' => 'user-files']], 'destinations' => [], 'tools' => ['nc_read_file'],
		'classificationDefault' => 'standard', 'sourceClasses' => ['standard']], JSON_THROW_ON_ERROR));
	$denied('current native scope revocation prevents an approved-looking inverse', fn () => $executor->execute($restrictedIdentity, $restrictedUndo), [403, 404]);
} finally {
	$config->setAppValue('fadenstack_agent', ScopePolicy::CONFIG_KEY, $old);
}
$assert('policy refusal preserves the applied journal state and native bytes', $restricted->getContent() === $after && $journal->preview($restrictedIdentity, $restrictedUndo->actionId)['state'] === 'applied');

[$shared, $sharedUndo, $sharedIdentity] = $applied('audience-changed.md');
$shares = Server::get(IManager::class);
$share = $shares->newShare();
$share->setNode($shared)->setShareType(\OCP\Share\IShare::TYPE_USER)->setSharedWith('pilot-bob')
	->setSharedBy($user->getUID())->setShareOwner($user->getUID())->setPermissions(\OCP\Constants::PERMISSION_READ);
$share = $shares->createShare($share);
try {
	$denied('a newly widened native audience blocks restoration under private-output policy', fn () => $executor->execute($sharedIdentity, $sharedUndo), [403]);
} finally {
	$shares->deleteShare($share);
}
$assert('an audience-policy refusal leaves generated native bytes intact', $shared->getContent() === $after);
$session->setUser(Server::get(IUserManager::class)->get('pilot-bob'));
try {
	$denied('another native user cannot execute the owner plan', fn () => $executor->execute($sharedIdentity, $sharedUndo), [403]);
} finally {
	$session->setUser($user);
}

[$dependency, $dependencyUndo, $dependencyIdentity] = $applied('dependency.md');
$later = $journal->prepare($dependencyIdentity, new JournalResource('files', 'parent:' . $folder->getId() . '/name:dependency.md', 'file:' . $dependency->getId()),
	'nc_update_text_file', ['operation' => 'update', 'filename' => 'dependency.md', 'content' => $after], ['operation' => 'update', 'content' => $after],
	['fileId' => $dependency->getId(), 'parentId' => $folder->getId(), 'parentPath' => $home->getRelativePath($folder->getPath()),
		'beforeHash' => hash('sha256', $after), 'afterHash' => hash('sha256', $after)]);
$journal->markDispatched($dependencyIdentity, $later['id'], $later['ticket']);
$journal->finish($dependencyIdentity, $later['id'], $later['ticket'], 'verified', new VerifiedReadback($dependency->getStorage()->getETag($dependency->getInternalPath())));
$denied('a later applied action blocks an older inverse even when bytes and revision match', fn () => $executor->execute($dependencyIdentity, $dependencyUndo));
$assert('dependency refusal preserves the older entry and native bytes', $dependency->getContent() === $after && $journal->preview($dependencyIdentity, $dependencyUndo->actionId)['state'] === 'applied');

$collision = $folder->newFile('same-revision-human-edit.md', str_repeat('A', 64));
$context = $broker->select($collision->getId());
$read = $broker->read($context['id']);
$proposal = $broker->propose($context['id'], 'update', '', 'Approved agent replacement', $read['file']['etag']);
$broker->approve($proposal['id']);
$mtime = $collision->getStorage()->filemtime($collision->getInternalPath());
$collision->putContent(str_repeat('B', 64));
// Local native storage derives ETags from inode/mtime/size. Preserve these
// explicitly to exercise a real weak-revision collision without a fake storage.
$collision->getStorage()->touch($collision->getInternalPath(), $mtime);
$assert('the native fixture reproduces changed bytes with an unchanged storage revision', $collision->getStorage()->getETag($collision->getInternalPath()) === $read['file']['etag']);
$denied('the original Files broker refuses changed bytes even when the storage revision collides', fn () => $broker->commit($proposal['id']));
$assert('a same-revision conflict preserves the independent human bytes', $collision->getContent() === str_repeat('B', 64));

echo json_encode(['layer' => 'typed Files native execution against real broker, Files, scope and encrypted journal',
	'native_executor' => true, 'cloud_governance' => false, 'http_or_tool_enabled' => false,
	'fixture_folder_id' => $folder->getId(), 'fixture_folder_name' => $folder->getName(),
	'native_readback' => array_map(static function ($node) use ($broker): array {
		$metadata = $broker->resource('nextcloud:files:' . $node->getId());
		return ['id' => $node->getId(), 'name' => $node->getName(), 'size' => $metadata['size'], 'etag' => $metadata['etag'],
			'sha256' => hash('sha256', $node->getContent())];
	}, [$file, $empty, $cleared]), 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
