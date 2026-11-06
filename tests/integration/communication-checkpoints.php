<?php

declare(strict_types=1);

// Journal-only acceptance in a labelled fixture; this does not certify native Mail.
if (getenv('FADEN_COMMUNICATIONS_CHECKPOINT') !== 'communications-20261106') {
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

$user = Server::get(IUserManager::class)->get('comms-owner-20261106');
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
		if (!$this->allowed || $identity->userId !== 'comms-owner-20261106' || $resource->provider !== 'mail') {
			throw new JournalException('permission_lost', 403);
		}
	}
};
$registry = new AccessResolverRegistry();
$registry->register('mail', $access);
$store = new SqlJournalStore(Server::get(IDBConnection::class));
$cipher = new JournalCipher(Server::get(ICrypto::class));
$journal = new JournalService($store, $cipher, $registry, $clock);
$identity = new JournalIdentity($user->getUID(), hash('sha256', 'checkpoint-only-' . random_bytes(16)), str_repeat('a', 64));
$resource = new JournalResource('mail', 'account:1/checkpoint:' . bin2hex(random_bytes(12)), 'account:1');
$proposal = ['payload' => ['subject' => 'Immutable synthetic approval', 'body' => 'No native send occurs in this test.']];
$entry = $journal->prepare($identity, $resource, 'nc_mail_send_draft', ['proposal' => $proposal, 'effects' => []], [], [], 600);
$step = static fn (string $name, string $phase, array $facts = []) => $journal->checkpointEffect($identity, $entry['id'], $entry['ticket'], $name, $phase, $facts);
$denied('No effect checkpoint before root dispatch', fn () => $step('stage_outbox', 'dispatched'), 'state_conflict');
$journal->markDispatched($identity, $entry['id'], $entry['ticket']);
$step('stage_outbox', 'dispatched');
$version = $store->get($entry['id'])['version'];
$denied('An uncertain stage cannot be blindly sent twice', fn () => $step('stage_outbox', 'dispatched'), 'effect_already_used');
$assert('Refused replay leaves durable row unchanged', $version === $store->get($entry['id'])['version']);
$step('stage_outbox', 'observed', ['nativeId' => 42, 'revision' => str_repeat('b', 64)]);
$reopened = new JournalService(new SqlJournalStore(Server::get(IDBConnection::class)), $cipher, $registry, $clock);
$snapshot = $reopened->preview($identity, $entry['id'])['snapshot'];
$assert('Restart preserves exact approved payload and partial native ID', JournalCipher::json($snapshot['forward']['proposal']) === JournalCipher::json($proposal) && $snapshot['forward']['effects'][0]['facts']['nativeId'] === 42);
$assert('Progress uses existing encrypted v1 payload', !str_contains($store->get($entry['id'])['payload'], 'Immutable synthetic approval'));
$denied('A different session cannot continue', fn () => $reopened->checkpointEffect(new JournalIdentity($identity->userId, $identity->scopeHash, str_repeat('c', 64)), $entry['id'], $entry['ticket'], 'send_smtp', 'dispatched'), 'claim_conflict');
$denied('A foreign owner cannot continue', fn () => $reopened->checkpointEffect(new JournalIdentity('comms-denied-20261106', $identity->scopeHash, str_repeat('a', 64)), $entry['id'], $entry['ticket'], 'send_smtp', 'dispatched'), 'action_unavailable');
$access->allowed = false;
$denied('Permission loss blocks the second dispatch', fn () => $step('send_smtp', 'dispatched'), 'permission_lost');
$access->allowed = true;
$before = $store->get($entry['id']);
$tiny = new JournalService($store, $cipher, $registry, $clock, new JournalPolicy(maxPayloadBytes: 10));
$denied('Snapshot quota blocks dispatch checkpoint', fn () => $tiny->checkpointEffect($identity, $entry['id'], $entry['ticket'], 'send_smtp', 'dispatched'), 'snapshot_too_large');
$quota = new JournalService($store, $cipher, $registry, $clock, new JournalPolicy(maxUserBytes: 1));
$denied('User quota blocks progress growth', fn () => $quota->checkpointEffect($identity, $entry['id'], $entry['ticket'], 'send_smtp', 'dispatched'), 'user_quota');
$assert('Quota rollback preserves row and payload bytes', $store->get($entry['id']) === $before);
$step('send_smtp', 'dispatched');
$journal->abort($identity, $entry['id'], $entry['ticket']);
$access->allowed = false;
$step('send_smtp', 'observed', ['status' => 12]);
$assert('An in-flight observation survives Stop and permission loss without clearing uncertainty', $store->get($entry['id'])['state'] === 'needs_reconciliation');
$access->allowed = true;
$denied('Observed partial result never grants another dispatch', fn () => $step('send_smtp', 'dispatched'), 'state_conflict');
$attempts = $journal->attempts($identity, $entry['id']);
$assert('Each effect has a signed durable dispatch and observation receipt', count(array_filter($attempts, static fn ($attempt) => str_starts_with($attempt['outcome'], 'effect_'))) === 4);
$clock->value += 601;
$denied('Expired compound actions cannot resume', fn () => $step('send_smtp', 'observed', ['status' => 12]), 'expired');
echo json_encode(['layer' => 'real PostgreSQL + native ICrypto; journal only; simulated access resolver; zero Mail effects', 'all_passed' => true, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
