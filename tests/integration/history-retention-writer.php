<?php

declare(strict_types=1);

if (getenv('FADEN_DISPOSABLE_TEST') !== 'writer-20261028') {
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';

use OCA\FadenstackAgent\History\JournalCipher;
use OCA\FadenstackAgent\History\JournalException;
use OCA\FadenstackAgent\History\JournalIdentity;
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

final class WriterTestTime implements ITimeFactory {
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
	fwrite(STDERR, json_encode(['error' => $error::class, 'reason' => $error instanceof JournalException ? $error->reason : null,
		'file' => basename($error->getFile()), 'line' => $error->getLine(), 'checks' => $checks], JSON_THROW_ON_ERROR));
	exit(1);
});
$assert = static function (string $case, bool $pass) use (&$checks): void {
	$checks[] = compact('case', 'pass');
	if (!$pass) {
		throw new RuntimeException('Writer acceptance failed');
	}
};
$denied = static function (string $case, callable $work, string $reason = 'history_safety_pause') use ($assert): void {
	try {
		$work();
	} catch (JournalException $error) {
		$assert($case, $error->reason === $reason);
		return;
	}
	$assert($case, false);
};
$path = '/tmp/faden-writer-marker.json';
$mode = $argv[1];
$marker = is_file($path) ? json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR) : ['time' => time(), 'scope' => hash('sha256', random_bytes(32))];
$clock = new WriterTestTime($marker['time']);
$db = Server::get(IDBConnection::class);
$store = Server::get(SqlJournalStore::class);
$cipher = Server::get(JournalCipher::class);
$journal = new JournalService($store, $cipher, Server::get(\OCA\FadenstackAgent\History\AccessResolverRegistry::class), $clock);
$session = Server::get(IUserSession::class);
$root = Server::get(IRootFolder::class);
$user = static function (string $uid) use ($session): void {
	$manager = Server::get(IUserManager::class);
	$native = $manager->get($uid) ?? $manager->createUser($uid, 'synthetic-writer-fixture-only');
	$session->setUser($native);
};
$identity = static fn (string $uid) => new JournalIdentity($uid, $marker['scope'], str_repeat('w', 64));
$uid = 'faden-workflow-lab';
$user($uid);
$owner = $identity($uid);
$resource = static fn ($file) => new JournalResource('files', 'file:' . $file->getId(), 'file:' . $file->getId());
$save = static function () use (&$marker, $path): void {
	file_put_contents($path, json_encode($marker, JSON_THROW_ON_ERROR));
};
$prepare = static function (string $name, int $ttl = 604800) use (&$marker, $root, $session, $journal, $identity, $resource): array {
	$nativeUid = $session->getUser()->getUID();
	$home = $root->getUserFolder($nativeUid);
	$folder = isset($marker['folders'][$nativeUid]) ? $home->get($marker['folders'][$nativeUid]) : $home->newFolder('Retention writer ' . bin2hex(random_bytes(4)));
	$marker['folders'][$nativeUid] = $folder->getName();
	$file = $folder->newFile($name . '.md', 'native before ' . $name);
	$prepared = $journal->prepare($identity($nativeUid), $resource($file), 'nc_update_text_file', ['content' => 'native after ' . $name], ['content' => $file->getContent()], [], $ttl);
	return ['entry' => $prepared, 'fileId' => $file->getId(), 'uid' => $nativeUid];
};
$fileOf = static fn (array $item) => $root->getUserFolder($item['uid'])->getById($item['fileId'])[0];
$settle = static function (array $item) use ($journal, $identity, $fileOf): array {
	$entry = $item['entry'];
	$journal->markDispatched($identity($item['uid']), $entry['id'], $entry['ticket']);
	$file = $fileOf($item);
	$file->putContent('native after ' . pathinfo($file->getName(), PATHINFO_FILENAME));
	return $journal->finish($identity($item['uid']), $entry['id'], $entry['ticket'], 'verified', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
};
$query = static function (string $sql, array $arguments = []) use ($db): array {
	$result = $db->executeQuery($sql, $arguments);
	try {
		return $result->fetchAll();
	} finally {
		$result->closeCursor();
	}
};
$accounting = static function (string $case) use ($store, $query, $assert): void {
	$totals = [];
	$resources = [];
	$unknown = ['entries' => 0, 'bytes' => 0];
	$after = (new ReflectionMethod($store, 'page'))->getParameters()[0]->allowsNull() ? null : '';
	do {
		$rows = $store->page($after, 25);
		foreach ($rows as $row) {
			$after = $row['action_id'];
			$allocation = $store->allocation($row['action_id']);
			if ($allocation === null) {
				$unknown['entries']++;
				$unknown['bytes'] += strlen($row['payload'] ?? '');
				continue;
			}
			$hash = $allocation['owner_hash'];
			$totals[$hash] ??= ['entries' => 0, 'bytes' => 0];
			$totals[$hash]['entries']++;
			$totals[$hash]['bytes'] += strlen($row['payload'] ?? '');
			$resources[$allocation['resource_hash']] = ($resources[$allocation['resource_hash']] ?? 0) + ($row['payload'] !== null ? 1 : 0);
		}
	} while (count($rows) === 25);
	$exact = true;
	foreach ($query('SELECT owner_hash, used_bytes, entries FROM *PREFIX*fdn_jr_owners') as $row) {
		$expected = $totals[$row['owner_hash']] ?? ['entries' => 0, 'bytes' => 0];
		$exact = $exact && (int)$row['used_bytes'] === $expected['bytes'] && (int)$row['entries'] === $expected['entries'];
	}
	foreach ($query('SELECT resource_hash, entries FROM *PREFIX*fdn_jr_resources') as $row) {
		$exact = $exact && (int)$row['entries'] === ($resources[$row['resource_hash']] ?? 0);
	}
	$stats = method_exists($store, 'retentionStats') ? $store->retentionStats() : null;
	if ($stats !== null) {
		$exact = $exact && $stats['unattributedEntries'] === $unknown['entries'] && $stats['unattributedBytes'] === $unknown['bytes'];
	}
	$assert($case, $exact);
};
if ($mode === 'baseline' || $mode === 'seed') {
	$control = $db->executeQuery('SELECT COUNT(*) AS count FROM *PREFIX*fdn_jr_control');
	$empty = (int)$control->fetch()['count'] === 0;
	$control->closeCursor();
	if ($empty && $mode === 'baseline') {
		$denied('fresh reader schema has no seed rows and first mutation fails', fn () => $store->atomic(static fn () => null), 'journal_owner_unavailable');
		$output = new class implements \OCP\Migration\IOutput {
			public function debug(string $message): void {
			}
			public function info($message) {
			}
			public function warning($message) {
			}
			public function startProgress($max = 0) {
			}
			public function advance($step = 1, $description = '') {
			}
			public function finishProgress() {
			}
		};
		// Establish the same data prerequisite as the previously tested upgrade.
		// This is a fixture-only workaround for exercising immutable reader1.9.
		(new \OCA\FadenstackAgent\Migration\Version000109Date20261028100000($db, $cipher))->postSchemaChange($output, static fn () => null, []);
	}
	foreach (['bad', 'owner', 'resource', 'state', 'bytes', 'time', 'json', 'payload'] as $name) {
		$marker['bad'][$name] = $prepare('bad-' . $name, 1);
	}
	$marker['healthyDue'] = $prepare('healthy-due', 2);
	$marker['unexpired'] = $prepare('unexpired', 3600);
	$marker['metadata'] = $prepare('metadata');
	$journal->abort($owner, $marker['metadata']['entry']['id'], $marker['metadata']['entry']['ticket']);
	$marker['held']['apply'] = $prepare('held-apply');
	foreach (['undo', 'redo'] as $direction) {
		$item = $prepare('held-' . $direction);
		$applied = $settle($item);
		$claim = $journal->claim($owner, $applied['id'], 'undo', $applied['version']);
		if ($direction === 'redo') {
			$journal->markDispatched($owner, $claim['id'], $claim['ticket']);
			$file = $fileOf($item);
			$file->putContent('native before held-redo');
			$undone = $journal->finish($owner, $claim['id'], $claim['ticket'], 'verified', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
			$claim = $journal->claim($owner, $applied['id'], 'redo', $undone['version']);
		}
		$item['entry'] = $claim;
		$marker['held'][$direction] = $item;
	}
	$marker['finish'] = $prepare('finish');
	$journal->markDispatched($owner, $marker['finish']['entry']['id'], $marker['finish']['entry']['ticket']);
	$marker['orphan'] = $prepare('orphan');
	$marker['orphanAllocation'] = $prepare('orphan-allocation');
	$journal->abort($owner, $marker['orphanAllocation']['entry']['id'], $marker['orphanAllocation']['entry']['ticket']);
	$user('writer-settled');
	$marker['settled'] = $prepare('settled', 1);
	$settle($marker['settled']);
	$user('writer-legacy');
	$marker['legacyDue'] = $prepare('legacy-due', 1);
	$marker['legacyFuture'] = $prepare('legacy-future', 3600);
	$user('pilot-bob');
	$marker['bob'] = $prepare('bob-control');
	$settle($marker['bob']);
	$user($uid);
	foreach ($marker['bad'] as $name => $item) {
		$id = $item['entry']['id'];
		$marker['bad'][$name]['original'] = $store->get($id);
		$column = match ($name) {
			'owner' => 'owner_hash', 'resource' => 'resource_hash', 'state' => 'state', 'bytes' => 'payload_bytes', 'time' => 'expires_at', 'json' => 'resource_json', 'payload' => 'payload', default => 'metadata_mac'
		};
		$value = match ($name) {
			'owner' => $identity('pilot-bob')->ownerHash, 'resource' => str_repeat('e', 64), 'state' => 'applied', 'bytes' => 2147483647, 'time' => $clock->value + 9999999, 'json' => '{malformed', 'payload' => 'corrupted ciphertext 日本語', default => 'writer-bad-seal'
		};
		$db->executeStatement('UPDATE *PREFIX*fdn_journal SET ' . $column . ' = ? WHERE action_id = ?', [$value, $id]);
	}
	foreach (['unexpired', 'settled'] as $name) {
		$id = $marker[$name]['entry']['id'];
		$marker[$name]['original'] = $store->get($id);
		$db->executeStatement('UPDATE *PREFIX*fdn_journal SET metadata_mac = ? WHERE action_id = ?', ['writer-' . $name . '-bad-seal', $id]);
		$marker[$name]['damaged'] = $store->get($id);
	}
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET updated_at = ? WHERE action_id = ?', [$clock->value - 2592001, $marker['metadata']['entry']['id']]);
	foreach (['orphan', 'orphanAllocation'] as $name) {
		$db->executeStatement('DELETE FROM *PREFIX*fdn_journal WHERE action_id = ?', [$marker[$name]['entry']['id']]);
	}
	$clock->value += 3;
	if ($mode === 'baseline') {
		$journal->pruneExpired(1000);
		$assert('reader prerequisite does not yet delete unreadable due rows', $store->get($marker['bad']['bad']['entry']['id']) !== null);
		$assert('reader still expires a healthy due snapshot in that scan', $store->get($marker['healthyDue']['entry']['id'])['payload'] === null);
	}
	$assert('unexpired unreadable snapshot remains byte-exact', $store->get($marker['unexpired']['entry']['id']) === $marker['unexpired']['damaged']);
	$assert('native bytes are unchanged by reader maintenance', $fileOf($marker['bad']['bad'])->getContent() === 'native before bad-bad');
	$save();
} elseif ($mode === 'writer') {
	$clock->value += 3;
	$beforeSequence = $query('SELECT action_seq FROM *PREFIX*fdn_jr_owners WHERE owner_hash = ?', [$owner->ownerHash])[0]['action_seq'];
	$bobBefore = $query('SELECT used_bytes, entries FROM *PREFIX*fdn_jr_owners WHERE owner_hash = ?', [$identity('pilot-bob')->ownerHash])[0];
	$count = $journal->pruneExpired(1000);
	$assert('writer deletes expired unreadable data and discovers orphan claims', $count >= count($marker['bad']) + 2);
	foreach ($marker['bad'] as $name => $item) {
		$id = $item['entry']['id'];
		$assert('damaged ' . $name . ' removed without a new signed transition', $store->get($id) === null && $store->attempts($id) === [] && $store->allocation($id) === null);
		$assert('damaged ' . $name . ' never changes native bytes', $fileOf($item)->getContent() === 'native before bad-' . $name);
	}
	$assert('expired unreadable metadata and receipts are removed', $store->get($marker['metadata']['entry']['id']) === null && $store->attempts($marker['metadata']['entry']['id']) === []);
	$assert('unexpired unreadable content stays byte-exact', $store->get($marker['unexpired']['entry']['id']) === $marker['unexpired']['damaged']);
	$assert('owner denial survives removal of all damaged live records', $store->blocked($owner->ownerHash));
	$assert('independent claims are retired after persistent denial', !in_array($marker['bad']['bad']['entry']['id'], array_column($store->activeClaims($owner->ownerHash), 'active_id'), true));
	$assert('orphan native claim is replaced by persistent denial', !in_array($marker['orphan']['entry']['id'], array_column($store->activeClaims($owner->ownerHash), 'active_id'), true) && $store->allocation($marker['orphan']['entry']['id']) !== null);
	$assert('unexpired orphan accounting facts remain content-minimal until their deadline', $store->allocation($marker['orphanAllocation']['entry']['id']) !== null);
	$assert('maintenance preserves monotonic owner sequence generations', $query('SELECT action_seq FROM *PREFIX*fdn_jr_owners WHERE owner_hash = ?', [$owner->ownerHash])[0]['action_seq'] >= $beforeSequence);
	$assert('forged owner header never debits the other native user', $query('SELECT used_bytes, entries FROM *PREFIX*fdn_jr_owners WHERE owner_hash = ?', [$identity('pilot-bob')->ownerHash])[0] === $bobBefore);
	$assert('settled unreadable action is erased without an invented outcome barrier', $store->get($marker['settled']['entry']['id']) === null && !$store->blocked($identity('writer-settled')->ownerHash));
	$accounting('actual bytes, metadata entries and resource charges are exact after writer cleanup');
	$before = $query('SELECT owner_hash, reason, created_at FROM *PREFIX*fdn_jr_barriers ORDER BY owner_hash');
	$journal->pruneExpired(1000);
	$assert('repeated cleanup is idempotent and never renews a denial', $query('SELECT owner_hash, reason, created_at FROM *PREFIX*fdn_jr_barriers ORDER BY owner_hash') === $before);
	$id = $marker['unexpired']['entry']['id'];
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET metadata_mac = ? WHERE action_id = ?', [$marker['unexpired']['original']['metadata_mac'], $id]);
	$assert('restoring the unexpired original seal still opens native encrypted content', $journal->preview($owner, $id)['snapshot']['forward']['content'] === 'native after unexpired');
	$journal->abort($owner, $id, $marker['unexpired']['entry']['ticket']);
	$assert('restoring and safely stopping an action never clears persistent denial', $store->blocked($owner->ownerHash));
	foreach ($marker['held'] as $direction => $item) {
		$denied('writer refuses previously prepared ' . $direction . ' ticket after erasure', fn () => $journal->markDispatched($owner, $item['entry']['id'], $item['entry']['ticket']));
	}
	$user('writer-settled');
	$control = $prepare('post-expiry-control');
	$settle($control);
	$assert('settled-only owner can complete a new native action after unreadable expiry', $fileOf($control)->getContent() === 'native after post-expiry-control');
	$accounting('new healthy producer remains correctly accounted after cleanup');
	$user($uid);
	$stats = $store->retentionStats();
	$assert('attributed cleanup needs no global denial or unattributed charges', $stats['globalPauses'] === 0 && $stats['unattributedEntries'] === 0 && $stats['ownerPauses'] === 1);
	$db->executeStatement('DELETE FROM *PREFIX*fdn_jr_control');
	$journal->pruneExpired(1);
	$assert('fresh-install empty controls initialize through the normal mutation boundary', count($query('SELECT control_name FROM *PREFIX*fdn_jr_control')) >= 4);
	$assert('initialization recovery never removes existing safety denials', $store->blocked($owner->ownerHash));
	$save();
} elseif ($mode === 'rollback') {
	$clock->value += 3;
	foreach ($marker['held'] as $direction => $item) {
		$denied('actual reader rollback still blocks prepared ' . $direction . ' dispatch', fn () => $journal->markDispatched($owner, $item['entry']['id'], $item['entry']['ticket']));
	}
	$other = new JournalIdentity($uid, hash('sha256', 'writer-other-scope'), str_repeat('x', 64));
	$denied('reader rollback blocks new Apply in another scope', fn () => $journal->prepare($other, $resource($fileOf($marker['held']['apply'])), 'nc_update_text_file', [], [], []));
	$item = $marker['finish'];
	$file = $fileOf($item);
	$finished = $journal->finish($owner, $item['entry']['id'], $item['entry']['ticket'], 'unchanged', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
	$assert('old reader can settle a healthy dispatch against new writer counters', $finished['state'] === 'failed' && $store->blocked($owner->ownerHash));
	$apply = $marker['held']['apply']['entry'];
	$assert('old reader can stop an undispatched action while paused', $journal->abort($owner, $apply['id'], $apply['ticket'])['state'] === 'cancelled');
	$user('pilot-bob');
	$item = $prepare('rollback-control');
	$settle($item);
	$assert('old reader native writes still work for an unaffected native user', $fileOf($item)->getContent() === 'native after rollback-control');
	$unknown = $prepare('rollback-unknown', 1);
	$journal->markDispatched($identity('pilot-bob'), $unknown['entry']['id'], $unknown['entry']['ticket']);
	$journal->abort($identity('pilot-bob'), $unknown['entry']['id'], $unknown['entry']['ticket']);
	$marker['unknown'] = $unknown;
	$accounting('old reader settlement, stop and new writes preserve exact physical accounting');
	$save();
} elseif ($mode === 'edge-before' || $mode === 'edge-after') {
	$afterFix = $mode === 'edge-after';
	$suffix = ($afterFix ? 'after' : 'before') . '-' . bin2hex(random_bytes(2));
	$clock->value += 3;
	$user('writer-quota-' . $suffix);
	$future = $prepare('quota-future');
	$due = $prepare('quota-due', 1);
	$pending = $prepare('quota-finish');
	$journal->markDispatched($identity($pending['uid']), $pending['entry']['id'], $pending['entry']['ticket']);
	$original = $store->get($future['entry']['id']);
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET payload = ? WHERE action_id = ?', [str_repeat('x', 20000), $future['entry']['id']]);
	$damaged = $store->get($future['entry']['id']);
	$clock->value += 2;
	$smallPolicy = new \OCA\FadenstackAgent\History\JournalPolicy(maxUserBytes: 10000);
	$limited = new JournalService($store, $cipher, Server::get(\OCA\FadenstackAgent\History\AccessResolverRegistry::class), $clock, $smallPolicy);
	$quotaFailure = false;
	try {
		$limited->pruneExpired(1000);
	} catch (JournalException $error) {
		$quotaFailure = $error->reason === 'user_quota';
	}
	$assert('above-quota expiry regression is reproduced before and removed after the fix', $quotaFailure !== $afterFix);
	$assert('healthy due bytes are retained before and expire after the fix', ($store->get($due['entry']['id'])['payload'] === null) === $afterFix);
	$assert('oversized unexpired damaged content remains byte-exact', $store->get($future['entry']['id']) === $damaged);
	if ($afterFix) {
		$store->atomic(fn () => (new \OCA\FadenstackAgent\History\JournalRetention($store, $cipher))->repairAccounting($identity($pending['uid'])->ownerHash, $clock->value));
		$file = $fileOf($pending);
		// A content-derived native observation exercises the generic revision
		// contract without requiring one storage backend's ETag length.
		$result = $limited->finish($identity($pending['uid']), $pending['entry']['id'], $pending['entry']['ticket'], 'unchanged', new VerifiedReadback(hash('sha256', $file->getContent())));
		$assert('no-change settlement needs no temporary quota increase', $result['state'] === 'failed' && $store->get($pending['entry']['id'])['payload'] === null);
		$current = $store->get($due['entry']['id']);
		$denied('positive quota growth is still refused above the ceiling', fn () => $store->atomic(fn () => $store->resizePayload($current, 1, $smallPolicy)), 'user_quota');
	}
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET payload = ? WHERE action_id = ?', [$original['payload'], $future['entry']['id']]);
	$store->atomic(fn () => (new \OCA\FadenstackAgent\History\JournalRetention($store, $cipher))->repairAccounting(null, $clock->value));
	if (!$afterFix) {
		$journal->finish($identity($pending['uid']), $pending['entry']['id'], $pending['entry']['ticket'], 'unchanged', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
	}
	$journal->pruneExpired(1000);
	$user('writer-identity-' . $suffix);
	$old = $prepare('identity-branch');
	$applied = $settle($old);
	$who = $identity($old['uid']);
	$undo = $journal->claim($who, $applied['id'], 'undo', $applied['version']);
	$journal->markDispatched($who, $undo['id'], $undo['ticket']);
	$file = $fileOf($old);
	$file->putContent('native before identity-branch');
	$undone = $journal->finish($who, $undo['id'], $undo['ticket'], 'verified', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET owner_hash = ? WHERE action_id = ?', [$identity('pilot-bob')->ownerHash, $old['entry']['id']]);
	$refused = false;
	$staleRedo = false;
	try {
		try {
			$new = $journal->prepare($who, $resource($file), 'nc_update_text_file', ['content' => $file->getContent()], ['content' => $file->getContent()], []);
		} catch (JournalException $error) {
			$refused = $error->reason === 'history_safety_pause';
		}
		if (!$refused) {
			$journal->markDispatched($who, $new['id'], $new['ticket']);
			$journal->finish($who, $new['id'], $new['ticket'], 'verified', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
		}
	} finally {
		$db->executeStatement('UPDATE *PREFIX*fdn_journal SET owner_hash = ? WHERE action_id = ?', [$who->ownerHash, $old['entry']['id']]);
	}
	if (!$refused) {
		$redo = $journal->claim($who, $undone['id'], 'redo', $undone['version']);
		$staleRedo = $redo['state'] === 'redo_prepared';
		$journal->abort($who, $redo['id'], $redo['ticket']);
	}
	$assert('independent allocation ownership closes the hidden inactive-Redo branch', $refused === $afterFix && $staleRedo !== $afterFix);
	$restored = $prepare('identity-restored-control');
	$journal->abort($who, $restored['entry']['id'], $restored['entry']['ticket']);
	$assert('restoring the original inactive owner preserves legitimate preparation', true);
	$user('writer-empty-' . $suffix);
	$empty = $prepare('empty-id', 1);
	$id = $empty['entry']['id'];
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET action_id = ? WHERE action_id = ?', ['', $id]);
	$clock->value += 2;
	for ($i = 0; $i < 3; $i++) {
		$journal->pruneExpired(1000);
	}
	$exists = $store->get('') !== null;
	$assert('empty primary IDs are stranded before and removed after the fix', $exists !== $afterFix);
	$assert('malformed identifier maintenance never writes native file bytes', $fileOf($empty)->getContent() === 'native before empty-id');
	if ($exists) {
		$db->executeStatement('UPDATE *PREFIX*fdn_journal SET action_id = ? WHERE action_id = ?', [$id, '']);
		$allocation = $store->allocation($id);
		$db->executeStatement('UPDATE *PREFIX*fdn_jr_resources SET active_id = ? WHERE resource_hash = ?', [$id, $allocation['resource_hash']]);
		$store->atomic(fn () => (new \OCA\FadenstackAgent\History\JournalRetention($store, $cipher))->repairAccounting(null, $clock->value));
	} else {
		$assert('empty-ID erasure installs a rollback-compatible global denial', $store->blocked($identity('pilot-bob')->ownerHash));
		$accounting('malformed identifiers do not bypass actual-byte accounting');
		// This fixture-created global pause is removed only as scenario teardown,
		// after proving persistence; production has no automatic clear path.
		$db->executeStatement('DELETE FROM *PREFIX*fdn_jr_barriers WHERE owner_hash = ?', ['*']);
	}
	$save();
} elseif ($mode === 'race-setup') {
	$clock->value += 3;
	$user('writer-race');
	$bad = $prepare('race-unknown', 1);
	$journal->markDispatched($identity('writer-race'), $bad['entry']['id'], $bad['entry']['ticket']);
	$held = $prepare('race-held');
	$finish = $prepare('race-finish');
	$journal->markDispatched($identity('writer-race'), $finish['entry']['id'], $finish['entry']['ticket']);
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET metadata_mac = ? WHERE action_id = ?', ['concurrent-expiry-bad-seal', $bad['entry']['id']]);
	$marker['race'] = ['round' => bin2hex(random_bytes(4)), 'bad' => $bad, 'observed' => $store->get($bad['entry']['id']), 'held' => $held, 'finish' => $finish];
	$save();
	$assert('concurrent cleanup, dispatch and healthy settlement fixtures are ready', true);
} elseif (in_array($mode, ['race-a', 'race-b', 'race-dispatch', 'race-finish'], true)) {
	$clock->value += 5;
	$user('writer-race');
	$gate = '/tmp/faden-writer-race-' . $marker['race']['round'];
	file_put_contents($gate . '.' . $mode, 'ready');
	$deadline = microtime(true) + 25;
	while (!is_file($gate . '.go')) {
		if (microtime(true) > $deadline) {
			throw new RuntimeException('Race release timeout');
		}
		usleep(20000);
	}
	if ($mode === 'race-a' || $mode === 'race-b') {
		$result = (new \OCA\FadenstackAgent\History\JournalRetention($store, $cipher))->expireUnreadable($marker['race']['observed'], $clock->value);
		echo json_encode(['changed' => $result], JSON_THROW_ON_ERROR);
		exit;
	}
	if ($mode === 'race-dispatch') {
		$item = $marker['race']['held'];
		$denied('concurrent old-ticket dispatch is refused on both sides of erasure', fn () => $journal->markDispatched($identity('writer-race'), $item['entry']['id'], $item['entry']['ticket']));
	} else {
		$item = $marker['race']['finish'];
		$file = $fileOf($item);
		$result = $journal->finish($identity('writer-race'), $item['entry']['id'], $item['entry']['ticket'], 'unchanged', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
		$assert('concurrent healthy no-change settlement succeeds and never clears denial', $result['state'] === 'failed');
	}
} elseif ($mode === 'race-verify') {
	$id = $marker['race']['bad']['entry']['id'];
	$assert('competing cleanup removes journal, attempts and allocation exactly once', $store->get($id) === null && $store->attempts($id) === [] && $store->allocation($id) === null);
	$assert('effect denial survives competing native metadata mutations', $store->blocked($identity('writer-race')->ownerHash));
	foreach (['bad', 'held', 'finish'] as $name) {
		$item = $marker['race'][$name];
		$file = $fileOf($item);
		$assert('concurrent ' . $name . ' never changes native content', $file->getContent() === 'native before ' . pathinfo($file->getName(), PATHINFO_FILENAME));
	}
	$accounting('concurrent expiry and settlement preserve exact bytes and claims accounting');
} elseif ($mode === 'audit') {
	$clock->value += 3;
	$user('writer-audit');
	$item = $prepare('audit-expiry', 1);
	$clock->value += 2;
	$logger = new class extends \Psr\Log\AbstractLogger {
		public array $records = [];
		public function log($level, $message, array $context = []): void {
			$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
		}
	};
	$job = new \OCA\FadenstackAgent\BackgroundJob\ExpireJournal($clock, $journal, $logger);
	(new ReflectionMethod($job, 'run'))->invoke($job, null);
	$assert('scheduled retention emits an aggregate audit event', count($logger->records) === 1 && $logger->records[0]['context']['changes'] >= 1);
	$context = $logger->records[0]['context'];
	$assert('app-supplied audit fields contain scope count and time only', array_keys($context) === ['app', 'scope', 'changes', 'time'] && $context['scope'] === 'global');
	$nativeJob = Server::get(\OCA\FadenstackAgent\BackgroundJob\ExpireJournal::class);
	$assert('normal Nextcloud DI supplies the retention logger', (new ReflectionProperty($nativeJob, 'logger'))->getValue($nativeJob) instanceof \Psr\Log\LoggerInterface);
	$assert('scheduled expiry never writes native file content', $fileOf($item)->getContent() === 'native before audit-expiry');
	$accounting('scheduled retention keeps every physical byte correctly attributed');
	$save();
} elseif ($mode === 'cursor') {
	$clock->value += 3;
	$user('writer-cursor');
	$empty = $prepare('unexpired-empty', 3600);
	$due = $prepare('cursor-healthy-due', 1);
	$id = $empty['entry']['id'];
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET action_id = ? WHERE action_id = ?', ['', $id]);
	$damaged = $store->get('');
	$db->executeStatement('UPDATE *PREFIX*fdn_jr_control SET cursor_id = ?, generation = ? WHERE control_name = ?', ['', 0, 'retention']);
	$clock->value += 2;
	$limit = count($store->page(null, 1000)) + 10;
	for ($i = 0; $i < $limit; $i++) {
		$journal->pruneExpired(1);
		if ($store->get($due['entry']['id'])['payload'] === null) {
			break;
		}
	}
	$assert('one-record windows progress past an unexpired empty-ID row', $store->get($due['entry']['id'])['payload'] === null);
	$assert('unexpired empty-ID data remains byte-exact across bounded scans', $store->get('') === $damaged);
	$assert('unattributed retained data is covered by persistent global denial', $store->retentionStats()['globalPauses'] === 1);
	$accounting('empty initial key and one-record windows preserve exact byte accounting');
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET action_id = ? WHERE action_id = ?', [$id, '']);
	$allocation = $store->allocation($id);
	$db->executeStatement('UPDATE *PREFIX*fdn_jr_resources SET active_id = ? WHERE resource_hash = ?', [$id, $allocation['resource_hash']]);
	$store->atomic(fn () => (new \OCA\FadenstackAgent\History\JournalRetention($store, $cipher))->repairAccounting(null, $clock->value));
	// Fixture scenario teardown only; an application never clears the pause here.
	$db->executeStatement('DELETE FROM *PREFIX*fdn_jr_barriers WHERE owner_hash = ?', ['*']);
	$save();
} elseif ($mode === 'legacy') {
	$clock->value += 3;
	$user('writer-legacy');
	$due = $prepare('legacy-short-' . bin2hex(random_bytes(3)), 1);
	$future = $marker['legacyFuture'];
	$marker['legacyFuture']['original'] = $store->get($future['entry']['id']);
	foreach ([$due, $future] as $item) {
		$id = $item['entry']['id'];
		$db->executeStatement('DELETE FROM *PREFIX*fdn_jr_alloc WHERE action_id = ?', [$id]);
		$db->executeStatement('UPDATE *PREFIX*fdn_journal SET owner_hash = ?, resource_hash = ?, payload_bytes = ?, metadata_mac = ? WHERE action_id = ?',
			[$identity('pilot-bob')->ownerHash, str_repeat('d', 64), 2147483647, 'legacy-no-allocation', $id]);
	}
	$db->executeStatement('DELETE FROM *PREFIX*fdn_jr_alloc WHERE action_id = ?', [$marker['legacyDue']['entry']['id']]);
	$damaged = $store->get($future['entry']['id']);
	$clock->value += 2;
	$journal->pruneExpired(1000);
	$assert('expired allocation-less legacy record is deleted without guessed ownership', $store->get($due['entry']['id']) === null && $store->attempts($due['entry']['id']) === []);
	$assert('unexpired allocation-less record remains byte-exact', $store->get($future['entry']['id']) === $damaged);
	$stats = $store->retentionStats();
	$assert('unattributed retained data has an exact explicit byte and entry pool', $stats['unattributedEntries'] === 1 && $stats['unattributedBytes'] === strlen($damaged['payload']) && $stats['globalPauses'] === 1);
	$assert('readable legacy data is independently backfilled before quota rebuilding', $store->allocation($marker['legacyDue']['entry']['id']) !== null);
	$accounting('legacy fallback accounts for every physical byte without crediting a guessed owner');
	$user('pilot-bob');
	$denied('global fallback blocks a different native user', fn () => $journal->prepare($identity('pilot-bob'), $resource($fileOf($marker['bob'])), 'nc_update_text_file', [], [], []));
	$original = $marker['legacyFuture']['original'];
	$keys = array_values(array_filter(array_keys($original), static fn ($key) => $key !== 'action_id'));
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET ' . implode(', ', array_map(static fn ($key) => $key . ' = ?', $keys)) . ' WHERE action_id = ?', [...array_map(static fn ($key) => $original[$key], $keys), $original['action_id']]);
	$cipher->verify($store->get($original['action_id']));
	$assert('original legacy encrypted content remains recoverable before expiry', $cipher->decrypt($store->get($original['action_id']))['forward']['content'] === 'native after legacy-future');
	$store->atomic(static fn () => (new \OCA\FadenstackAgent\History\JournalRetention($store, $cipher))->repairAccounting(null, $clock->value));
	$assert('key or header restoration never silently clears global denial', $store->blocked($identity('pilot-bob')->ownerHash) && $store->retentionStats()['unattributedEntries'] === 0);
	$accounting('verified recovery removes unattributed charges and restores exact per-owner counts');
	$save();
} elseif ($mode === 'rollback-global') {
	$user('pilot-bob');
	$denied('old reader honors writer global denial after attribution recovery', fn () => $journal->prepare($identity('pilot-bob'), $resource($fileOf($marker['bob'])), 'nc_update_text_file', [], [], []));
	$user($uid);
	foreach (['undo', 'redo'] as $direction) {
		$item = $marker['held'][$direction];
		$denied('old reader still blocks live ' . $direction . ' ticket under global denial', fn () => $journal->markDispatched($owner, $item['entry']['id'], $item['entry']['ticket']));
	}
	$accounting('global denial and counter type changes remain readable by the old package');
} elseif ($mode === 'aged') {
	$clock->value += 3196810;
	for ($i = 0; $i < 3; $i++) {
		$journal->pruneExpired(1000);
	}
	foreach (['orphan', 'orphanAllocation'] as $name) {
		$id = $marker[$name]['entry']['id'];
		$assert('orphan ' . $name . ' minimal facts and attempts expire on their bounded deadline', $store->allocation($id) === null && $store->attempts($id) === []);
	}
	$id = $marker['unknown']['entry']['id'];
	$row = $store->get($id);
	$assert('readable unresolved native effect retains exclusion after payload expiry', $row['state'] === 'needs_reconciliation' && $row['payload'] === null && in_array($id, array_column($store->activeClaims($identity('pilot-bob')->ownerHash), 'active_id'), true));
	$assert('minimal denials survive later key restoration and expiry cycles', $store->blocked($owner->ownerHash) && $store->blocked($identity('writer-legacy')->ownerHash));
	$accounting('aged cleanup and orphan removal keep all attributed counters exact');
} elseif ($mode === 'secret-seed') {
	$clock->value += 3;
	$user('writer-secret');
	$marker['secret']['due'] = $prepare('secret-due', 1);
	$marker['secret']['future'] = $prepare('secret-future', 3600);
	$marker['secret']['original'] = $store->get($marker['secret']['future']['entry']['id']);
	$assert('native instance-key fixtures are encrypted by the current default ICrypto', $journal->preview($identity('writer-secret'), $marker['secret']['future']['entry']['id'])['snapshot']['forward']['content'] === 'native after secret-future');
	$save();
} elseif ($mode === 'secret-expire') {
	$clock->value += 5;
	$user('writer-secret');
	$future = $marker['secret']['future'];
	$denied('changing the actual Nextcloud secret makes old snapshots unavailable', fn () => $journal->preview($identity('writer-secret'), $future['entry']['id']), 'journal_integrity');
	$journal->pruneExpired(1000);
	$assert('actual key-loss cleanup deletes due data without the old key', $store->get($marker['secret']['due']['entry']['id']) === null);
	$assert('actual key-loss cleanup preserves unexpired ciphertext byte-exact', $store->get($future['entry']['id']) === $marker['secret']['original']);
	$assert('actual key-loss erasure retains independent effect exclusion', $store->blocked($identity('writer-secret')->ownerHash));
	$user('writer-new-key');
	$item = $prepare('new-key-native-write');
	$settle($item);
	$marker['secret']['newKey'] = $item;
	$assert('a fresh owner can produce and read native journal data with the changed secret', $journal->preview($identity('writer-new-key'), $item['entry']['id'])['snapshot']['forward']['content'] === 'native after new-key-native-write');
	$accounting('actual key-loss maintenance keeps physical counters exact');
	$save();
} elseif ($mode === 'secret-restored') {
	$clock->value += 5;
	$user('writer-secret');
	$id = $marker['secret']['future']['entry']['id'];
	$assert('restored original configuration opens every remaining unexpired old fixture', $store->get($id) === $marker['secret']['original'] && $journal->preview($identity('writer-secret'), $id)['snapshot']['forward']['content'] === 'native after secret-future');
	$assert('key restoration never clears a persisted safety denial', $store->blocked($identity('writer-secret')->ownerHash));
	$user('writer-new-key');
	$denied('new-key data is not silently rewrapped when the original key is restored', fn () => $journal->preview($identity('writer-new-key'), $marker['secret']['newKey']['entry']['id']), 'journal_integrity');
	$clock->value += 604801;
	$journal->pruneExpired(1000);
	$id = $marker['secret']['newKey']['entry']['id'];
	$assert('new-key data also expires without retaining its old key', $store->get($id) === null && $store->attempts($id) === [] && $store->allocation($id) === null);
	$assert('key-loss maintenance never changes native file content', $fileOf($marker['secret']['future'])->getContent() === 'native before secret-future' && $fileOf($marker['secret']['newKey'])->getContent() === 'native after new-key-native-write');
	$accounting('restored-key and subsequent expiry accounting remain exact');
} elseif ($mode === 'snapshot') {
	$tables = ['fdn_journal' => 'action_id', 'fdn_jr_attempts' => 'action_id, version', 'fdn_jr_alloc' => 'action_id',
		'fdn_jr_owners' => 'owner_hash', 'fdn_jr_resources' => 'resource_hash', 'fdn_jr_barriers' => 'owner_hash', 'fdn_jr_control' => 'control_name'];
	$hashes = [];
	foreach ($tables as $table => $order) {
		$hashes[$table] = hash('sha256', json_encode($query('SELECT * FROM *PREFIX*' . $table . ' ORDER BY ' . $order), JSON_THROW_ON_ERROR));
	}
	echo json_encode(['hashes' => $hashes], JSON_THROW_ON_ERROR);
	exit;
} elseif ($mode === 'ui-seed') {
	$assert('persistent denial remains after expired source data is erased', $store->blocked($owner->ownerHash));
	$folder = $root->getUserFolder($uid)->get($marker['folders'][$uid]);
	echo json_encode(['checks' => $checks, 'folder' => $folder->getName(), 'folderId' => $folder->getId()], JSON_THROW_ON_ERROR);
	exit;
} else {
	throw new RuntimeException('Unsupported test mode');
}
echo json_encode(['mode' => $mode, 'checks' => $checks, 'nativeKeyUnchanged' => true], JSON_THROW_ON_ERROR);
