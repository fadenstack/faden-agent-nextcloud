<?php

declare(strict_types=1);

if (getenv('FADEN_DISPOSABLE_TEST') !== 'reconciliation-20261027') {
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
use OCA\FadenstackAgent\History\FilesHistoryPlanner;
use OCA\FadenstackAgent\History\FilesHistoryReconciler;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\SqlJournalStore;
use OCA\FadenstackAgent\Identity\CloudIdentityVerifier;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use OCP\Share\IManager;

$checks = [];
$assert = static function (string $name, bool $condition) use (&$checks): void {
	$checks[] = ['case' => $name, 'pass' => $condition];
	if (!$condition) {
		throw new RuntimeException('Native inspection assertion failed');
	}
};
$denied = static function (string $name, callable $work, array $statuses = [409]) use ($assert): void {
	try {
		$work();
	} catch (Problem $error) {
		$assert($name, in_array($error->status, $statuses, true));
		return;
	}
	$assert($name, false);
};
$mode = $argv[1] ?? 'test';
$uid = $mode === 'seed-ui' ? 'pilot-history-a' : 'faden-workflow-lab';
$user = Server::get(IUserManager::class)->get($uid);
Server::get(IUserSession::class)->setUser($user);
$home = Server::get(IRootFolder::class)->getUserFolder($uid);
$principal = Server::get(CloudIdentityVerifier::class)->verify('synthetic-principal-token-aaaa');
$broker = Server::get(FileService::class);
$broker->bindCloudPrincipal($principal);
$identity = $broker->pairedHistoryIdentity($principal);
$journal = Server::get(JournalService::class);
$inspector = Server::get(FilesHistoryReconciler::class);
$planner = Server::get(FilesHistoryPlanner::class);

if (in_array($mode, ['hold-write', 'race', 'probe-lock', 'probe-read', 'worker-status'], true)) {
	$fixture = json_decode(file_get_contents('/tmp/faden-inspection-workers.json'), true, 16, JSON_THROW_ON_ERROR);
	if ($mode === 'probe-lock') {
		$blocked = false;
		try {
			$inspector->prepare($identity, $fixture['actionId']);
		} catch (Problem $error) {
			$blocked = in_array($error->status, [403, 423], true);
		}
		$row = Server::get(SqlJournalStore::class)->get($fixture['actionId']);
		echo json_encode(['blocked' => $blocked, 'journalUnchanged' => $row['version'] === $fixture['version']
			&& $row['state'] === 'needs_reconciliation'], JSON_THROW_ON_ERROR);
		exit;
	}
	if ($mode === 'probe-read') {
		echo json_encode(['version' => $inspector->prepare($identity, $fixture['actionId'])->version], JSON_THROW_ON_ERROR);
		exit;
	}
	if ($mode === 'worker-status') {
		$entry = $journal->describe($identity, $fixture['actionId']);
		$attempts = $journal->attempts($identity, $fixture['actionId']);
		echo json_encode(['state' => $entry['state'], 'version' => $entry['version'],
			'reconciliations' => count(array_filter($attempts, static fn ($row) => $row['outcome'] === 'reconciled'))], JSON_THROW_ON_ERROR);
		exit;
	}
	if ($mode === 'hold-write') {
		$stream = $home->getById($fixture['fileId'])[0]->fopen('r+');
		if (!is_resource($stream)) {
			exit(3);
		}
		file_put_contents('/tmp/faden-inspection-writer-ready', 'ready');
		for ($i = 0; $i < 200 && !file_exists('/tmp/faden-inspection-writer-release'); $i++) {
			usleep(100000);
		}
		fclose($stream);
		echo "writer-released\n";
		exit;
	}
	$plan = $inspector->prepare($identity, $fixture['actionId']);
	file_put_contents('/tmp/faden-inspection-race-ready-' . getmypid(), 'ready');
	for ($i = 0; $i < 200 && !file_exists('/tmp/faden-inspection-race-release'); $i++) {
		usleep(100000);
	}
	try {
		$inspector->execute($identity, $plan);
		echo json_encode(['outcome' => 'reconciled']);
	} catch (Problem $error) {
		echo json_encode(['outcome' => 'refused', 'status' => $error->status]);
	}
	exit;
}

$folder = $home->newFolder('Inspection ' . bin2hex(random_bytes(5)));
$before = "Original inspection fixture.\n";
$after = "Recorded generated inspection fixture.\n";
$seed = static function (string $name, string $direction = 'apply', string $state = 'complete', ?string $replacement = null, ?int $ttl = null) use ($folder, $home, $before, $after, $broker, $identity, $journal, $planner): array {
	$file = $folder->newFile($name, $before);
	$forward = $replacement ?? $after;
	if ($direction === 'apply') {
		$entry = $journal->prepare($identity, new JournalResource('files', 'parent:' . $folder->getId() . '/name:' . $name,
			'file:' . $file->getId(), JournalResource::PRIVATE_TEXT_RECOVERY), 'nc_update_text_file',
			['operation' => 'update', 'filename' => $name, 'content' => $forward], ['operation' => 'update', 'content' => $before],
			['fileId' => $file->getId(), 'parentId' => $folder->getId(), 'parentPath' => $home->getRelativePath($folder->getPath()),
				'beforeHash' => hash('sha256', $before), 'afterHash' => hash('sha256', $forward)], $ttl);
	} else {
		$selected = $broker->select($file->getId());
		$read = $broker->read($selected['id']);
		$proposal = $broker->propose($selected['id'], 'update', '', $forward, $read['file']['etag']);
		$broker->approve($proposal['id']);
		$result = $broker->commit($proposal['id']);
		$id = $result['actionId'];
		if ($direction === 'redo') {
			Server::get(FilesHistoryExecutor::class)->execute($identity, $planner->prepare($identity, $id, 'undo'));
		}
		$entry = $journal->claim($identity, $id, $direction, $journal->describe($identity, $id)['version']);
	}
	$journal->markDispatched($identity, $entry['id'], $entry['ticket']);
	$wanted = $direction === 'undo' ? $before : $forward;
	$previous = $direction === 'undo' ? $forward : $before;
	$file->putContent($state === 'complete' ? $wanted : ($state === 'unchanged' ? $previous : 'Partial or later native content'));
	$journal->abort($identity, $entry['id'], $entry['ticket']);
	return [$file, $entry];
};

if ($mode === 'seed-ui') {
	$items = [];
	foreach (['complete', 'unchanged', 'partial'] as $state) {
		[$file, $entry] = $seed('inspect-' . $state . '.md', 'apply', $state);
		$items[$state] = ['actionId' => $entry['id'], 'fileId' => $file->getId(), 'name' => $file->getName()];
	}
	echo json_encode(['folder' => $folder->getName(), 'folderId' => $folder->getId(), 'items' => $items], JSON_THROW_ON_ERROR);
	exit;
}

foreach (['apply', 'undo', 'redo'] as $direction) {
	foreach (['complete', 'unchanged'] as $effect) {
		[$file, $entry] = $seed($direction . '-' . $effect . '.md', $direction, $effect);
		$bytes = $file->getContent();
		$revision = $file->getStorage()->getETag($file->getInternalPath());
		$attempts = count($journal->attempts($identity, $entry['id']));
		$plan = $inspector->prepare($identity, $entry['id']);
		$assert($direction . '/' . $effect . ' inspection preview makes no journal or native change',
			$file->getContent() === $bytes && count($journal->attempts($identity, $entry['id'])) === $attempts);
		$result = $inspector->execute($identity, $plan);
		$expected = $effect === 'complete' ? ($direction === 'undo' ? 'undone' : 'applied')
			: match ($direction) {
				'apply' => 'failed', 'undo' => 'applied', 'redo' => 'undone'
			};
		$assert($direction . '/' . $effect . ' records the inspected content state without replay', $result['state'] === $expected
			&& !$result['inspectionChangedFile'] && $result['externalEffects'] === 'unverified' && $file->getContent() === $bytes
			&& $file->getStorage()->getETag($file->getInternalPath()) === $revision);
		$history = $journal->attempts($identity, $entry['id']);
		$last = $history[array_key_last($history)];
		$assert($direction . '/' . $effect . ' appends a distinct signed inspection receipt', count($history) === $attempts + 1
			&& $last['outcome'] === 'reconciled' && $last['reason'] === ($effect === 'complete' ? 'verified' : 'unchanged'));
		$denied($direction . '/' . $effect . ' refuses a stale inspection plan', fn () => $inspector->execute($identity, $plan));
		$denied($direction . '/' . $effect . ' old dispatched ticket cannot finish again', fn () => $journal->finish($identity, $entry['id'], $entry['ticket'], 'unknown'));
		$assert($direction . '/' . $effect . ' retains only justified recovery eligibility',
			$journal->describe($identity, $entry['id'])['snapshotAvailable'] === ($expected !== 'failed'));
	}
}

[$partial, $entry] = $seed('partial.md', 'apply', 'partial');
$denied('partial or later native bytes stay unresolved', fn () => $inspector->prepare($identity, $entry['id']));
$assert('inconclusive inspection preserves the unresolved claim', $journal->describe($identity, $entry['id'])['state'] === 'needs_reconciliation');
$denied('unresolved partial bytes block a new action on the same native file', fn () => $journal->prepare($identity,
	new JournalResource('files', 'file:' . $partial->getId(), 'file:' . $partial->getId()), 'nc_update_text_file', [], [], []));
$context = $broker->select($partial->getId());
$read = $broker->read($context['id']);
$proposal = $broker->propose($context['id'], 'update', '', 'Replacement must not dispatch', $read['file']['etag']);
$broker->approve($proposal['id']);
$denied('the real paired Files broker cannot bypass an unresolved claim', fn () => $broker->commit($proposal['id']));
$assert('refused original broker mutation preserves partial bytes', $partial->getContent() === 'Partial or later native content');
[$noOp, $entry] = $seed('identical.md', 'apply', 'complete', $before);
$denied('identical before and after cannot prove either outcome', fn () => $inspector->prepare($identity, $entry['id']));

[$changed, $entry] = $seed('changed-after-preview.md');
$plan = $inspector->prepare($identity, $entry['id']);
$changed->putContent('Independent later edit');
$denied('a native edit after inspection preview prevents resolution', fn () => $inspector->execute($identity, $plan));
$assert('later native edit is preserved and claim remains unresolved', $changed->getContent() === 'Independent later edit'
	&& $journal->describe($identity, $entry['id'])['state'] === 'needs_reconciliation');

[$moved, $entry] = $seed('renamed-target.md');
$moved->move($folder->getPath() . '/renamed-by-user.md');
$denied('inspection does not retarget a renamed native file', fn () => $inspector->prepare($identity, $entry['id']));

[$private, $entry] = $seed('private-then-shared.md');
$shares = Server::get(IManager::class);
$share = $shares->newShare();
$share->setNode($private)->setShareType(\OCP\Share\IShare::TYPE_USER)->setSharedWith('pilot-bob')
	->setSharedBy($uid)->setShareOwner($uid)->setPermissions(\OCP\Constants::PERMISSION_READ);
$share = $shares->createShare($share);
try {
	$denied('widening the native audience refuses inspection', fn () => $inspector->prepare($identity, $entry['id']), [403]);
} finally {
	$shares->deleteShare($share);
}

[$expired, $entry] = $seed('expired.md', 'apply', 'complete', null, 1);
sleep(2);
$journal->expire($identity);
$denied('expired snapshots cannot be reconstructed to clear an unresolved claim', fn () => $inspector->prepare($identity, $entry['id']));
$assert('privacy expiry retains unresolved metadata', $journal->describe($identity, $entry['id'])['state'] === 'needs_reconciliation'
	&& !$journal->describe($identity, $entry['id'])['snapshotAvailable']);

[$workerFile, $workerEntry] = $seed('worker-coordination.md');
$workers = ['fileId' => $workerFile->getId(), 'actionId' => $workerEntry['id'], 'version' => $journal->describe($identity, $workerEntry['id'])['version']];
file_put_contents('/tmp/faden-inspection-workers.json', json_encode($workers, JSON_THROW_ON_ERROR));
echo json_encode(['layer' => 'actual native Files readback and durable journal', 'checks' => $checks,
	'workers' => $workers], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
