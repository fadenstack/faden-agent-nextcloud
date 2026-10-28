<?php

declare(strict_types=1);

if (getenv('FADEN_DISPOSABLE_TEST') !== 'reader-20261028') {
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';

use OCA\FadenstackAgent\History\JournalCipher;
use OCA\FadenstackAgent\History\JournalException;
use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalInspector;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\SqlJournalStore;
use OCA\FadenstackAgent\Identity\CloudIdentityVerifier;
use OCA\FadenstackAgent\Service\FileService;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use OCP\Server;

/** Test-only adapter: native ICrypto with an explicit public fixture password. */
final class ReaderFixtureCrypto implements ICrypto {
	public function __construct(
		private ICrypto $native,
		private string $fixturePassword,
	) {
	}
	public function calculateHMAC(string $message, string $password = ''): string {
		return $this->native->calculateHMAC($message, $this->fixturePassword);
	}
	public function encrypt(string $plaintext, string $password = ''): string {
		return $this->native->encrypt($plaintext, $this->fixturePassword);
	}
	public function decrypt(string $authenticatedCiphertext, string $password = ''): string {
		return $this->native->decrypt($authenticatedCiphertext, $this->fixturePassword);
	}
}

$checks = [];
set_exception_handler(static function (Throwable $error) use (&$checks): never {
	fwrite(STDERR, json_encode(['error' => $error::class, 'line' => $error->getLine(), 'file' => basename($error->getFile()), 'checks' => $checks], JSON_THROW_ON_ERROR));
	exit(1);
});
$assert = static function (string $case, bool $pass) use (&$checks): void {
	$checks[] = compact('case', 'pass');
	if (!$pass) {
		throw new RuntimeException('Reader acceptance failed');
	}
};
$uid = 'faden-workflow-lab';
Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
$home = Server::get(IRootFolder::class)->getUserFolder($uid);
$config = Server::get(IConfig::class);
$db = Server::get(IDBConnection::class);
$store = Server::get(SqlJournalStore::class);
$journal = Server::get(JournalService::class);
$cipher = Server::get(JournalCipher::class);
$broker = Server::get(FileService::class);
$nativeIdentity = static fn () => (new ReflectionMethod($broker, 'journalIdentity'))->invoke($broker);
$principal = Server::get(CloudIdentityVerifier::class)->verify('synthetic-principal-token-aaaa');
$write = static function ($file, string $content) use ($broker): string {
	$context = $broker->select($file->getId());
	$read = $broker->read($context['id']);
	$proposal = $broker->propose($context['id'], 'update', '', $content, $read['file']['etag']);
	$broker->approve($proposal['id']);
	return $broker->commit($proposal['id'])['actionId'];
};
$mode = $argv[1] ?? 'test';
$markerPath = '/tmp/faden-reader-marker.json';
if ($mode === 'seed-old') {
	// A public test-only secret for portable golden crypto fixtures, never a real deployment key.
	$secret = 'fadenstack-reader-golden-test-only-2026';
	$folder = $home->newFolder('Reader upgrade ' . bin2hex(random_bytes(4)));
	$native = $nativeIdentity();
	$nativeId = $write($folder->newFile('native.md', "Before reader upgrade.\n"), "After reader upgrade.\n");
	$broker->bindCloudPrincipal($principal);
	$paired = $broker->pairedHistoryIdentity($principal);
	$pairedId = $write($folder->newFile('paired.md', "Before paired upgrade.\n"), "After paired upgrade.\n");
	$marker = ['folder' => $folder->getName(), 'folderId' => $folder->getId(), 'nativeId' => $nativeId, 'nativeScope' => $native->scopeHash, 'pairedId' => $pairedId, 'pairedScope' => $paired->scopeHash];
	file_put_contents($markerPath, json_encode($marker, JSON_THROW_ON_ERROR));
	$row = $store->get($pairedId);
	$payload = $journal->preview($paired, $pairedId)['snapshot'];
	$goldens = ['testSecret' => $secret, 'nativeProducer' => '0.1.7', 'fixtures' => []];
	foreach (json_decode(file_get_contents('/tmp/faden-reader-ciphers.json'), true, 16, JSON_THROW_ON_ERROR) as $producer) {
		require $producer['path'];
		$old = new ($producer['class'])(new ReaderFixtureCrypto(Server::get(ICrypto::class), $secret));
		$copy = $row;
		unset($copy['metadata_mac']);
		$copy['payload'] = $old->encrypt($copy, $payload);
		$copy['payload_bytes'] = strlen($copy['payload']);
		$copy = $old->sign($copy);
		$receipt = $old->receipt($copy, 'applied');
		$copy['metadata_mac'] = base64_encode($copy['metadata_mac']);
		$receipt['receipt_mac'] = base64_encode($receipt['receipt_mac']);
		$goldens['fixtures'][] = ['producer' => $producer, 'row' => $copy, 'receipt' => $receipt, 'payload' => $payload];
	}
	file_put_contents('/tmp/faden-reader-golden.json', json_encode($goldens, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
	$failed = false;
	try {
		$cipher->verify([...$row, 'format' => 'v1']);
	} catch (JournalException $error) {
		$failed = $error->reason === 'journal_integrity';
	}
	echo json_encode(['marker' => $marker, 'oldReaderRejectsAddedColumn' => $failed], JSON_THROW_ON_ERROR);
	exit;
}

$marker = json_decode(file_get_contents($markerPath), true, 16, JSON_THROW_ON_ERROR);
if ($mode === 'ui-damage' || $mode === 'ui-restore') {
	$path = '/tmp/faden-reader-ui-row.json';
	if ($mode === 'ui-damage') {
		foreach ($store->owned($nativeIdentity()->ownerHash) as $candidate) {
			if ($candidate['state'] === 'cancelled') {
				file_put_contents($path, json_encode(['id' => $candidate['action_id'], 'mac' => $candidate['metadata_mac']], JSON_THROW_ON_ERROR));
				$db->executeStatement('UPDATE *PREFIX*fdn_journal SET metadata_mac = ? WHERE action_id = ?', ['damaged-ui-test-tag', $candidate['action_id']]);
				break;
			}
		}
		if (!isset($candidate) || $candidate['state'] !== 'cancelled') {
			exit(3);
		}
	} else {
		$saved = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
		$db->executeStatement('UPDATE *PREFIX*fdn_journal SET metadata_mac = ? WHERE action_id = ?', [$saved['mac'], $saved['id']]);
		unlink($path);
	}
	echo json_encode(['folder' => $marker['folder'], 'folderId' => $marker['folderId']], JSON_THROW_ON_ERROR);
	exit;
}
$goldens = json_decode(file_get_contents('/var/www/html/custom_apps/fadenstack_agent/tests/fixtures/history-v1-golden.json'), true, 32, JSON_THROW_ON_ERROR);
$goldenReader = new JournalCipher(new ReaderFixtureCrypto(Server::get(ICrypto::class), $goldens['testSecret']));
foreach ($goldens['fixtures'] as $golden) {
	$row = $golden['row'];
	$row['metadata_mac'] = base64_decode($row['metadata_mac'], true);
	$receipt = $golden['receipt'];
	$receipt['receipt_mac'] = base64_decode($receipt['receipt_mac'], true);
	$goldenReader->verify([...$row, 'format' => 'v1', 'future_column' => 'reader compatibility']);
	$goldenReader->verifyReceipt([...$receipt, 'future_column' => 'reader compatibility']);
	$assert($golden['producer']['version'] . ': authentic old cipher payload/header/receipt open with extra columns', $goldenReader->decrypt($row) === $golden['payload']);
}
$native = $nativeIdentity();
$assert('one-time native namespace transition does not adopt old native scope', $native->scopeHash === JournalIdentity::nativeScope() && $native->scopeHash !== $marker['nativeScope']);
$folder = $home->get($marker['folder']);
$nativeId = $write($folder->newFile('native-reader.md', 'Reader before'), 'Reader after');
$native = $nativeIdentity();
$version = $config->getAppValue('fadenstack_agent', 'installed_version');
try {
	$config->setAppValue('fadenstack_agent', 'installed_version', 'reader-version-only-test');
	$current = $nativeIdentity();
	$assert('later version-only bump preserves native namespace', $current->scopeHash === $native->scopeHash);
	$assert('later version-only bump invalidates native session/approval binding', $current->sessionHash !== $native->sessionHash);
	$assert('native snapshot remains accessible after version-only bump', $journal->preview($current, $nativeId)['snapshot']['forward']['content'] === 'Reader after');
} finally {
	$config->setAppValue('fadenstack_agent', 'installed_version', $version);
}
$native = $nativeIdentity();
$broker->bindCloudPrincipal($principal);
$paired = $broker->pairedHistoryIdentity($principal);
$assert('paired namespace survives the reader upgrade unchanged', $paired->scopeHash === $marker['pairedScope']);
$assert('actual v0.1.7 paired snapshot remains readable', $journal->preview($paired, $marker['pairedId'])['snapshot']['forward']['content'] === "After paired upgrade.\n");
$inspector = Server::get(JournalInspector::class);
$availability = $inspector->availability($paired);
$assert('legacy native scope yields an honest earlier-scope count', $availability['earlierScopeCount'] >= 1 && $availability['earlierContentExpiresBy'] !== null);

// Exercise more than one diagnostic page, without writing any native content.
$file = $folder->get('native-reader.md');
for ($i = 0; $i < 30; $i++) {
	$entry = $journal->prepare($native, new JournalResource('files', 'file:' . $file->getId(), 'file:' . $file->getId()), 'nc_update_text_file', ['test' => 1], ['test' => 0], []);
	$journal->abort($native, $entry['id'], $entry['ticket']);
}
$fingerprint = static function () use ($db): string {
	$tables = [];
	foreach (['fdn_journal' => 'action_id', 'fdn_jr_attempts' => 'action_id, version', 'fdn_jr_owners' => 'owner_hash', 'fdn_jr_resources' => 'resource_hash'] as $table => $order) {
		$result = $db->executeQuery('SELECT * FROM *PREFIX*' . $table . ' ORDER BY ' . $order);
		try {
			$tables[$table] = $result->fetchAll();
		} finally {
			$result->closeCursor();
		}
	}
	return hash('sha256', JournalCipher::json($tables));
};
$initial = $fingerprint();
$healthy = $inspector->check();
$wrongKey = new JournalInspector($store, new JournalCipher(new ReaderFixtureCrypto(Server::get(ICrypto::class), 'wrong-fixture-password')), Server::get(\OCP\AppFramework\Utility\ITimeFactory::class));
$wrongStatus = $wrongKey->check();
$assert('native wrong-key projection makes every existing row unavailable without mutating core configuration', $wrongStatus['readable'] === 0 && array_sum($wrongStatus['unavailable']) === $healthy['rows']);
$assert('operator check reaches all native rows over multiple pages', $healthy['rows'] >= 33 && $healthy['readable'] === $healthy['rows']);
$assert('operator check changes no journal, receipt, claim or quota bytes', $fingerprint() === $initial);
$saved = $store->get($entry['id']);
try {
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET metadata_mac = ? WHERE action_id = ?', ['damaged-test-tag', $entry['id']]);
	$damaged = $fingerprint();
	$status = $inspector->check();
	$assert('one unreadable row never blocks diagnostic progress', $status['rows'] === $healthy['rows'] && array_sum($status['unavailable']) === 1 && $status['readable'] === $healthy['readable'] - 1);
	$assert('unreadable native history has a coarse notice without target or action ID', $inspector->availability($paired)['unavailableCount'] === 1);
	$assert('checking a damaged row never authenticates or repairs it', $fingerprint() === $damaged);
} finally {
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET metadata_mac = ? WHERE action_id = ?', [$saved['metadata_mac'], $saved['action_id']]);
}

// Prospective schema only in the disposable acceptance DB; this release adds no migration.
$db->executeStatement("ALTER TABLE *PREFIX*fdn_journal ADD format VARCHAR(16) DEFAULT 'v1'");
try {
	$status = $inspector->check();
	$assert('prospective format column preserves every released v1 seal', $status['readable'] === $healthy['readable']);
	$db->executeStatement('UPDATE *PREFIX*fdn_journal SET format = ? WHERE action_id = ?', ['v2', $saved['action_id']]);
	$assert('unsupported format is counted and refuses dispatch', ($inspector->check()['unavailable']['unsupported_journal_format'] ?? 0) === 1);
} finally {
	$db->executeStatement('ALTER TABLE *PREFIX*fdn_journal DROP COLUMN format');
}
$assert('diagnostic acceptance restores all fixture rows exactly', $fingerprint() === $initial);
echo json_encode(['checks' => $checks, 'healthy' => $healthy, 'availability' => $availability], JSON_THROW_ON_ERROR);
