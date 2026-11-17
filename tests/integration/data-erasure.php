<?php

declare(strict_types=1);

/*
 * Release plan 8 Dec, N1: a deleted account's journal rows and tickets go with it; disabling or removing the app
 * erases the journal, the tickets and the users' engine credentials, and keeps the organisation's settings.
 *
 * Disposable fixture only. Run as www-data in the dev container, in this order:
 *   php data-erasure.php user        (seeds two accounts, deletes one through Nextcloud)
 *   occ app:disable fadenstack_agent
 *   php data-erasure.php disabled    (everything personal is gone; the settings stay)
 *   occ app:enable fadenstack_agent
 *   php data-erasure.php enabled     (the journal works again; removes the second account)
 */
if (getenv('FADEN_DISPOSABLE_TEST') !== 'erasure-20261117') {
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
// Nextcloud does not autoload a disabled app.
require_once __DIR__ . '/../../vendor/autoload.php';

use OCA\FadenstackAgent\History\AccessResolverRegistry;
use OCA\FadenstackAgent\History\FilesAccessResolver;
use OCA\FadenstackAgent\History\JournalCipher;
use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\SqlJournalStore;
use OCA\FadenstackAgent\Providers\StructuredTickets;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

$checks = [];
set_exception_handler(static function (Throwable $error) use (&$checks): never {
	fwrite(STDERR, json_encode(['error' => $error::class, 'message' => $error->getMessage(), 'line' => $error->getLine(),
		'file' => basename($error->getFile()), 'checks' => $checks], JSON_THROW_ON_ERROR) . "\n");
	exit(1);
});
$assert = static function (string $case, bool $pass) use (&$checks): void {
	$checks[] = compact('case', 'pass');
	if (!$pass) {
		throw new RuntimeException('Erasure acceptance failed: ' . $case);
	}
};

$db = Server::get(IDBConnection::class);
$config = Server::get(IConfig::class);
$users = Server::get(IUserManager::class);
$session = Server::get(IUserSession::class);
$root = Server::get(IRootFolder::class);
$store = new SqlJournalStore($db);
$tickets = Server::get(StructuredTickets::class);
$registry = new AccessResolverRegistry();
$registry->register('files', new FilesAccessResolver($root, $session, new ScopePolicy($config, $root, $session, Server::get(\OCP\Share\IManager::class))));
$journal = new JournalService($store, Server::get(JournalCipher::class), $registry);

$count = static function (string $table, ?string $owner = null) use ($db): int {
	$sql = 'SELECT COUNT(*) AS n FROM *PREFIX*' . $table . ($owner === null ? '' : ' WHERE owner_hash = ?');
	$result = $db->executeQuery($sql, $owner === null ? [] : [$owner]);
	$n = (int)$result->fetchOne();
	$result->closeCursor();
	return $n;
};
$idsOf = static function (string $owner) use ($db): array {
	$result = $db->executeQuery('SELECT action_id FROM *PREFIX*fdn_jr_alloc WHERE owner_hash = ?', [$owner]);
	$ids = $result->fetchAll(\PDO::FETCH_COLUMN);
	$result->closeCursor();
	return $ids;
};
$receipts = static function (array $ids) use ($db): int {
	$n = 0;
	foreach ($ids as $id) {
		$result = $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*fdn_jr_attempts WHERE action_id = ?', [$id]);
		$n += (int)$result->fetchOne();
		$result->closeCursor();
	}
	return $n;
};
$seed = static function (string $uid, int $actions) use ($users, $session, $root, $journal, $tickets, $config): JournalIdentity {
	$user = $users->get($uid) ?? $users->createUser($uid, bin2hex(random_bytes(16)));
	$session->setUser($user);
	$home = $root->getUserFolder($uid);
	$identity = new JournalIdentity($uid, hash('sha256', 'erasure-scope'), str_repeat('e', 64));
	for ($i = 0; $i < $actions; $i++) {
		$file = $home->newFile('erasure-' . bin2hex(random_bytes(4)) . '.md', 'before ' . $i);
		$journal->prepare($identity, new JournalResource('files', 'file:' . $file->getId(), 'file:' . $file->getId()),
			'nc_update_text_file', ['content' => 'after ' . $i], ['content' => 'before ' . $i], []);
	}
	$tickets->start($identity, bin2hex(random_bytes(24)));
	$config->setUserValue($uid, 'fadenstack_agent', 'engine_connection_erasure', 'sealed-test-value');
	return $identity;
};

$mode = $argv[1] ?? '';
$marker = '/tmp/faden-erasure-marker.json';
[$a, $b] = ['faden-erasure-a', 'faden-erasure-b'];
[$ownerA, $ownerB] = [JournalIdentity::ownerHashOf($a), JournalIdentity::ownerHashOf($b)];

if ($mode === 'user') {
	$seed($a, 3);
	$seed($b, 2);
	$store->atomic(fn () => $store->deny($ownerA, 'erasure_test', time()));
	$assert('seeded: A has journal, quota, claims, tickets and a pause', $count('fdn_journal', $ownerA) === 3 && $count('fdn_jr_alloc', $ownerA) === 3
		&& $count('fdn_jr_owners', $ownerA) === 1 && $count('fdn_jr_resources', $ownerA) === 3 && $count('fdn_provider_tickets', $ownerA) === 1
		&& $count('fdn_jr_barriers', $ownerA) === 1);
	$assert('seeded: B has journal and tickets', $count('fdn_journal', $ownerB) === 2 && $count('fdn_provider_tickets', $ownerB) === 1);

	[$idsA, $idsB] = [$idsOf($ownerA), $idsOf($ownerB)];
	$assert('seeded: every action has its prepared receipt', $receipts($idsA) === 3 && $receipts($idsB) === 2);

	$session->setUser(null);
	$assert('Nextcloud deletes account A', $users->get($a)->delete());

	foreach (['fdn_journal', 'fdn_jr_alloc', 'fdn_jr_owners', 'fdn_jr_resources', 'fdn_jr_barriers', 'fdn_provider_tickets'] as $table) {
		$assert('deleted account: no rows left in ' . $table, $count($table, $ownerA) === 0);
	}
	$assert('deleted account: no receipts left', $receipts($idsA) === 0);
	$assert('deleted account: its engine credential went with its preferences', $config->getUserValue($a, 'fadenstack_agent', 'engine_connection_erasure', '') === '');
	$assert('other account: journal, quota and tickets untouched', $count('fdn_journal', $ownerB) === 2 && $count('fdn_jr_alloc', $ownerB) === 2
		&& $count('fdn_jr_owners', $ownerB) === 1 && $count('fdn_provider_tickets', $ownerB) === 1 && $receipts($idsB) === 2);
	$assert('other account: engine credential untouched', $config->getUserValue($b, 'fadenstack_agent', 'engine_connection_erasure', '') === 'sealed-test-value');

	$settings = [];
	foreach (['server_url', 'agent', 'integration_enabled'] as $key) {
		$settings[$key] = $config->getAppValue('fadenstack_agent', $key, '');
	}
	file_put_contents($marker, json_encode(['settings' => $settings], JSON_THROW_ON_ERROR));
} elseif ($mode === 'disabled') {
	$saved = json_decode(file_get_contents($marker), true, 8, JSON_THROW_ON_ERROR);
	foreach (['fdn_journal', 'fdn_jr_alloc', 'fdn_jr_attempts', 'fdn_jr_owners', 'fdn_jr_resources', 'fdn_jr_barriers', 'fdn_provider_tickets'] as $table) {
		$assert('app disabled: ' . $table . ' is empty', $count($table) === 0);
	}
	$assert('app disabled: the safety mutex row stays', $count('fdn_jr_control') >= 1);
	$assert('app disabled: users\' engine credentials erased', $config->getUserValue($b, 'fadenstack_agent', 'engine_connection_erasure', '') === '');
	foreach ($saved['settings'] as $key => $value) {
		$assert('app disabled: organisation setting ' . $key . ' kept', $config->getAppValue('fadenstack_agent', $key, '') === $value);
	}
} elseif ($mode === 'enabled') {
	$seed($b, 1);
	$assert('re-enabled: the journal records again', $count('fdn_journal', $ownerB) === 1 && $count('fdn_jr_owners', $ownerB) === 1);
	$session->setUser(null);
	$users->get($b)?->delete();
	$assert('cleanup: account B and its rows removed', $users->get($b) === null && $count('fdn_journal', $ownerB) === 0);
	unlink($marker);
} else {
	exit(2);
}
echo json_encode(['mode' => $mode, 'passed' => count($checks), 'checks' => array_column($checks, 'case')], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
