<?php

declare(strict_types=1);

// Run only in the labelled disposable test container via the dev fixture helper.
if (getenv('FADEN_DISPOSABLE_TEST') !== 'foundation-20261026') {
	fwrite(STDERR, "A disposable fixture is required.\n");
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';

use OCA\FadenstackAgent\History\AccessResolverRegistry;
use OCA\FadenstackAgent\History\FilesAccessResolver;
use OCA\FadenstackAgent\History\JournalCipher;
use OCA\FadenstackAgent\History\JournalException;
use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalPolicy;
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
use OCP\Security\ICrypto;
use OCP\Server;

final class JournalTestTime implements ITimeFactory {
	public int $value;
	public function __construct() {
		$this->value = time();
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
$assert = function (string $name, bool $ok) use (&$checks): void {
	$checks[] = ['case' => $name, 'pass' => $ok];
	if (!$ok) {
		throw new RuntimeException('Failed: ' . $name);
	}
};
$denied = function (string $name, callable $work, string $reason) use ($assert): void {
	try {
		$work();
	} catch (JournalException $error) {
		$assert($name, $error->reason === $reason);
		return;
	}
	$assert($name, false);
};
$session = Server::get(IUserSession::class);
$user = Server::get(IUserManager::class)->get('faden-workflow-lab');
if ($user === null) {
	throw new RuntimeException('Fixture user is absent');
}
$session->setUser($user);
$root = Server::get(IRootFolder::class);
$home = $root->getUserFolder($user->getUID());
$folder = $home->newFolder('Journal fixture ' . bin2hex(random_bytes(6)));
$file = $folder->newFile('roundtrip.md', 'before: Grüß Gott');
$db = Server::get(IDBConnection::class);
$clock = new JournalTestTime();
$registry = new AccessResolverRegistry();
$registry->register('files', new FilesAccessResolver($root, $session, new ScopePolicy(Server::get(\OCP\IConfig::class), $root, $session, Server::get(\OCP\Share\IManager::class))));
$cipher = new JournalCipher(Server::get(ICrypto::class));
$store = new SqlJournalStore($db);
$service = new JournalService($store, $cipher, $registry, $clock);
$identity = new JournalIdentity($user->getUID(), hash('sha256', $folder->getName()), str_repeat('a', 64));
$resource = new JournalResource('files', 'file:' . $file->getId(), 'file:' . $file->getId());
$before = $file->getContent();
$after = "after: synthetic journal payload\nUnicode: 日本語";
$prepared = $service->prepare($identity, $resource, 'nc_update_text_file', ['content' => $after], ['content' => $before], ['revision' => $file->getEtag()]);
$assert('prepared is durable before native dispatch', $service->preview($identity, $prepared['id'])['state'] === 'prepared');
$raw = $store->get($prepared['id']);
$assert('encrypted payload excludes recognizable plaintext', !str_contains($raw['payload'], 'synthetic journal payload') && !str_contains($raw['payload'], 'Grüß Gott'));
$assert('metadata MAC survives database column order', $service->preview($identity, $prepared['id'])['snapshot']['forward']['content'] === $after);
$denied('duplicate resource preparation blocked', fn () => $service->prepare($identity, $resource, 'nc_update_text_file', [], [], []), 'resource_busy_or_quota');
$denied('foreign native owner cannot preview', fn () => $service->preview(new JournalIdentity('other-fixture-user', $identity->scopeHash, str_repeat('b', 64)), $prepared['id']), 'action_unavailable');
$denied('foreign configuration cannot preview', fn () => $service->preview(new JournalIdentity($identity->userId, hash('sha256', 'different-config'), str_repeat('b', 64)), $prepared['id']), 'action_unavailable');
$denied('wrong attempt ticket blocked', fn () => $service->markDispatched($identity, $prepared['id'], str_repeat('f', 64)), 'claim_conflict');
$service->markDispatched($identity, $prepared['id'], $prepared['ticket']);
$file->putContent($after);
$assert('independent native apply readback', $folder->get('roundtrip.md')->getContent() === $after);
$applied = $service->finish($identity, $prepared['id'], $prepared['ticket'], 'verified', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
$assert('only readback advances applied state', $applied['state'] === 'applied');
$reopened = new JournalService(new SqlJournalStore($db), $cipher, $registry, $clock);
$assert('new service recovers encrypted before state', $reopened->preview($identity, $prepared['id'])['snapshot']['inverse']['content'] === $before);
$claim = $reopened->claim($identity, $prepared['id'], 'undo', $applied['version']);
$denied('second tab cannot claim same undo', fn () => $service->claim($identity, $prepared['id'], 'undo', $applied['version']), 'state_conflict');
$service->markDispatched($identity, $prepared['id'], $claim['ticket']);
$file->putContent($before);
$assert('independent native inverse readback', $folder->get('roundtrip.md')->getContent() === $before);
$undone = $service->finish($identity, $prepared['id'], $claim['ticket'], 'verified', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
$assert('verified undo retains redo payload', $undone['state'] === 'undone' && $undone['journalEligibility'] === 'redo');
$redo = $service->claim($identity, $prepared['id'], 'redo', $undone['version']);
$service->markDispatched($identity, $prepared['id'], $redo['ticket']);
$file->putContent($after);
$assert('independent native redo readback', $folder->get('roundtrip.md')->getContent() === $after);
$redone = $service->finish($identity, $prepared['id'], $redo['ticket'], 'verified', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
$assert('verified redo returns to applied', $redone['state'] === 'applied');
$failed = $service->claim($identity, $prepared['id'], 'undo', $redone['version']);
$service->markDispatched($identity, $prepared['id'], $failed['ticket']);
$stillApplied = $service->finish($identity, $prepared['id'], $failed['ticket'], 'unchanged', new VerifiedReadback($file->getStorage()->getETag($file->getInternalPath())));
$assert('failed inverse does not pop entry', $stillApplied['state'] === 'applied' && $stillApplied['journalEligibility'] === 'undo');
$assert('immutable attempt history retained', count($service->attempts($identity, $prepared['id'])) >= 12);
$pending = $service->claim($identity, $prepared['id'], 'undo', $stillApplied['version']);
$cancelled = $service->abort($identity, $prepared['id'], $pending['ticket']);
$assert('Stop before inverse dispatch retains applied state', $cancelled['state'] === 'applied');
$other = $folder->newFile('expiry.md', 'expiry fixture');
$otherResource = new JournalResource('files', 'file:' . $other->getId(), 'file:' . $other->getId());
$expiring = $service->prepare($identity, $otherResource, 'nc_update_text_file', [], [], [], 1);
$clock->value += 2;
$service->expire($identity);
$assert('expired unsent preparation is not an unknown effect', $store->get($expiring['id'])['state'] === 'expired');
$again = $service->prepare($identity, $otherResource, 'nc_update_text_file', [], [], []);
$assert('expired unsent preparation releases own resource claim', $again['state'] === 'prepared');
$service->markDispatched($identity, $again['id'], $again['ticket']);
$unknown = $service->abort($identity, $again['id'], $again['ticket']);
$assert('Stop after dispatch preserves unknown completion', $unknown['state'] === 'needs_reconciliation');
$denied('unknown effect cannot be blindly retried', fn () => $service->prepare($identity, $otherResource, 'nc_update_text_file', [], [], []), 'resource_busy_or_quota');
$tiny = new JournalService($store, $cipher, $registry, $clock, new JournalPolicy(maxPayloadBytes: 10));
$denied('snapshot quota failure blocks preparation', fn () => $tiny->prepare($identity, $resource, 'nc_update_text_file', ['content' => $after], [], []), 'snapshot_too_large');
$denied('unknown provider has no elevated fallback', fn () => $service->prepare($identity, new JournalResource('calendar', 'event:1', 'calendar:1'), 'nc_test', [], [], []), 'provider_unavailable');
$inactive = $folder->newFile('inactive-owner.md', 'inactive retention fixture');
$inactiveEntry = $service->prepare($identity, new JournalResource('files', 'file:' . $inactive->getId(), 'file:' . $inactive->getId()), 'nc_update_text_file', ['content' => 'private snapshot'], [], [], 1);
$clock->value += 2;
$session->setUser(null);
try {
	$job = new \OCA\FadenstackAgent\BackgroundJob\ExpireJournal($clock, $service);
	(new ReflectionMethod($job, 'run'))->invoke($job, null);
	$expired = $store->get($inactiveEntry['id']);
	$assert('hourly maintenance expires an inactive owner without native sign-in or decryption', $expired['payload'] === null && $expired['state'] === 'expired');
} finally {
	$session->setUser($user);
}
$file->delete();
$denied('missing native resource blocks saved snapshot access', fn () => $service->preview($identity, $prepared['id']), 'permission_lost');
echo json_encode(['layer' => 'real Nextcloud database, ICrypto and native Files', 'guarded_executor' => false,
	'notes' => 'Journal persistence/lifecycle tests use explicit fixture writes; governed typed inverse execution remains separate acceptance.', 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
