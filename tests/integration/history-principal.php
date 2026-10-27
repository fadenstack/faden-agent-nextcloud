<?php

declare(strict_types=1);

// Requires an owned clone, synthetic loopback issuer, and the previous package
// for the seed phase. This is not a public endpoint or a cloud login simulator.
if (getenv('FADEN_DISPOSABLE_TEST') !== 'principal-native-20261027') {
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
use OCA\FadenstackAgent\History\JournalException;
use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalPolicy;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\SqlJournalStore;
use OCA\FadenstackAgent\History\VerifiedReadback;
use OCA\FadenstackAgent\Identity\CloudIdentityVerifier;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

$checks = [];
$assert = static function (string $name, bool $condition) use (&$checks): void {
	$checks[] = ['case' => $name, 'pass' => $condition];
	if (!$condition) {
		throw new RuntimeException('Failed native principal check');
	}
};
$denied = static function (string $name, callable $work, string $reason) use ($assert): void {
	try {
		$work();
	} catch (JournalException $error) {
		$assert($name, $error->reason === $reason);
		return;
	}
	$assert($name, false);
};
$mode = static function (string $value): void {
	file_put_contents('/tmp/faden-principal-mode', $value, LOCK_EX);
};
$mode('normal');
$session = Server::get(IUserSession::class);
$user = Server::get(IUserManager::class)->get('faden-workflow-lab');
$session->setUser($user);
$home = Server::get(IRootFolder::class)->getUserFolder($user->getUID());
$db = Server::get(IDBConnection::class);
$store = Server::get(SqlJournalStore::class);
$journal = Server::get(JournalService::class);
$verifier = Server::get(CloudIdentityVerifier::class);
$nativeScope = hash('sha256', 'principal-native-fixture-scope');
$binding = 'principal-native-fixture-session';
$identity = static fn (string $actor): JournalIdentity => JournalIdentity::forPrincipal($user->getUID(), $nativeScope, $binding,
	$verifier->verify('synthetic-principal-token-' . ($actor === 'a' ? 'aaaa' : 'bbbb')));
$a = $identity('a');
$b = $identity('b');
$assert('native HTTP verification produces distinct private cloud namespaces with shared native owner', $a->scopeHash !== $b->scopeHash && $a->ownerHash === $b->ownerHash);
$resource = static fn ($file): JournalResource => new JournalResource('files', 'parent:' . $file->getParent()->getId() . '/name:' . $file->getName(), 'file:' . $file->getId());
$apply = static function (JournalIdentity $who, $file, string $after) use ($journal, $resource, $home): array {
	$before = $file->getContent();
	$entry = $journal->prepare($who, $resource($file), 'nc_update_text_file',
		['operation' => 'update', 'filename' => $file->getName(), 'content' => $after], ['operation' => 'update', 'content' => $before],
		['fileId' => $file->getId(), 'parentId' => $file->getParent()->getId(), 'parentPath' => $home->getRelativePath($file->getParent()->getPath()),
			'beforeHash' => hash('sha256', $before), 'afterHash' => hash('sha256', $after)]);
	$journal->markDispatched($who, $entry['id'], $entry['ticket']);
	$file->putContent($after); // Explicit seed/apply fixture, not an agent-approved forward call.
	return $journal->finish($who, $entry['id'], $entry['ticket'], 'verified', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
};
$fingerprints = static function () use ($store, $a): array {
	$result = [];
	foreach ($store->owned($a->ownerHash) as $row) {
		ksort($row);
		$result[$row['action_id']] = hash('sha256', serialize($row));
	}
	ksort($result);
	return $result;
};
$owner = static function () use ($db, $a): array {
	$query = $db->executeQuery('SELECT * FROM *PREFIX*fdn_jr_owners WHERE owner_hash = ?', [$a->ownerHash]);
	try {
		return $query->fetch();
	} finally {
		$query->closeCursor();
	}
};
$manifest = '/tmp/faden-principal-state.json';
if (getenv('FADEN_PRINCIPAL_PHASE') === 'seed') {
	$folder = $home->newFolder('Principal migration ' . bin2hex(random_bytes(5)));
	$file = $folder->newFile('legacy-overlap.md', 'Original');
	$first = $apply($a, $file, 'First cloud actor');
	$second = $apply($b, $file, 'Second cloud actor');
	$assert('previous package permits independent cloud scopes to apply on the same native file', $first['state'] === 'applied' && $second['state'] === 'applied');
	$unknown = $folder->newFile('legacy-unknown.md', 'Unchanged unknown fixture');
	$legacy = new JournalIdentity($user->getUID(), $nativeScope, $binding);
	$pending = $journal->prepare($legacy, $resource($unknown), 'nc_update_text_file', [], [], []);
	$journal->markDispatched($legacy, $pending['id'], $pending['ticket']);
	$journal->abort($legacy, $pending['id'], $pending['ticket']);
	$assert('old native-only unresolved action is retained', $store->get($pending['id'])['state'] === 'needs_reconciliation');
	file_put_contents($manifest, json_encode(['folderId' => $folder->getId(), 'first' => $first['id'], 'second' => $second['id'],
		'unknown' => $pending['id'], 'fingerprints' => $fingerprints(), 'owner' => $owner()], JSON_THROW_ON_ERROR));
} else {
	$seed = json_decode(file_get_contents($manifest), true, 16, JSON_THROW_ON_ERROR);
	$assert('normal app migration leaves every old signed row and encrypted payload byte identical', $fingerprints() === $seed['fingerprints']);
	$current = $owner();
	$assert('normal app migration preserves aggregate byte and entry quotas', $current['used_bytes'] === $seed['owner']['used_bytes'] && $current['entries'] === $seed['owner']['entries']);
	$maximum = max(array_column($store->owned($a->ownerHash), 'resource_seq'));
	$assert('normal app migration starts the owner generation above retained legacy generations', (int)$current['action_seq'] >= $maximum);
	$folder = $home->getById($seed['folderId'])[0];
	$old = $folder->get('legacy-overlap.md');
	$unknown = $folder->get('legacy-unknown.md');
	$assert('old cloud snapshots remain decryptable by their own actor', $journal->preview($a, $seed['first'])['snapshot']['forward']['content'] === 'First cloud actor');
	$visible = array_column($journal->list($b), 'id');
	$assert('cloud B list does not disclose cloud A or legacy native-only entries', in_array($seed['second'], $visible, true)
		&& !in_array($seed['first'], $visible, true) && !in_array($seed['unknown'], $visible, true));
	foreach (['preview', 'attempts'] as $method) {
		$denied('cloud B cannot ' . $method . ' cloud A action', fn () => $journal->$method($b, $seed['first']), 'action_unavailable');
	}
	$denied('cloud B cannot claim cloud A action', fn () => $journal->claim($b, $seed['first'], 'undo', $store->get($seed['first'])['version']), 'action_unavailable');
	$denied('a retained native-only unknown effect blocks a new cloud scope', fn () => $journal->prepare($b, $resource($unknown), 'nc_update_text_file', [], [], []), 'resource_busy_or_quota');
	$denied('legacy applied actions in another cloud scope block unsafe older Undo', fn () => $journal->claim($a, $seed['first'], 'undo', $store->get($seed['first'])['version']), 'dependent_action');

	$file = $folder->newFile('paired-roundtrip.md', "Before: ä\n");
	$first = $apply($a, $file, "After: 日本語\n");
	$planner = Server::get(FilesHistoryPlanner::class);
	$executor = Server::get(FilesHistoryExecutor::class);
	$undo = $planner->prepare($a, $first['id'], 'undo');
	$result = $executor->execute($a, $undo);
	$assert('paired cloud identity executes real typed Undo with exact native readback', $result['state'] === 'undone' && $file->getContent() === "Before: ä\n");
	$result = $executor->execute($a, $planner->prepare($a, $first['id'], 'redo'));
	$assert('paired cloud identity executes real typed Redo with exact native readback', $result['state'] === 'applied' && $file->getContent() === "After: 日本語\n");
	$claim = $journal->claim($a, $first['id'], 'undo', $result['version']);
	$alias = new JournalResource('files', 'file:' . $file->getId(), 'file:' . $file->getId());
	$denied('another cloud scope cannot bypass an active claim using a file-ID alias', fn () => $journal->prepare($b, $alias, 'nc_update_text_file', [], [], []), 'resource_busy_or_quota');
	$journal->abort($a, $first['id'], $claim['ticket']);
	$second = $apply($b, $file, 'Second actor after released claim');
	$assert('new cloud scopes share monotonic native-owner generations', $store->get($second['id'])['resource_seq'] > $store->get($first['id'])['resource_seq']);
	$denied('a later cloud B action blocks cloud A Undo even across private namespaces', fn () => $journal->claim($a, $first['id'], 'undo', $journal->preview($a, $first['id'])['version']), 'dependent_action');
	$executor->execute($b, $planner->prepare($b, $second['id'], 'undo'));
	$third = $apply($a, $file, 'New branch by cloud A');
	$assert('a verified new action invalidates another cloud scope Redo and purges its snapshot', $journal->preview($a, $third['id'])['state'] === 'applied'
		&& $store->get($second['id'])['state'] === 'redo_invalidated' && $store->get($second['id'])['payload'] === null);
	$denied('cloud B cannot decrypt its invalidated Redo snapshot', fn () => $journal->preview($b, $second['id']), 'expired');
	$denied('cloud A still cannot disclose cloud B invalidated action metadata', fn () => $journal->attempts($a, $second['id']), 'action_unavailable');

	$quota = new JournalService($store, Server::get(\OCA\FadenstackAgent\History\JournalCipher::class), Server::get(\OCA\FadenstackAgent\History\AccessResolverRegistry::class),
		policy: new JournalPolicy(maxResourceEntries: 2));
	$denied('per-resource retention quota counts private cloud scopes together', fn () => $quota->prepare($a, $resource($old), 'nc_update_text_file', [], [], []), 'resource_busy_or_quota');
	$assert('different creation names in one parent are independent resources', !((new JournalResource('files', 'parent:1/name:a', 'folder:1'))->conflictsWith(new JournalResource('files', 'parent:1/name:b', 'folder:1'))));

	$pendingFile = $folder->newFile('device-drift.md', 'Original');
	$pending = $journal->prepare($a, $resource($pendingFile), 'nc_update_text_file', [], [], []);
	foreach (['new-device', 'new-profile'] as $change) {
		$mode($change);
		$changed = $identity('a');
		$assert($change . ' keeps private durable history but changes the attempt binding', $changed->scopeHash === $a->scopeHash && $changed->sessionHash !== $a->sessionHash);
		$denied($change . ' cannot dispatch an old attempt ticket', fn () => $journal->markDispatched($changed, $pending['id'], $pending['ticket']), 'claim_conflict');
	}
	$mode('normal');
	$journal->abort($a, $pending['id'], $pending['ticket']);
	$available = $journal->prepare($b, $resource($pendingFile), 'nc_update_text_file', [], [], []);
	$assert('Stop before dispatch releases coordination for another cloud scope', $available['state'] === 'prepared');
	$journal->abort($b, $available['id'], $available['ticket']);
	$policy = Server::get(\OCP\IConfig::class);
	$original = $policy->getAppValue('fadenstack_agent', ScopePolicy::CONFIG_KEY, '');
	try {
		$policy->setAppValue('fadenstack_agent', ScopePolicy::CONFIG_KEY, json_encode(['revision' => 'revoked-principal-fixture',
			'sources' => [], 'destinations' => [], 'tools' => [], 'classificationDefault' => 'standard', 'sourceClasses' => []], JSON_THROW_ON_ERROR));
		$denied('native scope revocation prevents saved cloud snapshot decryption', fn () => $journal->preview($a, $third['id']), 'permission_lost');
	} finally {
		$policy->setAppValue('fadenstack_agent', ScopePolicy::CONFIG_KEY, $original);
	}

	foreach (['revoked' => 401, 'forbidden' => 403, 'redirect' => 503, 'malformed' => 503, 'oversized' => 503, 'wrong-agent' => 409, 'missing-device' => 503, 'slow' => 503] as $change => $expected) {
		$mode($change);
		$start = microtime(true);
		try {
			$identity('a');
			$assert('native HTTP rejects ' . $change, false);
		} catch (Problem $error) {
			$assert('native HTTP rejects ' . $change . ' without exposing the token', $error->status === $expected && !str_contains($error->getMessage(), 'synthetic-principal-token'));
		}
		if ($change === 'slow') {
			$assert('native HTTP slow issuer is bounded by the configured request timeout', microtime(true) - $start < 6.5);
		}
	}
	$mode('normal');
	$log = array_map(static fn ($line) => json_decode($line, true, 8, JSON_THROW_ON_ERROR), file('/tmp/faden-principal-http.jsonl', FILE_IGNORE_NEW_LINES));
	$assert('native HTTP never follows the redirect or forwards Nextcloud cookies', count(array_filter($log, static fn ($row) => $row['hasCookie'] || $row['path'] !== '/v1/agents/redesign-smoke/profile')) === 0);
}
echo json_encode(['layer' => 'local Nextcloud 35.0.2 native database, Files, ICrypto and HTTP client', 'phase' => getenv('FADEN_PRINCIPAL_PHASE'),
	'issuer' => 'synthetic loopback fixture; real gateway auth tested separately', 'public_history_enabled' => false, 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
