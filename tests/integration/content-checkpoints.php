<?php

declare(strict_types=1);

// Journal-only acceptance in a labelled fixture; this does not certify native Forms.
if (getenv('FADEN_CONTENT_CHECKPOINT') !== 'content-20261107') {
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';

use OCA\FadenstackAgent\History\AccessResolver;
use OCA\FadenstackAgent\History\AccessResolverRegistry;
use OCA\FadenstackAgent\History\JournalCipher;
use OCA\FadenstackAgent\History\JournalException;
use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalPolicy;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\SqlJournalStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use OCP\Server;

$user = Server::get(IUserManager::class)->get('content-writer-20261107');
if ($user === null || !$user->isEnabled()) {
	throw new RuntimeException('Fresh synthetic fixture required.');
}
Server::get(IUserSession::class)->setUser($user);
$checks = [];
$assert = static function (string $name, bool $ok) use (&$checks): void {
	$checks[] = ['case' => $name, 'pass' => $ok];
	if (!$ok) {
		throw new RuntimeException($name);
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
$clock = new class implements ITimeFactory {
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
};
$access = new class implements AccessResolver {
	public bool $allowed = true;
	public function assertAccess(JournalIdentity $identity, JournalResource $resource, string $purpose): void {
		if (!$this->allowed || $identity->userId !== 'content-writer-20261107' || $resource->provider !== 'forms') {
			throw new JournalException('permission_lost', 403);
		}
	}
};
$registry = new AccessResolverRegistry();
$registry->register('forms', $access);
$store = new SqlJournalStore(Server::get(IDBConnection::class));
$cipher = new JournalCipher(Server::get(ICrypto::class));
$journal = new JournalService($store, $cipher, $registry, $clock);
$identity = new JournalIdentity($user->getUID(), hash('sha256', 'checkpoint-only-' . random_bytes(16)), str_repeat('a', 64));
$resource = new JournalResource('forms', 'collection:personal/checkpoint:' . bin2hex(random_bytes(12)), 'collection:personal');
$proposal = ['payload' => ['subject' => 'Immutable synthetic approval', 'body' => 'No native construction occurs in this journal-only test.']];
$entry = $journal->prepare($identity, $resource, 'nc_forms_create_form', ['proposal' => $proposal, 'effects' => []], [], [], 600);
$step = static fn (string $name, string $phase, array $facts = []) => $journal->checkpointEffect($identity, $entry['id'], $entry['ticket'], $name, $phase, $facts);
$denied('No effect checkpoint before root dispatch', fn () => $step('form_000', 'dispatched'), 'state_conflict');
$journal->markDispatched($identity, $entry['id'], $entry['ticket']);
$step('form_000', 'dispatched');
$version = $store->get($entry['id'])['version'];
$denied('An uncertain stage cannot be blindly sent twice', fn () => $step('form_000', 'dispatched'), 'effect_already_used');
$assert('Refused replay leaves durable row unchanged', $version === $store->get($entry['id'])['version']);
$step('form_000', 'observed', ['nativeId' => 42, 'revision' => str_repeat('b', 64)]);
$reopened = new JournalService(new SqlJournalStore(Server::get(IDBConnection::class)), $cipher, $registry, $clock);
$snapshot = $reopened->preview($identity, $entry['id'])['snapshot'];
$assert('Restart preserves exact approved payload and partial native ID', JournalCipher::json($snapshot['forward']['proposal']) === JournalCipher::json($proposal) && $snapshot['forward']['effects'][0]['facts']['nativeId'] === 42);
$assert('Progress uses existing encrypted v1 payload', !str_contains($store->get($entry['id'])['payload'], 'Immutable synthetic approval'));
$denied('A different session cannot continue', fn () => $reopened->checkpointEffect(new JournalIdentity($identity->userId, $identity->scopeHash, str_repeat('c', 64)), $entry['id'], $entry['ticket'], 'form_001', 'dispatched'), 'claim_conflict');
$denied('A foreign owner cannot continue', fn () => $reopened->checkpointEffect(new JournalIdentity('content-denied-20261107', $identity->scopeHash, str_repeat('a', 64)), $entry['id'], $entry['ticket'], 'form_001', 'dispatched'), 'action_unavailable');
$access->allowed = false;
$denied('Permission loss blocks the second dispatch', fn () => $step('form_001', 'dispatched'), 'permission_lost');
$access->allowed = true;
$before = $store->get($entry['id']);
$tiny = new JournalService($store, $cipher, $registry, $clock, new JournalPolicy(maxPayloadBytes: 10));
$denied('Snapshot quota blocks dispatch checkpoint', fn () => $tiny->checkpointEffect($identity, $entry['id'], $entry['ticket'], 'form_001', 'dispatched'), 'snapshot_too_large');
$quota = new JournalService($store, $cipher, $registry, $clock, new JournalPolicy(maxUserBytes: 1));
$denied('User quota blocks progress growth', fn () => $quota->checkpointEffect($identity, $entry['id'], $entry['ticket'], 'form_001', 'dispatched'), 'user_quota');
$assert('Quota rollback preserves row and payload bytes', $store->get($entry['id']) === $before);
$step('form_001', 'dispatched');
$journal->abort($identity, $entry['id'], $entry['ticket']);
$access->allowed = false;
$step('form_001', 'observed', ['nativeId' => 43]);
$assert('An in-flight observation survives Stop and permission loss without clearing uncertainty', $store->get($entry['id'])['state'] === 'needs_reconciliation');
$access->allowed = true;
$denied('Observed partial result never grants another dispatch', fn () => $step('form_001', 'dispatched'), 'state_conflict');
$attempts = $journal->attempts($identity, $entry['id']);
$assert('Each effect has a signed durable dispatch and observation receipt', count(array_filter($attempts, static fn ($attempt) => str_starts_with($attempt['outcome'], 'effect_'))) === 4);
$clock->value += 601;
$denied('Expired compound actions cannot resume', fn () => $step('form_001', 'observed', ['nativeId' => 43]), 'expired');
// Fixed maximum recipe: every step is durably dispatched and observed in order.
$max = $journal->prepare($identity, new JournalResource('forms', 'collection:personal/checkpoint:' . bin2hex(random_bytes(12)), 'collection:personal'), 'nc_forms_create_form', ['proposal' => $proposal, 'effects' => []], [], [], 600);
$journal->markDispatched($identity, $max['id'], $max['ticket']);
for ($i = 0; $i < 152; $i++) {
	$name = sprintf('form_%03d', $i);
	$journal->checkpointEffect($identity, $max['id'], $max['ticket'], $name, 'dispatched');
	$journal->checkpointEffect($identity, $max['id'], $max['ticket'], $name, 'observed', ['nativeId' => $i + 100]);
}
$saved = $journal->preview($identity, $max['id'])['snapshot']['forward']['effects'];
$assert('All 152 bounded Forms checkpoints survive native PostgreSQL and ICrypto', count($saved) === 152 && $saved[151]['facts']['nativeId'] === 251);
$denied('A 153rd Forms checkpoint cannot extend the approved bounded protocol', fn () => $journal->checkpointEffect($identity, $max['id'], $max['ticket'], 'form_152', 'dispatched'), 'invalid_effect');
$denied('The maximum recipe cannot replay its last dispatched step', fn () => $journal->checkpointEffect($identity, $max['id'], $max['ticket'], 'form_151', 'dispatched'), 'effect_already_used');
$journal->abort($identity, $max['id'], $max['ticket']);

echo json_encode(['layer' => 'real PostgreSQL + native ICrypto; journal only; simulated access resolver; zero native provider effects', 'all_passed' => true, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
