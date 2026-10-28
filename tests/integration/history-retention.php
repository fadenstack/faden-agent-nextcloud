<?php

declare(strict_types=1);

if (getenv('FADEN_DISPOSABLE_TEST') !== 'retention-20261028') {
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';

use OCA\FadenstackAgent\History\AccessResolverRegistry;
use OCA\FadenstackAgent\History\FilesAccessResolver;
use OCA\FadenstackAgent\History\JournalCipher;
use OCA\FadenstackAgent\History\JournalException;
use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\SqlJournalStore;
use OCA\FadenstackAgent\History\VerifiedReadback;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

final class RetentionTestTime implements ITimeFactory {
	public int $value;
	public function __construct(int $value) {
		$this->value = $value;
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
		'line' => $error->getLine(), 'file' => basename($error->getFile()), 'checks' => $checks], JSON_THROW_ON_ERROR));
	exit(1);
});
$assert = static function (string $case, bool $pass) use (&$checks): void {
	$checks[] = compact('case', 'pass');
	if (!$pass) {
		throw new RuntimeException('Retention acceptance failed');
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
$uid = 'faden-workflow-lab';
$session = Server::get(IUserSession::class);
$session->setUser(Server::get(IUserManager::class)->get($uid));
$root = Server::get(IRootFolder::class);
$home = $root->getUserFolder($uid);
$db = Server::get(IDBConnection::class);
$store = new SqlJournalStore($db);
$cipher = Server::get(JournalCipher::class);
$registry = new AccessResolverRegistry();
$registry->register('files', new FilesAccessResolver($root, $session, new ScopePolicy(Server::get(\OCP\IConfig::class), $root, $session, Server::get(\OCP\Share\IManager::class))));
$mode = $argv[1] ?? '';
$path = '/tmp/faden-retention-marker.json';
$marker = is_file($path) ? json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : ['time' => time(), 'scope' => hash('sha256', random_bytes(32))];
$clock = new RetentionTestTime($marker['time']);
$journal = new JournalService($store, $cipher, $registry, $clock);
$identity = new JournalIdentity($uid, $marker['scope'], str_repeat('r', 64));
$otherScope = new JournalIdentity($uid, hash('sha256', 'retention-other-scope'), str_repeat('s', 64));
$resource = static fn ($file) => new JournalResource('files', 'file:' . $file->getId(), 'file:' . $file->getId());
$save = static function () use (&$marker, $path): void {
	file_put_contents($path, json_encode($marker, JSON_THROW_ON_ERROR));
};
if ($mode === 'baseline' || $mode === 'reader-seed') {
	$folder = $home->newFolder('Retention ' . bin2hex(random_bytes(4)));
	$marker['folder'] = $folder->getName();
	$bad = $journal->prepare($identity, $resource($folder->newFile('bad.md', 'native bad unchanged')), 'nc_update_text_file', [], [], [], 1);
	$good = $journal->prepare($identity, $resource($folder->newFile('good.md', 'native good unchanged')), 'nc_update_text_file', [], [], [], 2);
	$original = $store->get($bad['id']);
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET metadata_mac = ? WHERE action_id = ?', ['unreadable-baseline', $bad['id']]);
	$clock->value += 3;
	if ($mode === 'baseline') {
		$denied('original v0.1.8 trigger: bad earliest row aborts maintenance', fn () => $journal->pruneExpired(), 'journal_integrity');
		$assert('original trigger starves a healthy due row', $store->get($good['id'])['payload'] !== null);
	}
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET metadata_mac = ? WHERE action_id = ?', [$original['metadata_mac'], $bad['id']]);
	$expired = $mode === 'baseline' ? $journal->pruneExpired() : $journal->expire($identity);
	$assert('legitimate control: both healthy due snapshots expire', $expired === 2 && $store->get($good['id'])['payload'] === null);
	$clock->value = $marker['time'];
	$marker['held'] = [];
	foreach (['apply', 'undo', 'redo', 'finish'] as $direction) {
		$file = $folder->newFile($direction . '.md', 'before ' . $direction);
		$prepared = $journal->prepare($identity, $resource($file), 'nc_update_text_file', ['content' => 'after ' . $direction], ['content' => 'before ' . $direction], []);
		if ($direction !== 'apply') {
			$journal->markDispatched($identity, $prepared['id'], $prepared['ticket']);
			if ($direction !== 'finish') {
				$file->putContent('after ' . $direction);
				$settled = $journal->finish($identity, $prepared['id'], $prepared['ticket'], 'verified', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
				$prepared = $journal->claim($identity, $prepared['id'], 'undo', $settled['version']);
				if ($direction === 'redo') {
					$journal->markDispatched($identity, $prepared['id'], $prepared['ticket']);
					$file->putContent('before redo');
					$settled = $journal->finish($identity, $prepared['id'], $prepared['ticket'], 'verified', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
					$prepared = $journal->claim($identity, $prepared['id'], 'redo', $settled['version']);
				}
			}
		}
		$marker['held'][$direction] = $prepared;
	}
	$marker['beforeUpgrade'] = hash('sha256', JournalCipher::json($store->owned($identity->ownerHash)));
	$save();
	$assert('real native files and three prepared ticket directions seeded', count($marker['held']) === 4);
} elseif ($mode === 'reader') {
	$folder = $home->get($marker['folder']);
	$assert('reader migration never rewrites existing journal bytes or seals', $marker['beforeUpgrade'] === hash('sha256', JournalCipher::json($store->owned($identity->ownerHash))));
	foreach ($store->owned($identity->ownerHash) as $row) {
		$allocation = $store->allocation($row['action_id']);
		$assert('verified upgrade row has independent allocation', $allocation !== null && $allocation['owner_hash'] === $identity->ownerHash && $allocation['payload_bytes'] === $row['payload_bytes'] && $allocation['expires_at'] === $row['expires_at']);
	}
	$held = $marker['held'];
	$row = $store->get($held['apply']['id']);
	foreach (['owner_hash' => str_repeat('f', 64), 'resource_hash' => str_repeat('e', 64), 'state' => 'applied', 'metadata_mac' => 'corrupt-active-header'] as $column => $value) {
		$db->executeStatement('UPDATE *PREFIX*fdn_journal SET ' . $column . ' = ? WHERE action_id = ?', [$value, $row['action_id']]);
		$assert('independent live claim detects damaged ' . $column, $journal->writesPaused($identity));
		$denied('other-scope new preparation denied for damaged ' . $column, fn () => $journal->prepare($otherScope, $resource($folder->get('good.md')), 'nc_update_text_file', [], [], []));
		$assert('temporary denial never changes damaged row or native bytes', $store->get($row['action_id'])[$column] === $value && $folder->get('apply.md')->getContent() === 'before apply');
		$db->executeStatement('UPDATE *PREFIX*fdn_journal SET ' . $column . ' = ? WHERE action_id = ?', [$row[$column], $row['action_id']]);
	}
	$assert('restoring original header before expiry removes temporary pause', !$journal->writesPaused($identity));
	$db->executeStatement('INSERT INTO *PREFIX*fdn_jr_barriers (owner_hash, reason, created_at) VALUES (?, ?, ?)', [$identity->ownerHash, 'unreadable_claim', $clock->value]);
	foreach (['apply', 'undo', 'redo'] as $direction) {
		$denied('reader blocks already-prepared ' . $direction . ' dispatch', fn () => $journal->markDispatched($identity, $held[$direction]['id'], $held[$direction]['ticket']));
	}
	$denied('owner barrier covers a different private scope', fn () => $journal->prepare($otherScope, $resource($folder->get('good.md')), 'nc_update_text_file', [], [], []));
	$assert('stop before dispatch still releases a healthy claim', $journal->abort($identity, $held['apply']['id'], $held['apply']['ticket'])['state'] === 'cancelled');
	$file = $folder->get('finish.md');
	$assert('trusted unchanged readback can settle an earlier dispatch during pause', $journal->finish($identity, $held['finish']['id'], $held['finish']['ticket'], 'unchanged', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())))['state'] === 'failed');
	$assert('healthy settlement never clears owner barrier', $store->blocked($identity->ownerHash));
	$db->executeStatement('DELETE FROM *PREFIX*fdn_jr_barriers WHERE owner_hash = ?', [$identity->ownerHash]);
	$journal->abort($identity, $held['undo']['id'], $held['undo']['ticket']);
	$journal->abort($identity, $held['redo']['id'], $held['redo']['ticket']);
	$inactive = $store->get($held['finish']['id']);
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET metadata_mac = ? WHERE action_id = ?', ['corrupt-inactive-header', $inactive['action_id']]);
	$assert('inactive unreadable history preserves possible Redo ordering', $journal->writesPaused($identity));
	$denied('new writes wait until unreadable inactive ordering can be preserved', fn () => $journal->prepare($otherScope, $resource($folder->get('good.md')), 'nc_update_text_file', [], [], []));
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET metadata_mac = ? WHERE action_id = ?', [$inactive['metadata_mac'], $inactive['action_id']]);
	$new = $journal->prepare($otherScope, $resource($folder->get('good.md')), 'nc_update_text_file', [], [], []);
	$journal->abort($otherScope, $new['id'], $new['ticket']);
	$assert('restoring inactive history permits normal new actions', true);
	$db->executeStatement('INSERT INTO *PREFIX*fdn_jr_barriers (owner_hash, reason, created_at) VALUES (?, ?, ?)', ['*', 'legacy_unattributed', $clock->value]);
	$denied('reader also enforces a global denial', fn () => $journal->prepare($identity, $resource($folder->get('good.md')), 'nc_update_text_file', [], [], []));
	$db->executeStatement('DELETE FROM *PREFIX*fdn_jr_barriers WHERE owner_hash = ?', ['*']);
	$assert('global denial removal in test restores normal preparation', !$journal->writesPaused($identity));
} elseif ($mode === 'maintenance') {
	$folder = $home->newFolder('Retention reader maintenance ' . bin2hex(random_bytes(4)));
	$good = $journal->prepare($identity, $resource($folder->newFile('good.md', 'healthy native bytes')), 'nc_update_text_file', [], [], [], 1);
	$bad = $journal->prepare($identity, $resource($folder->newFile('bad.md', 'unreadable native bytes')), 'nc_update_text_file', [], [], [], 1);
	$original = $store->get($bad['id']);
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET metadata_mac = ? WHERE action_id = ?', ['reader-maintenance-corruption', $bad['id']]);
	$damaged = $store->get($bad['id']);
	$clock->value += 3;
	$iterations = count($store->owned($identity->ownerHash)) + 50;
	for ($i = 0; $i < $iterations; $i++) {
		$journal->pruneExpired(1);
		if ($store->get($good['id'])['payload'] === null) {
			break;
		}
	}
	$assert('successive bounded pages reach a healthy due row despite unreadable rows', $store->get($good['id'])['payload'] === null);
	$assert('reader prerequisite leaves the unreadable row byte-exact', $store->get($bad['id']) === $damaged);
	$assert('maintenance never changes native file content', $folder->get('good.md')->getContent() === 'healthy native bytes' && $folder->get('bad.md')->getContent() === 'unreadable native bytes');
	$encodingDamage = $damaged;
	$encodingDamage['resource_json'] = "\x80";
	// PostgreSQL rejects invalid UTF-8 at insertion. Inject the same stored-row
	// representation directly into the shared maintenance boundary for this case.
	(new ReflectionMethod($journal, 'expireRows'))->invoke($journal, [$encodingDamage, $store->get($good['id'])]);
	$assert('malformed UTF-8 is isolated at the maintenance verification boundary', $store->get($bad['id']) === $damaged);
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET metadata_mac = ? WHERE action_id = ?', [$original['metadata_mac'], $bad['id']]);
	$journal->expire($identity);
	$assert('original header restored before writer deletion can still be processed', $store->get($bad['id'])['payload'] === null);
	$db->executeStatement('INSERT INTO *PREFIX*fdn_jr_barriers (owner_hash, reason, created_at) VALUES (?, ?, ?)', [$identity->ownerHash, 'unreadable_claim', $clock->value]);
	try {
		$broker = Server::get(\OCA\FadenstackAgent\Service\FileService::class);
		$config = Server::get(\OCP\IConfig::class);
		$config->setAppValue('fadenstack_agent', 'history_enabled', '0');
		$selected = $broker->select($folder->get('good.md')->getId());
		$read = $broker->read($selected['id']);
		$proposal = $broker->propose($selected['id'], 'update', '', 'must never be written', $read['file']['etag']);
		$broker->approve($proposal['id']);
		$rejected = false;
		try {
			$broker->commit($proposal['id']);
		} catch (\OCA\FadenstackAgent\Service\Problem) {
			$rejected = true;
		}
		$assert('ordinary broker update obeys denial with history disabled', $rejected && $folder->get('good.md')->getContent() === 'healthy native bytes');
		$bob = Server::get(IUserManager::class)->get('pilot-bob');
		$session->setUser($bob);
		$bobFile = $root->getUserFolder('pilot-bob')->newFile('reader-owner-isolation-' . bin2hex(random_bytes(4)) . '.md', 'bob before');
		$bobIdentity = new JournalIdentity('pilot-bob', $marker['scope'], str_repeat('b', 64));
		$prepared = $journal->prepare($bobIdentity, $resource($bobFile), 'nc_update_text_file', ['content' => 'bob after'], ['content' => 'bob before'], []);
		$journal->markDispatched($bobIdentity, $prepared['id'], $prepared['ticket']);
		$bobFile->putContent('bob after');
		$settled = $journal->finish($bobIdentity, $prepared['id'], $prepared['ticket'], 'verified', new VerifiedReadback($bobFile->getStorage()->getETag($bobFile->getInternalPath())));
		$assert('owner denial leaves another native user fully functional', $settled['state'] === 'applied' && $bobFile->getContent() === 'bob after');
		$claim = $journal->claim($bobIdentity, $prepared['id'], 'undo', $settled['version']);
		$db->executeStatement('INSERT INTO *PREFIX*fdn_jr_barriers (owner_hash, reason, created_at) VALUES (?, ?, ?)', ['*', 'legacy_unattributed', $clock->value]);
		try {
			$denied('global denial covers another user prepared before it existed', fn () => $journal->markDispatched($bobIdentity, $claim['id'], $claim['ticket']));
			$journal->abort($bobIdentity, $claim['id'], $claim['ticket']);
			$denied('claim itself obeys global denial', fn () => $journal->claim($bobIdentity, $prepared['id'], 'undo', $settled['version'] + 2));
		} finally {
			$db->executeStatement('DELETE FROM *PREFIX*fdn_jr_barriers WHERE owner_hash = ?', ['*']);
		}
	} finally {
		$session->setUser(Server::get(IUserManager::class)->get($uid));
		$db->executeStatement('DELETE FROM *PREFIX*fdn_jr_barriers WHERE owner_hash = ?', [$identity->ownerHash]);
	}
} else {
	throw new RuntimeException('Unsupported test mode');
}
echo json_encode(['checks' => $checks, 'mode' => $mode, 'nativeKeyUnchanged' => true], JSON_THROW_ON_ERROR);
