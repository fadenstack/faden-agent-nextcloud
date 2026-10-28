<?php

declare(strict_types=1);

if (getenv('FADEN_DISPOSABLE_TEST') !== 'lifecycle-20261028') {
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';

use OCA\FadenstackAgent\History\AccessResolverRegistry;
use OCA\FadenstackAgent\History\JournalCipher;
use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\SqlJournalStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

final class MaintenanceRaceTime implements ITimeFactory {
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

$user = Server::get(IUserManager::class)->get('faden-workflow-lab');
Server::get(IUserSession::class)->setUser($user);
$home = Server::get(IRootFolder::class)->getUserFolder($user->getUID());
$store = Server::get(SqlJournalStore::class);
$mode = $argv[1];
$markerPath = '/tmp/faden-maintenance-race.json';
if ($mode === 'setup') {
	$folder = $home->newFolder('Maintenance race ' . bin2hex(random_bytes(5)));
	$marker = ['round' => bin2hex(random_bytes(5)), 'clock' => time(), 'scope' => hash('sha256', $folder->getName()), 'items' => []];
	$identity = new JournalIdentity($user->getUID(), $marker['scope'], 'maintenance-race-session');
	$clock = new MaintenanceRaceTime($marker['clock']);
	$journal = new JournalService($store, Server::get(JournalCipher::class), Server::get(AccessResolverRegistry::class), $clock);
	foreach (['a', 'b'] as $actor) {
		$file = $folder->newFile($actor . '.md', 'Unchanged maintenance fixture');
		$entry = $journal->prepare($identity, new JournalResource('files', 'file:' . $file->getId(), 'file:' . $file->getId()), 'nc_update_text_file', [], [], [], 1);
		$marker['items'][] = ['id' => $entry['id'], 'fileId' => $file->getId(), 'etag' => $file->getStorage()->getETag($file->getInternalPath())];
	}
	file_put_contents($markerPath, json_encode($marker, JSON_THROW_ON_ERROR));
	echo json_encode($marker, JSON_THROW_ON_ERROR);
	exit;
}
$marker = json_decode(file_get_contents($markerPath), true, 16, JSON_THROW_ON_ERROR);
$terminal = str_starts_with($mode, 'terminal');
$clock = new MaintenanceRaceTime($marker['clock'] + ($terminal ? 2592010 : 5));
$journal = new JournalService($store, Server::get(JournalCipher::class), Server::get(AccessResolverRegistry::class), $clock);
if ($mode === 'verify-expire' || $mode === 'verify-terminal') {
	$terminal = $mode === 'verify-terminal';
	$rows = array_map(static fn ($item) => $store->get($item['id']), $marker['items']);
	$valid = true;
	foreach ($marker['items'] as $index => $item) {
		$row = $rows[$index];
		$valid = $valid && ($terminal ? $row === null && $store->attempts($item['id']) === []
			: $row['payload'] === null && $row['state'] === 'expired'
				&& count(array_filter($store->attempts($item['id']), static fn ($attempt) => $attempt['outcome'] === 'expired')) === 1);
		$file = $home->getById($item['fileId'])[0];
		$valid = $valid && $file->getContent() === 'Unchanged maintenance fixture' && $file->getStorage()->getETag($file->getInternalPath()) === $item['etag'];
	}
	$identity = new JournalIdentity($user->getUID(), $marker['scope'], 'maintenance-race-session');
	$ownerRows = $store->owned($identity->ownerHash);
	$result = Server::get(IDBConnection::class)->executeQuery('SELECT used_bytes, entries FROM *PREFIX*fdn_jr_owners WHERE owner_hash = ?', [$identity->ownerHash]);
	$quota = $result->fetch();
	$result->closeCursor();
	$valid = $valid && (int)$quota['used_bytes'] === array_sum(array_map(static fn ($row) => strlen($row['payload'] ?? ''), $ownerRows))
		&& (int)$quota['entries'] === count($ownerRows);
	echo json_encode(['valid' => $valid, 'phase' => $terminal ? 'terminal' : 'expire', 'rows' => count($rows), 'quotaExact' => $valid], JSON_THROW_ON_ERROR);
	exit($valid ? 0 : 1);
}

// Both workers capture the same signed observations before either takes the owner lock.
$observed = array_map(static fn ($item) => $store->get($item['id']), $marker['items']);
$phase = $terminal ? 'terminal' : 'expire';
$barrier = '/tmp/faden-maintenance-' . $marker['round'] . '.' . $phase;
file_put_contents($barrier . '.' . substr($mode, -1), 'ready');
$deadline = microtime(true) + 20;
while (!is_file($barrier . '.go')) {
	if (microtime(true) > $deadline) {
		exit(3);
	}
	usleep(10000);
}
try {
	$count = (new ReflectionMethod(JournalService::class, 'expireRows'))->invoke($journal, $observed);
	echo json_encode(['completed' => true, 'purgedPayloads' => $count], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
	echo json_encode(['completed' => false, 'error' => $error::class], JSON_THROW_ON_ERROR);
	exit(1);
}
