<?php

declare(strict_types=1);

// Internal acceptance only, in the exact labelled disposable dev fixture.
if (getenv('FADEN_DISPOSABLE_TEST') !== 'lifecycle-20261028') {
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';

use OCA\FadenstackAgent\BackgroundJob\ExpireJournal;
use OCA\FadenstackAgent\History\AccessResolverRegistry;
use OCA\FadenstackAgent\History\FilesHistoryExecutor;
use OCA\FadenstackAgent\History\FilesHistoryPlanner;
use OCA\FadenstackAgent\History\JournalCipher;
use OCA\FadenstackAgent\History\JournalException;
use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalPolicy;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\SqlJournalStore;
use OCA\FadenstackAgent\History\VerifiedReadback;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

final class LifecycleTime implements ITimeFactory {
	public function __construct(
		public int $value,
	) {
	}
	public function getTime(): int {
		return $this->value;
	}
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable('@' . $this->value);
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
	fwrite(STDERR, json_encode(['error' => $error::class, 'line' => $error->getLine(), 'checks' => $checks], JSON_THROW_ON_ERROR));
	exit(1);
});
$assert = static function (string $name, bool $value) use (&$checks): void {
	$checks[] = ['case' => $name, 'pass' => $value];
	if (!$value) {
		throw new RuntimeException('Lifecycle assertion failed');
	}
};
$denied = static function (string $name, callable $work, array $reasons) use ($assert): void {
	try {
		$work();
	} catch (JournalException $error) {
		$assert($name, in_array($error->reason, $reasons, true));
		return;
	}
	$assert($name, false);
};
$mode = $argv[1] ?? 'readback';
$markerPath = '/tmp/faden-lifecycle.json';
$session = Server::get(IUserSession::class);
$user = Server::get(IUserManager::class)->get('faden-workflow-lab');
$session->setUser($user);
$home = Server::get(IRootFolder::class)->getUserFolder($user->getUID());
$store = Server::get(SqlJournalStore::class);
$cipher = Server::get(JournalCipher::class);
$clock = new LifecycleTime(time());
$journal = new JournalService($store, $cipher, Server::get(AccessResolverRegistry::class), $clock);
$before = "Original lifecycle content. Grüße 日本語\n";
$after = "Recorded lifecycle content. Grüße 日本語\n";
$partial = 'Unconfirmed partial lifecycle content';

if ($mode === 'seed') {
	$folder = $home->newFolder('Lifecycle ' . bin2hex(random_bytes(5)));
	$marker = ['folder' => $folder->getName(), 'scope' => hash('sha256', $folder->getName()), 'clock' => $clock->value, 'items' => []];
	$identity = new JournalIdentity($user->getUID(), $marker['scope'], 'lifecycle-fixture-session');
	foreach (['restorable', 'unknown', 'unsent', 'expire-unsent', 'expire-sent'] as $kind) {
		$name = $kind . '.md';
		$file = $folder->newFile($name, $before);
		$entry = $journal->prepare($identity, new JournalResource('files', 'parent:' . $folder->getId() . '/name:' . $name,
			'file:' . $file->getId(), JournalResource::PRIVATE_TEXT_RECOVERY), 'nc_update_text_file',
			['operation' => 'update', 'filename' => $name, 'content' => $after], ['operation' => 'update', 'content' => $before],
			['fileId' => $file->getId(), 'parentId' => $folder->getId(), 'parentPath' => $home->getRelativePath($folder->getPath()),
				'beforeHash' => hash('sha256', $before), 'afterHash' => hash('sha256', $after)],
			str_starts_with($kind, 'expire-') ? 1 : null);
		if (in_array($kind, ['restorable', 'unknown', 'expire-sent'], true)) {
			$journal->markDispatched($identity, $entry['id'], $entry['ticket']);
			$file->putContent($kind === 'unknown' ? $partial : $after);
			$entry = $journal->finish($identity, $entry['id'], $entry['ticket'], $kind === 'restorable' ? 'verified' : 'unknown',
				$kind === 'restorable' ? new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())) : null);
		}
		$marker['items'][$kind] = ['id' => $entry['id'], 'fileId' => $file->getId(), 'name' => $name,
			'etag' => $file->getStorage()->getETag($file->getInternalPath()), 'state' => $entry['state']];
	}
	file_put_contents($markerPath, json_encode($marker, JSON_THROW_ON_ERROR));
	echo json_encode(['marker' => $marker, 'payload_exported' => false], JSON_THROW_ON_ERROR);
	exit;
}

$marker = json_decode(file_get_contents($markerPath), true, 16, JSON_THROW_ON_ERROR);
$clock->value = $marker['clock'];
$identity = new JournalIdentity($user->getUID(), $marker['scope'], 'lifecycle-fixture-session');
$item = $marker['items']['restorable'];
$file = $home->getById($item['fileId'])[0];
$quota = static function () use ($identity, $store): array {
	$db = Server::get(IDBConnection::class);
	$one = static function (string $sql) use ($db, $identity): array {
		$result = $db->executeQuery($sql, [$identity->ownerHash]);
		try {
			return $result->fetch();
		} finally {
			$result->closeCursor();
		}
	};
	$owner = $one('SELECT used_bytes, entries FROM *PREFIX*fdn_jr_owners WHERE owner_hash = ?');
	$rows = $store->owned($identity->ownerHash);
	$actual = ['used_bytes' => array_sum(array_map(static fn ($row) => strlen($row['payload'] ?? ''), $rows)), 'entries' => count($rows)];
	return ['stored' => array_map(intval(...), $owner), 'actual' => $actual];
};

if ($mode === 'diagnose-restore') {
	echo json_encode(['bytesMatch' => $file->getContent() === $after, 'expectedRevision' => $item['etag'],
		'storageRevision' => $file->getStorage()->getETag($file->getInternalPath()), 'cachedRevision' => $file->getEtag()], JSON_THROW_ON_ERROR);
	exit;
} elseif ($mode === 'key-check') {
	$denied('missing instance secret refuses authenticated journal metadata', fn () => $journal->preview($identity, $item['id']), ['journal_integrity']);
	$denied('missing instance secret cannot decrypt the snapshot separately', fn () => $cipher->decrypt($store->get($item['id'])), ['snapshot_unavailable']);
} elseif ($mode === 'readback' || $mode === 'restore-readback') {
	$restored = $mode === 'restore-readback';
	$preview = $journal->preview($identity, $item['id']);
	$assert('fresh process recovers exact encrypted forward and inverse recipes', $preview['snapshot']['forward']['content'] === $after && $preview['snapshot']['inverse']['content'] === $before);
	$currentRevision = $file->getStorage()->getETag($file->getInternalPath());
	$assert($restored ? 'copied backup preserves exact bytes but has a new storage revision' : 'native file bytes and revision match the recorded action',
		$file->getContent() === $after && ($restored ? $currentRevision !== $item['etag'] : $currentRevision === $item['etag']));
	$assert('applied record retains its snapshot and journal-level Undo state', $preview['state'] === 'applied' && $preview['journalEligibility'] === 'undo');
	if ($restored) {
		$denied('copied backup cannot use an old revision to overwrite the restored file', fn () => Server::get(FilesHistoryPlanner::class)->prepare($identity, $item['id'], 'undo'), ['native_conflict']);
		$assert('refused restored Undo retains the durable applied action and snapshot', $journal->describe($identity, $item['id'])['state'] === 'applied'
			&& $journal->describe($identity, $item['id'])['snapshotAvailable']);
	}
	foreach (['unknown' => $partial, 'unsent' => $before] as $kind => $content) {
		$row = $marker['items'][$kind];
		$native = $home->getById($row['fileId'])[0];
		$assert($kind . ' survives with exact native bytes and its original durable state', $native->getContent() === $content
			&& $journal->describe($identity, $row['id'])['state'] === $row['state']);
		$assert($kind . ' claim blocks a new write without replaying it', $store->get($row['id'])['payload'] !== null);
		$denied($kind . ' unresolved resource cannot be prepared again', fn () => $journal->prepare($identity,
			new JournalResource('files', 'file:' . $row['fileId'], 'file:' . $row['fileId']), 'nc_update_text_file', [], [], []), ['resource_busy_or_quota']);
	}
	$attempts = $journal->attempts($identity, $item['id']);
	$assert('signed attempt receipts survive restart or backup restore', count($attempts) >= 2);
	$counts = $quota();
	$assert('owner quotas equal persisted payload bytes and metadata rows', $counts['stored'] === $counts['actual']);
	$assert('readback does not rewrite native content or ETag', $file->getStorage()->getETag($file->getInternalPath()) === $currentRevision);
} elseif ($mode === 'roundtrip') {
	$planner = Server::get(FilesHistoryPlanner::class);
	$executor = Server::get(FilesHistoryExecutor::class);
	$result = $executor->execute($identity, $planner->prepare($identity, $item['id'], 'undo'));
	$assert('restored snapshot Undo writes exact original bytes through the native executor', $result['state'] === 'undone' && $file->getContent() === $before);
	$result = $executor->execute($identity, $planner->prepare($identity, $item['id'], 'redo'));
	$assert('restored snapshot Redo writes exact recorded generated bytes', $result['state'] === 'applied' && $file->getContent() === $after);
	$counts = $quota();
	$assert('restored Undo and Redo leave exact quota accounting', $counts['stored'] === $counts['actual']);
	$marker['items']['restorable']['etag'] = $file->getStorage()->getETag($file->getInternalPath());
	file_put_contents($markerPath, json_encode($marker, JSON_THROW_ON_ERROR));
} elseif ($mode === 'expire') {
	$clock = new LifecycleTime($marker['clock'] + 5);
	$maintenance = new JournalService($store, $cipher, Server::get(AccessResolverRegistry::class), $clock);
	$session->setUser(null);
	try {
		(new ReflectionMethod(ExpireJournal::class, 'run'))->invoke(new ExpireJournal($clock, $maintenance), null);
	} finally {
		$session->setUser($user);
	}
	$expired = $store->get($marker['items']['expire-unsent']['id']);
	$assert('inactive-owner maintenance deletes expired encrypted bytes and attempt credentials', $expired['payload'] === null && $expired['payload_bytes'] === 0
		&& $expired['ticket_hash'] === null && $expired['session_hash'] === null && $expired['state'] === 'expired');
	$unknown = $store->get($marker['items']['expire-sent']['id']);
	$assert('expiry deletes unknown snapshot bytes without certifying completion', $unknown['payload'] === null && $unknown['state'] === 'needs_reconciliation');
	$row = $marker['items']['expire-sent'];
	$denied('expired unknown effect still excludes another write', fn () => $journal->prepare($identity,
		new JournalResource('files', 'file:' . $row['fileId'], 'file:' . $row['fileId']), 'nc_update_text_file', [], [], []), ['resource_busy_or_quota']);
	$denied('expired snapshot cannot expose a preview', fn () => $journal->preview($identity, $row['id']), ['expired']);
	$counts = $quota();
	$assert('expiry releases byte quota with exact accounting', $counts['stored'] === $counts['actual']);
	$unsent = $marker['items']['expire-unsent'];
	$next = $journal->prepare($identity, new JournalResource('files', 'file:' . $unsent['fileId'], 'file:' . $unsent['fileId']), 'nc_update_text_file', [], [], []);
	$assert('expired unsent attempt releases its claim for a new proposal', $next['state'] === 'prepared');
	$journal->abort($identity, $next['id'], $next['ticket']);
	$clock->value += 2592001;
	$maintenance->pruneExpired(1000);
	$assert('terminal metadata and receipts have a bounded retention', $store->get($expired['action_id']) === null && $store->attempts($expired['action_id']) === []);
	$assert('unknown-effect metadata survives terminal retention without a guessed resolution', $store->get($unknown['action_id'])['state'] === 'needs_reconciliation');
	$counts = $quota();
	$assert('terminal deletion keeps byte and entry quotas exact', $counts['stored'] === $counts['actual']);
	$assert('maintenance never changes the independently read native file', $file->getContent() === $after && $file->getStorage()->getETag($file->getInternalPath()) === $item['etag']);
} elseif ($mode === 'quota') {
	$folder = $home->get($marker['folder']);
	$native = $folder->newFile('quota-refused.md', $before);
	$resource = new JournalResource('files', 'file:' . $native->getId(), 'file:' . $native->getId());
	$start = $quota();
	$tiny = new JournalService($store, $cipher, Server::get(AccessResolverRegistry::class), $clock, new JournalPolicy(maxPayloadBytes: 10));
	$denied('snapshot too large refuses preparation before dispatch', fn () => $tiny->prepare($identity, $resource, 'nc_update_text_file', ['content' => $after], [], []), ['snapshot_too_large']);
	$tiny = new JournalService($store, $cipher, Server::get(AccessResolverRegistry::class), $clock, new JournalPolicy(maxUserBytes: 1));
	$denied('user quota failure rolls back its transaction', fn () => $tiny->prepare($identity, $resource, 'nc_update_text_file', [], [], []), ['user_quota']);
	$assert('quota refusal preserves existing accounting and native bytes', $quota() === $start && $native->getContent() === $before);
} else {
	throw new RuntimeException('Unknown lifecycle fixture mode');
}

echo json_encode(['layer' => 'native PostgreSQL lifecycle; internal executor only, not cloud-governed UI', 'mode' => $mode, 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
