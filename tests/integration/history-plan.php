<?php

declare(strict_types=1);

if (getenv('FADEN_DISPOSABLE_TEST') !== 'foundation-20261026') {
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (\Throwable $error): never {
	fwrite(STDERR, $error::class . ': ' . $error->getMessage() . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n");
	exit(1);
});

use OCA\FadenstackAgent\History\FilesHistoryPlanner;
use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\VerifiedReadback;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

$session = Server::get(IUserSession::class);
$user = Server::get(IUserManager::class)->get('faden-workflow-lab');
$session->setUser($user);
$home = Server::get(IRootFolder::class)->getUserFolder($user->getUID());
$folder = $home->newFolder('Typed history plan ' . bin2hex(random_bytes(5)));
$journal = Server::get(JournalService::class);
$planner = Server::get(FilesHistoryPlanner::class);
$identity = new JournalIdentity($user->getUID(), hash('sha256', $folder->getName()), 'typed-history-plan-fixture-session');
$checks = [];
$assert = function (string $name, bool $condition) use (&$checks): void {
	$checks[] = ['case' => $name, 'pass' => $condition];
	if (!$condition) {
		throw new RuntimeException('Failed: ' . $name);
	}
};
$denied = function (string $name, callable $work, int $status = 409) use ($assert): void {
	try {
		$work();
	} catch (Problem $error) {
		$assert($name, $error->status === $status);
		return;
	}
	$assert($name, false);
};
$before = "Before: ä\n";
$after = "# Agent text\n\nAfter: 日本語\n";
// Real journal + explicit fixture write/readback. No cloud-governance or inverse
// execution acceptance is claimed by this planner-only test.
$applied = function (string $name, string $operation = 'nc_update_text_file', bool $validHash = true) use ($journal, $identity, $folder, $home, $before, $after): array {
	$file = $folder->newFile($name, $before);
	$entry = $journal->prepare($identity, new JournalResource('files', 'parent:' . $folder->getId() . '/name:' . $name, 'file:' . $file->getId()), $operation,
		['operation' => 'update', 'filename' => $name, 'content' => $after], ['operation' => 'update', 'content' => $before],
		['fileId' => $file->getId(), 'parentId' => $folder->getId(), 'parentPath' => $home->getRelativePath($folder->getPath()),
			'beforeHash' => hash('sha256', $before), 'afterHash' => $validHash ? hash('sha256', $after) : str_repeat('0', 64)]);
	$journal->markDispatched($identity, $entry['id'], $entry['ticket']);
	$file->putContent($after);
	$file = $folder->get($name);
	$receipt = $journal->finish($identity, $entry['id'], $entry['ticket'], 'verified', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
	return [$file, $receipt];
};

[$file, $entry] = $applied('roundtrip.md');
$undo = $planner->prepare($identity, $entry['id'], 'undo');
$assert('typed Undo preview uses exact saved before/after and current native target', $undo->before === $after && $undo->content === $before && $undo->fileId === $file->getId() && $undo->target === $home->getRelativePath($file->getPath()));
$assert('preparing and revalidating a plan never mutates native bytes', $planner->revalidate($identity, $undo)->digest === $undo->digest && $file->getContent() === $after);
$claim = $journal->claim($identity, $entry['id'], 'undo', $entry['version']);
$denied('a claimed attempt cannot obtain another executable-looking Undo preview', fn () => $planner->prepare($identity, $entry['id'], 'undo'));
$journal->abort($identity, $entry['id'], $claim['ticket']);
$denied('a changed journal version invalidates an earlier plan even with identical native bytes', fn () => $planner->revalidate($identity, $undo));
$undo = $planner->prepare($identity, $entry['id'], 'undo');
$claim = $journal->claim($identity, $entry['id'], 'undo', $undo->version);
$journal->markDispatched($identity, $entry['id'], $claim['ticket']);
$file->putContent($before); // Explicit fixture write, not a production inverse handler.
$file = $folder->get('roundtrip.md');
$journal->finish($identity, $entry['id'], $claim['ticket'], 'verified', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
$redo = $planner->prepare($identity, $entry['id'], 'redo');
$assert('typed Redo preserves exact recorded output without new generation', $redo->before === $before && $redo->content === $after && $redo->fileId === $undo->fileId);
$denied('an undone action cannot prepare another Undo', fn () => $planner->prepare($identity, $entry['id'], 'undo'));
$file->putContent('Human change');
$denied('later native edits invalidate a prepared Redo without overwriting them', fn () => $planner->revalidate($identity, $redo));
$assert('human bytes remain unchanged after refused Redo preparation', $file->getContent() === 'Human change');

[$moved, $movedEntry] = $applied('before-rename.md');
$moved->move($folder->getPath() . '/after-rename.md');
$denied('same native ID under a new name cannot silently retarget history', fn () => $planner->prepare($identity, $movedEntry['id'], 'undo'));
[$missing, $missingEntry] = $applied('deleted.md');
$missing->delete();
$denied('missing native authority cannot recover saved payload through history', fn () => $planner->prepare($identity, $missingEntry['id'], 'undo'), 403);
[$invalid, $invalidEntry] = $applied('invalid-hash.md', validHash: false);
$denied('inconsistent stored recipe hashes do not become a typed preview', fn () => $planner->prepare($identity, $invalidEntry['id'], 'undo'));
[$unsupported, $unsupportedEntry] = $applied('unsupported-kind.md', 'nc_create_text_file');
$denied('creation/deletion recipes remain explicitly unavailable', fn () => $planner->prepare($identity, $unsupportedEntry['id'], 'undo'), 422);

[$ready, $readyEntry] = $applied('restricted.md');
$session->setUser(Server::get(IUserManager::class)->get('pilot-bob'));
try {
	$denied('another native account cannot prepare the owner snapshot', fn () => $planner->prepare($identity, $readyEntry['id'], 'undo'), 403);
} finally {
	$session->setUser($user);
}
$config = Server::get(IConfig::class);
$old = $config->getAppValue('fadenstack_agent', ScopePolicy::CONFIG_KEY, '');
try {
	$config->setAppValue('fadenstack_agent', ScopePolicy::CONFIG_KEY, json_encode(['revision' => 'planner-read-only',
		'sources' => [['kind' => 'user-files']], 'destinations' => [], 'tools' => ['nc_read_file'],
		'classificationDefault' => 'standard', 'sourceClasses' => ['standard']], JSON_THROW_ON_ERROR));
	$denied('current native write scope is mandatory even for a journal owner', fn () => $planner->prepare($identity, $readyEntry['id'], 'undo'), 404);
} finally {
	$config->setAppValue('fadenstack_agent', ScopePolicy::CONFIG_KEY, $old);
}
$assert('planning and denied attempts leave the ready native file intact', $ready->getContent() === $after);
echo json_encode(['layer' => 'typed Files history planning against real Nextcloud Files, scope and encrypted journal', 'native_executor' => false,
	'cloud_governance' => false, 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
