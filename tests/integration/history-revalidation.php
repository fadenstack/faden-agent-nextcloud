<?php

declare(strict_types=1);

// Acceptance driver: never run against a user's installation.
if (getenv('FADEN_DISPOSABLE_TEST') !== 'revalidation-20261028') {
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';

use OCA\FadenstackAgent\History\AccessResolverRegistry;
use OCA\FadenstackAgent\History\FilesHistoryExecutor;
use OCA\FadenstackAgent\History\FilesHistoryPlanner;
use OCA\FadenstackAgent\History\FilesRevisionRevalidator;
use OCA\FadenstackAgent\History\JournalCipher;
use OCA\FadenstackAgent\History\JournalPolicy;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\SqlJournalStore;
use OCA\FadenstackAgent\History\VerifiedReadback;
use OCA\FadenstackAgent\Identity\CloudIdentityVerifier;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use OCP\Share\IManager;

final class RevalidationTime implements ITimeFactory {
	public function getTime(): int {
		return time() + 60;
	}
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable('@' . $this->getTime());
	}
	public function getDateTime(string $time = 'now', ?DateTimeZone $timezone = null): DateTime {
		return new DateTime($time, $timezone);
	}
	public function withTimeZone(DateTimeZone $timezone): static {
		return clone $this;
	}
	public function getTimeZone(?string $timezone = null): DateTimeZone {
		return new DateTimeZone($timezone ?? 'UTC');
	}
}

$checks = [];
set_exception_handler(static function (Throwable $error) use (&$checks): never {
	fwrite(STDERR, json_encode(['error' => $error::class, 'message' => $error->getMessage(), 'file' => basename($error->getFile()), 'line' => $error->getLine(), 'checks' => $checks], JSON_THROW_ON_ERROR));
	exit(1);
});
$assert = static function (string $name, bool $value) use (&$checks): void {
	$checks[] = ['case' => $name, 'pass' => $value];
	if (!$value) {
		throw new RuntimeException('Revalidation assertion failed');
	}
};
$denied = static function (string $name, callable $work, array $statuses = [409, 422]) use ($assert): void {
	try {
		$work();
	} catch (Problem $error) {
		$assert($name, in_array($error->status, $statuses, true));
		return;
	}
	$assert($name, false);
};
$mode = $argv[1] ?? 'test';
$uid = 'faden-workflow-lab';
Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
$home = Server::get(IRootFolder::class)->getUserFolder($uid);
$principal = Server::get(CloudIdentityVerifier::class)->verify('synthetic-principal-token-aaaa');
$broker = Server::get(FileService::class);
$broker->bindCloudPrincipal($principal);
$identity = $broker->pairedHistoryIdentity($principal);
$journal = Server::get(JournalService::class);
$store = Server::get(SqlJournalStore::class);
$planner = Server::get(FilesHistoryPlanner::class);
$executor = Server::get(FilesHistoryExecutor::class);
$before = "Original native revision fixture.\n";
$after = "Agent generated revision fixture.\n";
$markerPath = '/tmp/faden-revalidation.json';
$revision = static fn ($file) => $file->getStorage()->getETag($file->getInternalPath());
$drift = static function ($file) use ($revision): void {
	$old = $revision($file);
	$file->getStorage()->touch($file->getInternalPath(), time() + random_int(30, 100000));
	if ($revision($file) === $old) {
		throw new RuntimeException('Native revision did not change');
	}
};
$folder = null;
$seed = static function (string $name, string $state = 'applied', bool $private = true, ?string $content = null, ?int $ttl = null) use (&$folder, $home, $before, $after, $broker, $journal, $identity, $executor, $planner, $revision): array {
	$file = $folder->newFile($name, $before);
	$forward = $content ?? $after;
	$entry = $journal->prepare($identity, new JournalResource('files', 'parent:' . $folder->getId() . '/name:' . $name,
		'file:' . $file->getId(), $private ? JournalResource::PRIVATE_TEXT_RECOVERY : null), 'nc_update_text_file',
		['operation' => 'update', 'filename' => $name, 'content' => $forward], ['operation' => 'update', 'content' => $before],
		['fileId' => $file->getId(), 'parentId' => $folder->getId(), 'parentPath' => $home->getRelativePath($folder->getPath()),
			'beforeHash' => hash('sha256', $before), 'afterHash' => hash('sha256', $forward)], $ttl);
	$journal->markDispatched($identity, $entry['id'], $entry['ticket']);
	$file->putContent($forward);
	$journal->finish($identity, $entry['id'], $entry['ticket'], $state === 'unknown' ? 'unknown' : 'verified', $state === 'unknown' ? null : new VerifiedReadback($revision($file)));
	if ($state === 'undone') {
		$executor->execute($identity, $planner->prepare($identity, $entry['id'], 'undo'));
	}
	return [$file, $entry['id']];
};

if (in_array($mode, ['seed', 'seed-ui'], true)) {
	$folder = $home->newFolder('Restored revision ' . bin2hex(random_bytes(5)));
	$marker = ['folder' => $folder->getName(), 'folderId' => $folder->getId(), 'scope' => $identity->scopeHash, 'items' => []];
	foreach (['applied', 'undone'] as $state) {
		$file = $folder->newFile($state . '.md', $before);
		$selected = $broker->select($file->getId());
		$read = $broker->read($selected['id']);
		$proposal = $broker->propose($selected['id'], 'update', '', $after, $read['file']['etag']);
		$broker->approve($proposal['id']);
		$result = $broker->commit($proposal['id']);
		$id = $result['actionId'];
		if ($state === 'undone') {
			$executor->execute($identity, $planner->prepare($identity, $id, 'undo'));
		}
		$row = $store->get($id);
		$marker['items'][$state] = ['actionId' => $id, 'fileId' => $file->getId(), 'name' => $file->getName(), 'revision' => $revision($file), 'version' => $row['version'], 'sequence' => $row['resource_seq'], 'direction' => $row['direction']];
		if ($mode === 'seed-ui') {
			$drift($file);
		}
	}
	file_put_contents($markerPath, json_encode($marker, JSON_THROW_ON_ERROR));
	echo json_encode(['marker' => $marker, 'paired_broker_seed' => true], JSON_THROW_ON_ERROR);
	exit;
}

$verifier = Server::get(FilesRevisionRevalidator::class);
if (in_array($mode, ['race', 'hold-write', 'probe', 'status'], true)) {
	$worker = json_decode(file_get_contents('/tmp/faden-revalidation-workers.json'), true, 16, JSON_THROW_ON_ERROR);
	if ($mode === 'status') {
		$entry = $journal->describe($identity, $worker['actionId']);
		echo json_encode(['state' => $entry['state'], 'version' => $entry['version'], 'revalidations' => count(array_filter($journal->attempts($identity, $worker['actionId']), static fn ($row) => $row['outcome'] === 'revalidated'))]);
	} elseif ($mode === 'hold-write') {
		$stream = $home->getById($worker['fileId'])[0]->fopen('r+');
		if (!is_resource($stream)) {
			exit(3);
		}
		file_put_contents('/tmp/faden-revalidation-writer-ready', 'ready');
		for ($i = 0; $i < 200 && !file_exists('/tmp/faden-revalidation-writer-release'); $i++) {
			usleep(100000);
		}
		fclose($stream);
	} elseif ($mode === 'probe') {
		$denied('active native write excludes revision inspection', fn () => $verifier->prepare($identity, $worker['actionId']), [403, 423]);
		echo json_encode(['checks' => $checks]);
	} else {
		$plan = $verifier->prepare($identity, $worker['actionId']);
		file_put_contents('/tmp/faden-revalidation-race-ready-' . getmypid(), 'ready');
		for ($i = 0; $i < 200 && !file_exists('/tmp/faden-revalidation-race-release'); $i++) {
			usleep(100000);
		}
		try {
			$verifier->execute($identity, $plan);
			echo json_encode(['outcome' => 'revalidated']);
		} catch (Problem $error) {
			echo json_encode(['outcome' => 'refused', 'status' => $error->status]);
		}
	}
	exit;
}

if ($mode === 'restore') {
	$marker = json_decode(file_get_contents($markerPath), true, 16, JSON_THROW_ON_ERROR);
	$assert('ordinary app upgrade preserves the paired journal namespace', $identity->scopeHash === $marker['scope']);
	foreach ($marker['items'] as $state => $item) {
		$file = $home->getById($item['fileId'])[0];
		$bytes = $file->getContent();
		$current = $revision($file);
		$assert($state . ': copied volume changes native storage revision with exact bytes', $current !== $item['revision'] && $bytes === ($state === 'applied' ? $after : $before));
		$denied($state . ': ordinary reversal refuses the old storage revision', fn () => $planner->prepare($identity, $item['actionId'], $state === 'applied' ? 'undo' : 'redo'));
		$plan = $verifier->prepare($identity, $item['actionId']);
		$result = $verifier->execute($identity, $plan);
		$row = $store->get($item['actionId']);
		$assert($state . ': verification changes no bytes or native revision', $file->getContent() === $bytes && $revision($file) === $current && $result['revalidationChangedFile'] === false);
		$assert($state . ': settled state, original direction and resource sequence preserved', $row['state'] === $state && $row['direction'] === $item['direction'] && $row['resource_seq'] === $item['sequence'] && $row['version'] === $item['version'] + 1);
		$attempts = $journal->attempts($identity, $item['actionId']);
		$last = end($attempts);
		$assert($state . ': distinct authenticated receipt records revision verification', $last['outcome'] === 'revalidated' && $last['reason'] === 'storage_revision');
		$denied($state . ': old verification plan cannot execute again', fn () => $verifier->execute($identity, $plan));
		$executor->execute($identity, $planner->prepare($identity, $item['actionId'], $state === 'applied' ? 'undo' : 'redo'));
		$assert($state . ': fresh reversal works after revision verification', $file->getContent() === ($state === 'applied' ? $before : $after));
	}
} else {
	$folder = $home->newFolder('Revision guards ' . bin2hex(random_bytes(5)));
	foreach (['applied', 'undone'] as $state) {
		[$file, $id] = $seed($state . '.md', $state);
		$oldPlan = $planner->prepare($identity, $id, $state === 'applied' ? 'undo' : 'redo');
		$denied($state . ': unchanged revision needs no revalidation', fn () => $verifier->prepare($identity, $id));
		$drift($file);
		$plan = $verifier->prepare($identity, $id);
		$assert($state . ': plan binds recorded and observed revisions and exact settled bytes', $plan->recordedRevision !== $plan->expectedEtag && $plan->settledState === $state && $plan->before === ($state === 'applied' ? $after : $before));
		$drift($file);
		$denied($state . ': native drift invalidates an approved observation', fn () => $verifier->execute($identity, $plan));
		$verifier->execute($identity, $verifier->prepare($identity, $id));
		$denied($state . ': old reversal plan invalidated by new version', fn () => $executor->execute($identity, $oldPlan));
	}
	foreach (['other-recipe', 'partial', 'equal', 'unknown', 'legacy', 'renamed', 'missing', 'shared'] as $kind) {
		[$file, $id] = $seed($kind . '.md', $kind === 'unknown' ? 'unknown' : 'applied', $kind !== 'legacy', $kind === 'equal' ? $before : null);
		$drift($file);
		$original = $store->get($id);
		if ($kind === 'other-recipe' || $kind === 'partial') {
			$file->putContent($kind === 'other-recipe' ? $before : 'Later partial content');
		} elseif ($kind === 'renamed') {
			$file->move($folder->getPath() . '/renamed-by-user.md');
		} elseif ($kind === 'missing') {
			$file->delete();
		} elseif ($kind === 'shared') {
			$manager = Server::get(IManager::class);
			$share = $manager->newShare()->setNode($file)->setShareType(0)->setSharedWith('pilot-bob')->setSharedBy($uid)->setShareOwner($uid)->setPermissions(1);
			$manager->createShare($share);
		}
		$denied($kind . ': unsupported or unproven state refuses verification', fn () => $verifier->prepare($identity, $id), [403, 409, 422]);
		$assert($kind . ': refused verification leaves authenticated journal untouched', $store->get($id) === $original);
	}
	[$file, $id] = $seed('dependent.md');
	$drift($file);
	$plan = $verifier->prepare($identity, $id);
	$busy = $journal->prepare($identity, new JournalResource('files', 'parent:' . $folder->getId() . '/name:dependent.md', 'file:' . $file->getId(), JournalResource::PRIVATE_TEXT_RECOVERY), 'nc_update_text_file', ['x' => 1], ['x' => 0], []);
	$denied('a coordinated busy alias blocks verification under owner lock', fn () => $verifier->execute($identity, $plan));
	$journal->abort($identity, $busy['id'], $busy['ticket']);
	$assert('failed dependency check rolls back claim and revision', $journal->preview($identity, $id)['snapshot']['revision'] === $plan->recordedRevision);
	[$file, $id] = $seed('quota.md');
	$drift($file);
	$plan = $verifier->prepare($identity, $id);
	$original = $store->get($id);
	$tiny = new JournalService($store, Server::get(JournalCipher::class), Server::get(AccessResolverRegistry::class), null, new JournalPolicy(maxPayloadBytes: 1));
	$denied('payload bound prevents revision replacement atomically', fn () => $tiny->refreshRevision($identity, $id, $plan->version, $plan->recordedRevision, new VerifiedReadback($plan->expectedEtag)), [413]);
	$tiny = new JournalService($store, Server::get(JournalCipher::class), Server::get(AccessResolverRegistry::class), null, new JournalPolicy(maxUserBytes: 1));
	$denied('owner byte quota prevents a larger revision payload atomically', fn () => $tiny->refreshRevision($identity, $id, $plan->version, $plan->recordedRevision, new VerifiedReadback(str_repeat('r', 128))), [507]);
	$assert('quota refusal preserves the encrypted payload and version', $store->get($id) === $original);
	[$file, $id] = $seed('expired.md', ttl: 30);
	$drift($file);
	$late = new JournalService($store, Server::get(JournalCipher::class), Server::get(AccessResolverRegistry::class), new RevalidationTime());
	$late->expire($identity);
	$denied('expired snapshot cannot be reconstructed for verification', fn () => $verifier->prepare($identity, $id));
	[$file, $id] = $seed('workers.md');
	$drift($file);
	$worker = ['actionId' => $id, 'fileId' => $file->getId(), 'version' => $journal->describe($identity, $id)['version']];
	file_put_contents('/tmp/faden-revalidation-workers.json', json_encode($worker, JSON_THROW_ON_ERROR));
}
echo json_encode(['checks' => $checks, 'workers' => $worker ?? null, 'database' => 'PostgreSQL 16.15'], JSON_THROW_ON_ERROR);
