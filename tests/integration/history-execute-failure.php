<?php

declare(strict_types=1);

if (getenv('FADEN_DISPOSABLE_TEST') !== 'foundation-20261026') {
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (\Throwable $error): never {
	fwrite(STDERR, $error::class . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n");
	exit(1);
});

use OCA\FadenstackAgent\History\FilesHistoryExecutor;
use OCA\FadenstackAgent\History\FilesHistoryPlanner;
use OCA\FadenstackAgent\History\JournalException;
use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

$user = Server::get(IUserManager::class)->get('faden-workflow-lab');
Server::get(IUserSession::class)->setUser($user);
// Separate native processes ensure a real, freshly loaded Nextcloud quota wrapper.
// No core source, reflection or custom fault callback is used by the executor.
if (($argv[1] ?? '') === 'quota') {
	$user->setQuota($argv[2]);
	echo json_encode(['quota_set' => true], JSON_THROW_ON_ERROR) . "\n";
	exit;
}
$home = Server::get(IRootFolder::class)->getUserFolder($user->getUID());
$broker = Server::get(FileService::class);
$planner = Server::get(FilesHistoryPlanner::class);
$executor = Server::get(FilesHistoryExecutor::class);
$journal = Server::get(JournalService::class);
$original = str_repeat('Recoverable original text. ', 8192);
$generated = "Small generated output\n";
if (($argv[1] ?? '') === 'hold') {
	$file = $home->getById((int)$argv[2])[0];
	$stream = $file->fopen('rb');
	if (!is_resource($stream)) {
		exit(3);
	}
	$marker = '/tmp/faden-history-lock-' . $file->getId();
	try {
		file_put_contents($marker . '.ready', 'ready');
		$deadline = microtime(true) + 45;
		while (!is_file($marker . '.release') && microtime(true) < $deadline) {
			usleep(100000);
		}
	} finally {
		fclose($stream);
		unlink($marker . '.ready');
		if (is_file($marker . '.release')) {
			unlink($marker . '.release');
		}
	}
	exit;
}
if (($argv[1] ?? '') === 'prepare') {
	$folder = $home->newFolder('History storage failure ' . bin2hex(random_bytes(5)));
	$file = $folder->newFile('quota-failure.md', $original);
	$context = $broker->select($file->getId());
	$read = $broker->read($context['id']);
	$proposal = $broker->propose($context['id'], 'update', '', $generated, $read['file']['etag']);
	$broker->approve($proposal['id']);
	$committed = $broker->commit($proposal['id']);
	$plan = $broker->prepareHistory($committed['actionId'], 'undo');
	echo json_encode(['actionId' => $plan->actionId, 'scopeHash' => $plan->scopeHash, 'fileId' => $file->getId(),
		'originalQuota' => $user->getQuota(), 'limitedQuota' => ($home->getStorage()->getCache()->get($home->getInternalPath())['size'] + 65536) . ' B'], JSON_THROW_ON_ERROR) . "\n";
	exit;
}
$identity = new JournalIdentity($user->getUID(), $argv[3], 'native-history-failure-test-session');
if (($argv[1] ?? '') === 'execute') {
	$file = $home->getById((int)$argv[4])[0];
	$diagnostic = ['free' => $file->getStorage()->free_space($file->getInternalPath()), 'quota' => $user->getQuota(),
		'files_size' => $home->getStorage()->getCache()->get($home->getInternalPath())['size'],
		'root_size' => $home->getStorage()->getCache()->get('')['size']];
	try {
		$plan = $planner->prepare($identity, $argv[2], 'undo');
		$executor->execute($identity, $plan);
		echo json_encode(['unexpected_success' => true], JSON_THROW_ON_ERROR) . "\n";
	} catch (JournalException $error) {
		echo json_encode(['reason' => $error->reason, 'status' => $error->status, 'storage' => $diagnostic], JSON_THROW_ON_ERROR) . "\n";
	} catch (\OCP\Lock\LockedException) {
		echo json_encode(['reason' => 'native_locked', 'status' => 409], JSON_THROW_ON_ERROR) . "\n";
	}
	exit;
}
if (($argv[1] ?? '') === 'verify-lock') {
	$entry = $journal->preview($identity, $argv[2]);
	$file = $home->getById((int)$argv[4])[0];
	$checks = [
		['case' => 'another native process holds a read lock and refuses the inverse write lock', 'pass' => $file->getContent() === $generated],
		['case' => 'a pre-dispatch lock failure returns the action to applied with its snapshot', 'pass' => $entry['state'] === 'applied' && $entry['snapshotAvailable']],
		['case' => 'a failed pre-dispatch claim remains in immutable attempts', 'pass' => count(array_filter($journal->attempts($identity, $argv[2]), fn ($a) => $a['outcome'] === 'cancelled')) === 1],
	];
	$plan = $planner->prepare($identity, $argv[2], 'undo');
	$result = $executor->execute($identity, $plan);
	$checks[] = ['case' => 'a fresh plan after the lock is released can complete Undo', 'pass' => $result['state'] === 'undone' && $file->getContent() === $original];
	echo json_encode(['layer' => 'independent native process lock contention', 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
	exit;
}
if (($argv[1] ?? '') !== 'verify') {
	exit(2);
}
$entry = $journal->preview($identity, $argv[2]);
$file = $home->getById((int)$argv[4])[0];
$content = $file->getContent();
$checks = [];
$assert = function (string $name, bool $condition) use (&$checks): void {
	$checks[] = ['case' => $name, 'pass' => $condition];
	if (!$condition) {
		throw new RuntimeException('Failed check ' . count($checks));
	}
};
$assert('real native quota failure after truncation remains needs_reconciliation', $entry['state'] === 'needs_reconciliation');
$assert('failed inverse retains encrypted original and generated recipes', $entry['snapshot']['inverse']['content'] === $original && $entry['snapshot']['forward']['content'] === $generated);
$assert('partial native output is not mistaken for either a verified inverse or an unchanged file', $content !== $original && $content !== $generated);
$assert('uncertain inverse retains a dispatched receipt without a verified undone receipt', count(array_filter($journal->attempts($identity, $argv[2]), fn ($a) => $a['outcome'] === 'needs_reconciliation')) === 1
	&& count(array_filter($journal->attempts($identity, $argv[2]), fn ($a) => $a['outcome'] === 'undone')) === 0);
try {
	$planner->prepare($identity, $argv[2], 'undo');
	$assert('an uncertain inverse cannot be retried automatically', false);
} catch (JournalException $error) {
	$assert('an uncertain inverse cannot be retried automatically', $error->reason === 'state_conflict');
}
$context = $broker->select($file->getId());
$read = $broker->read($context['id']);
$proposal = $broker->propose($context['id'], 'update', '', 'A competing mutation', $read['file']['etag']);
$broker->approve($proposal['id']);
try {
	$broker->commit($proposal['id']);
	$assert('the unresolved resource claim blocks another agent write', false);
} catch (Problem $error) {
	$assert('the unresolved resource claim blocks another agent write', $error->status === 409);
}
$assert('refused retry and competing mutation preserve the partial native evidence', $file->getContent() === $content);
echo json_encode(['layer' => 'real native quota failure across restarted PHP processes', 'checks' => $checks,
	'partial_bytes' => strlen($content), 'partial_sha256' => hash('sha256', $content), 'http_or_tool_enabled' => false], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
