<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\VerifiedReadback;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;

/** Typed communications executor; each native effect is dispatched at most once. */
final class CommunicationProvider {
	private const KEY = 'fadenstack_agent.communications';
	public const LIMITATION = 'Native last-write-wins: native editors are not locked by our preflight. MIME dates, IDs and encoding can change. Attachments require a verified native byte-preserving round trip; unsafe text normalization requires the native editor. Each step is recorded before dispatch. Stop blocks subsequent steps, not an already accepted send. Partial drafts/outbox entries are retained. SMTP acceptance is not recipient delivery. Native notifications may occur. Audit only; no Undo or Redo.';
	private const TOOLS = ['mail' => ['nc_mail_read', 'nc_mail_save_draft', 'nc_mail_send_draft'], 'talk' => ['nc_talk_read', 'nc_talk_send_message']];
	private ?string $attempt = null;
	private bool $dispatched = false;
	private bool $partial = false;
	public function __construct(
		private CommunicationAuthority $native,
		private Configuration $configuration,
		private IUserSession $users,
		private ISession $session,
		private JournalService $journal,
		private StructuredTickets $tickets,
		private ILockingProvider $locks,
	) {
	}
	public function attemptId(): ?string {
		return $this->attempt;
	}
	public function attemptOutcome(): string {
		return $this->partial ? 'partial' : ($this->dispatched ? 'unknown' : 'not-dispatched');
	}
	private function identity(): JournalIdentity {
		return new JournalIdentity($this->users->getUser()?->getUID() ?? throw new Problem('Sign in to Nextcloud.', 401), JournalIdentity::nativeScope(), hash('sha256', $this->session->getId()));
	}
	private static function digest(mixed $value): string {
		return StructuredProvider::digest($value);
	}
	private function binding(array $resource): string {
		return self::digest([$this->identity()->userId, $this->session->getId(), $this->configuration->get(), $resource['policyVersion']]);
	}
	private static function id(mixed $value): int {
		if (!is_int($value) || $value < 1 || $value > 999999999999999) {
			throw new Problem('A positive native identifier is required.', 400);
		} return $value;
	}
	private function accountId(mixed $value): int {
		if (is_string($value) && preg_match('/^(?:nextcloud:mail:)?([1-9][0-9]{0,14})$/D', $value, $m)) {
			return (int)$m[1];
		}
		return self::id($value);
	}
	private function resource(string $provider, string $collection, array $authority, mixed $snapshot, string $selection = ''): array {
		return ['provider' => $provider, 'collectionId' => $collection, 'resourceId' => 'nextcloud:' . $provider . ':' . rtrim(strtr(base64_encode($collection . "\0" . $selection), '+/', '-_'), '='),
			'revision' => self::digest([$authority['authorityRevision'], $snapshot]), 'policyVersion' => $authority['policy']['policyVersion'],
			'sourceClass' => $authority['policy']['sourceClass'], 'audience' => $provider === 'mail' ? 'unknown' : (($authority['audience'] ?? '') === 'public-or-discoverable' ? 'public' : 'shared'), 'filename' => $selection];
	}
	public function capabilities(): array {
		$result = [];
		foreach (['mail', 'talk'] as $provider) {
			try {
				$policy = $this->native->policy($provider);
				$tools = [];
				$values = [];
				foreach ($policy['collections'] as $id) {
					try {
						$values[] = $provider === 'mail' ? $this->native->mailAccount((int)$id) : $this->native->talkRoom($id);
					} catch (Problem $error) {
						if (!in_array($error->status, [403, 404], true)) {
							throw $error;
						}
					}
				}
				foreach ($values as $value) {
					$tools[] = self::TOOLS[$provider][0];
					if ($policy['writesEnabled'] && $this->configuration->folderDispatchSupported() && ($provider === 'mail' || $value['canWrite'])) {
						$tools = array_merge($tools, array_slice(self::TOOLS[$provider], 1));
					}
				}
				$tools = array_values(array_intersect(array_unique($tools), $policy['processing']['tools']));
				if ($tools) {
					$result[] = ['provider' => $provider, 'apiVersion' => $provider === 'mail' ? 'mail-5.13-api' : 'talk-25-v4-v1', 'policyVersion' => $policy['policyVersion'], 'enabled' => true,
						'tools' => $tools, 'conflict' => count($tools) > 1 ? 'native' : 'read-only', 'history' => 'audit-only', 'refresh' => $provider === 'talk' ? 'native-event' : 'child-reload', 'canRead' => true, 'canWrite' => count($tools) > 1];
				}
			} catch (Problem $error) {
				if (!in_array($error->status, [403, 404], true)) {
					throw $error;
				}
			}
		}
		return $result;
	}
	private function message(int $id, mixed $account = null, bool $audit = false): array {
		if ($account !== null) {
			return $this->native->mailMessage($this->accountId($account), $id, $audit);
		}
		foreach ($this->native->policy('mail', $audit)['collections'] as $accountId) {
			try {
				return $this->native->mailMessage((int)$accountId, $id, $audit);
			} catch (Problem $error) {
				if (!in_array($error->status, [403, 404], true)) {
					throw $error;
				}
			}
		}
		throw new Problem('An authorized native Mail message is required.', 403);
	}
	public function read(string $provider, array $args): array {
		if ($provider === 'talk') {
			if (array_diff(array_keys($args), ['roomToken']) || !is_string($args['roomToken'] ?? null)) {
				throw new Problem('A native Talk room is required.', 400);
			}
			$value = $this->native->talkRoom($args['roomToken']);
			$history = $this->native->talkRequest($value['policy'], 'history', $args['roomToken']);
			if (!array_is_list($history) || count($history) > 50) {
				throw new Problem('Narrow the native Talk history.', 413);
			}
			foreach ($history as $message) {
				if (($message['token'] ?? '') !== $args['roomToken'] || !is_int($message['id'] ?? null)) {
					throw new Problem('Unverified native Talk message membership.', 503);
				}
			}
			$snapshot = ['room' => $value['room'], 'participants' => $value['participants'], 'history' => $history, 'audience' => $value['audience']];
			return [...$snapshot, 'resource' => $this->resource('talk', $args['roomToken'], $value, $snapshot, '{}')];
		}
		if ($provider !== 'mail' || array_diff(array_keys($args), ['accountId', 'messageId', 'mailboxId', 'query'])) {
			throw new Problem('Unsupported communications read.', 400);
		}
		$query = $args['query'] ?? '';
		if (!is_string($query) || mb_strlen($query) > 200 || preg_match('/[\x00-\x1f\x7f]/', $query)) {
			throw new Problem('Use bounded Mail search text.', 400);
		}
		if (isset($args['messageId'])) {
			$value = $this->message(self::id($args['messageId']), $args['accountId'] ?? null);
			$accountId = $value['account']['accountId'];
			$mime = $value['mime'];
			foreach ($mime['attachments'] as &$part) {
				unset($part['native']);
			} unset($part);
			$snapshot = ['account' => $value['account'], 'message' => array_intersect_key($value['message'], array_flip(['databaseId', 'mailboxId', 'subject', 'from', 'to', 'cc', 'bcc', 'flags', 'dateInt'])), 'content' => $mime];
			$selection = json_encode($args, JSON_THROW_ON_ERROR);
		} else {
			$accounts = isset($args['accountId']) ? [$this->native->mailAccount($this->accountId($args['accountId']))] : $this->native->mailAccounts();
			if (!$accounts) {
				throw new Problem('No configured native Mail account is accessible.', 403);
			}
			$accountId = isset($args['accountId']) ? $this->accountId($args['accountId']) : $accounts[0]['account']['accountId'];
			$value = $this->native->mailboxes($accountId);
			$box = isset($args['mailboxId']) ? self::id($args['mailboxId']) : (array_values(array_filter($value['mailboxes'], static fn ($b) => $b['specialRole'] === 'inbox'))[0]['databaseId'] ?? 0);
			$messages = $box ? $this->native->mailMessages($value, $box) : [];
			$messages = array_values(array_filter($messages, static fn ($m) => $query === '' || mb_stripos(($m['subject'] ?? '') . ' ' . ($m['previewText'] ?? ''), $query) !== false));
			$messages = array_map(static fn ($m) => array_intersect_key($m, array_flip(['databaseId', 'mailboxId', 'subject', 'from', 'to', 'cc', 'bcc', 'flags', 'dateInt', 'previewText'])), $messages);
			$snapshot = ['accounts' => array_column($accounts, 'account'), 'account' => $value['account'], 'mailboxes' => $value['mailboxes'], 'messages' => $messages, 'partial' => true, 'searchWindow' => 'latest 20 messages in the selected mailbox'];
			$selection = json_encode($args ?: new \stdClass(), JSON_THROW_ON_ERROR);
		}
		return [...$snapshot, 'resource' => $this->resource('mail', (string)$accountId, $value, $snapshot, $selection)];
	}
	private static function addresses(mixed $value, bool $required = false): array {
		if (!is_array($value) || !array_is_list($value) || count($value) > 50 || ($required && !$value)) {
			throw new Problem('Use bounded native recipients.', 400);
		}
		return array_map(static function ($address): array {
			$email = is_array($address) ? ($address['email'] ?? '') : $address;
			if (!is_string($email) || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\x00]/', $email)) {
				throw new Problem('Use a valid recipient email address.', 400);
			}
			$label = is_array($address) ? ($address['label'] ?? $email) : $email;
			if (!is_string($label) || strlen($label) > 255 || preg_match('/[\r\n\x00]/', $label)) {
				throw new Problem('Use a bounded recipient label.', 400);
			}
			return ['label' => $label, 'email' => $email];
		}, $value);
	}
	private function movement(array $policy, string $audience): void {
		if (!in_array($audience, $policy['processing']['destinationAudiences'], true)
			|| !in_array(['source' => $policy['sourceClass'], 'destination' => $audience], $policy['processing']['movements'], true)) {
			throw new Problem('This native audience is outside the configured data movement policy.', 403);
		}
	}
	private function attachment(int $accountId, string $reference): array {
		if (!preg_match('/^mail-attachment:([1-9][0-9]{0,14}):([0-9]+(?:\.[0-9]+)*)$/D', $reference, $m)) {
			throw new Problem('Use an attachment reference returned by Mail read.', 400);
		}
		$value = $this->native->mailMessage($accountId, (int)$m[1]);
		foreach ($value['mime']['attachments'] as $part) {
			if ($part['reference'] === $reference && !$part['inline']) {
				return $part;
			}
		}
		throw new Problem('The native attachment is unavailable or needs the native editor.', 403);
	}
	private function plan(string $toolName, array $args): array {
		$provider = str_starts_with($toolName, 'nc_mail_') ? 'mail' : 'talk';
		if (!in_array($toolName, array_slice(self::TOOLS[$provider], 1), true) || !$this->configuration->folderDispatchSupported()) {
			throw new Problem('Unsupported native communications operation.', 403);
		}
		$policy = $this->native->policy($provider);
		if (!$policy['writesEnabled'] || !in_array($toolName, $policy['processing']['tools'], true)) {
			throw new Problem('This native write is disabled.', 403);
		}
		if ($provider === 'talk') {
			if (array_diff(array_keys($args), ['roomToken', 'message', 'replyTo']) || !is_string($args['roomToken'] ?? null) || !is_string($args['message'] ?? null)
				|| trim($args['message']) === '' || mb_strlen($args['message']) > 4000) {
				throw new Problem('Use a bounded native Talk message.', 400);
			}
			$value = $this->native->talkRoom($args['roomToken']);
			if (!$value['canWrite']) {
				throw new Problem('Current native Talk write permission is required.', 403);
			}
			$this->movement($policy, $value['audience'] === 'public-or-discoverable' ? 'public' : 'shared');
			$parent = null;
			if (isset($args['replyTo'])) {
				$id = self::id($args['replyTo']);
				$history = $this->native->talkRequest($policy, 'history', $args['roomToken']);
				foreach ($history as $candidate) {
					if (($candidate['id'] ?? null) === $id && ($candidate['token'] ?? '') === $args['roomToken'] && ($candidate['isReplyable'] ?? false) === true) {
						$parent = $candidate;
					}
				}
				if (!$parent) {
					throw new Problem('Read a replyable parent in this room before replying.', 403);
				}
			}
			$payload = ['message' => trim($args['message']), 'silent' => false, ...(isset($args['replyTo']) ? ['replyTo' => $args['replyTo']] : [])];
			$resource = $this->resource('talk', $args['roomToken'], $value, $value['room']);
			return ['provider' => 'talk', 'toolName' => $toolName, 'args' => $args, 'collectionId' => $args['roomToken'], 'target' => 'Talk: ' . $value['room']['displayName'], 'payload' => $payload,
				'before' => $parent, 'authority' => $value['authorityRevision'], 'resource' => $resource, 'audience' => $value['participants'], 'steps' => ['send_talk'],
				'display' => [['label' => 'Room and audience', 'after' => $value['room']['displayName'] . "\n" . $value['audience'] . "\n" . implode("\n", array_map(static fn ($member) => $member['displayName'] . ' (' . $member['actorType'] . ':' . $member['actorId'] . ')', $value['participants']))], ['label' => 'Message', 'after' => $payload['message']], ['label' => 'Reply parent', 'after' => $parent ? '#' . $parent['id'] . ': ' . $parent['message'] : 'New message']], 'history' => 'audit-only', 'limitation' => self::LIMITATION];
		}
		$send = $toolName === 'nc_mail_send_draft';
		// Mailbox delegation and external recipient access are not a complete ACL
		// inventory. Never mislabel an SMTP audience or shared mailbox private.
		$this->movement($policy, 'unknown');
		$allowed = $send ? ['draftId', 'expectedRevision', 'accountRef'] : ['accountRef', 'draftId', 'expectedRevision', 'subject', 'body', 'recipients', 'cc', 'bcc', 'attachments'];
		if (array_diff(array_keys($args), $allowed)) {
			throw new Problem('Unexpected native Mail arguments.', 400);
		}
		$before = isset($args['draftId']) ? $this->message(self::id($args['draftId']), $args['accountRef'] ?? null) : null;
		if ($send && !$before) {
			throw new Problem('Read a persisted native draft before sending.', 400);
		}
		if ($before && $before['mime']['unsupportedHeaders']) {
			throw new Problem('This draft has additional reply or receipt headers. Preserve them in the native Mail editor.', 403);
		}
		if ($before && (($before['message']['flags']['draft'] ?? false) !== true || !is_string($args['expectedRevision'] ?? null) || !hash_equals($before['mime']['revision'], $args['expectedRevision']))) {
			throw new Problem('The native draft changed. Read it again.', 409);
		}
		$accountId = $before ? $before['account']['accountId'] : $this->accountId($args['accountRef'] ?? null);
		$value = $this->native->mailboxes($accountId);
		if ($before && (self::addresses($before['message']['from'] ?? []) !== self::addresses([['email' => $value['account']['emailAddress'], 'label' => $value['account']['name']]]))) {
			throw new Problem('This draft uses an alias; review it in the native Mail editor.', 403);
		}
		$subject = $send ? $before['mime']['subject'] : ($args['subject'] ?? null);
		$body = $send ? $before['mime']['bodyPlain'] : ($args['body'] ?? null);
		if (!is_string($subject) || trim($subject) === '' || mb_strlen($subject) > 255 || preg_match('/[\r\n\x00]/', $subject) || (!$send && (!is_string($body) || strlen($body) > 65536))) {
			throw new Problem('Use a bounded Mail subject and body.', 400);
		}
		$parts = $before['mime']['attachments'] ?? [];
		if (!$send && isset($args['attachments'])) {
			if (!is_array($args['attachments']) || !array_is_list($args['attachments']) || count($args['attachments']) > 20) {
				throw new Problem('Use at most 20 native attachments.', 400);
			}
			$parts = array_map(fn ($ref) => is_string($ref) ? $this->attachment($accountId, $ref) : throw new Problem('Use native attachment references.', 400), $args['attachments']);
		}
		foreach ($parts as $part) {
			if ($part['inline']) {
				throw new Problem('Inline MIME attachments require the native Mail editor.', 403);
			}
			preg_match('/^mail-attachment:([1-9][0-9]*):([0-9.]+)$/D', $part['reference'], $ref);
			$bytes = $this->native->attachmentBytes($policy, (int)$ref[1], $ref[2]);
			$final = str_starts_with($part['mime'], 'text/') ? str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $bytes)) : $bytes;
			if (!hash_equals($part['sha256'], hash('sha256', $final))) {
				throw new Problem('Native Mail would change these attachment bytes. Use the native editor or a canonical attachment copy. No draft or send was dispatched.', 403);
			}
		}
		$payload = ['accountId' => $accountId, 'subject' => trim($subject), 'bodyPlain' => MailMime::text($body), 'bodyHtml' => $send ? $before['mime']['bodyHtml'] : null,
			'editorBody' => null, 'isHtml' => $send && $before['mime']['bodyHtml'] !== null, 'smimeSign' => false, 'smimeEncrypt' => false, 'sendAt' => null, 'requestMdn' => false, 'isPgpMime' => false,
			'to' => self::addresses($send ? $before['message']['to'] ?? [] : $args['recipients'] ?? [], true),
			'cc' => self::addresses($send ? $before['message']['cc'] ?? [] : $args['cc'] ?? $before['message']['cc'] ?? []),
			'bcc' => self::addresses($send ? $before['message']['bcc'] ?? [] : $args['bcc'] ?? $before['message']['bcc'] ?? []),
			'attachments' => array_column($parts, 'native'), 'inReplyToMessageId' => $before['mime']['inReplyToMessageId'] ?? null, 'isAiGenerated' => $send ? $before['mime']['isAiGenerated'] : true];
		if (count($payload['to']) + count($payload['cc']) + count($payload['bcc']) > 50) {
			throw new Problem('Use at most 50 total recipients.', 413);
		}
		$boxId = self::id($value['account'][$send ? 'sentMailboxId' : 'draftsMailboxId'] ?? null);
		$existing = $this->native->mailMessages($value, $boxId);
		$attachmentFacts = array_map(static fn ($p) => ['fileName' => $p['fileName'], 'mime' => $p['mime'], 'size' => $p['nativeSize'], 'sha256' => $p['nativeSha256']], $parts);
		$resource = $this->resource('mail', (string)$accountId, $value, $before ? $before['mime']['revision'] : $value['account']);
		return ['provider' => 'mail', 'toolName' => $toolName, 'args' => $args, 'collectionId' => (string)$accountId, 'target' => ($send ? 'Send: ' : 'Draft: ') . trim($subject), 'payload' => $payload,
			'before' => $before ? ['messageId' => $args['draftId'], 'revision' => $before['mime']['revision']] : null, 'authority' => $value['authorityRevision'], 'resource' => $resource,
			'account' => $value['account'], 'boxId' => $boxId, 'existingIds' => array_column($existing, 'databaseId'), 'attachmentFacts' => $attachmentFacts,
			'steps' => [$send ? 'stage_outbox' : 'save_local_draft', $send ? 'send_smtp' : 'persist_imap', ...($before ? ['retire_source'] : [])],
			'display' => [['label' => 'Account / From', 'after' => $value['account']['name'] . ' <' . $value['account']['emailAddress'] . '> (account ' . $accountId . ')'],
				['label' => 'To / Cc / Bcc', 'after' => implode("\n", array_map(static fn ($key) => ucfirst($key) . ': ' . (implode(', ', array_map(static fn ($address) => $address['label'] === $address['email'] ? $address['email'] : $address['label'] . ' <' . $address['email'] . '>', $payload[$key])) ?: 'None'), ['to', 'cc', 'bcc']))],
				['label' => 'Subject / body', 'after' => $payload['subject'] . "\n\n" . ($payload['bodyPlain'] ?? '') . ($payload['bodyHtml'] !== null ? "\n\nHTML source (inert):\n" . $payload['bodyHtml'] : '')],
				['label' => 'Attachments', 'after' => implode("\n", array_map(static fn ($part) => $part['fileName'] . ' · ' . $part['mime'] . ' · ' . $part['size'] . ' bytes · SHA-256 ' . $part['sha256'], $attachmentFacts)) ?: 'None'],
				['label' => 'Original draft', 'after' => $before ? 'Retire only after the replacement or SMTP acceptance is independently verified; Stop can retain both.' : 'Create a new draft.']], 'history' => 'audit-only', 'limitation' => self::LIMITATION];
	}
	private function state(): array {
		$state = $this->session->get(self::KEY);
		return is_array($state) ? $state : [];
	}
	private function view(array $p): array {
		return array_intersect_key($p, array_flip(['id', 'digest', 'toolName', 'provider', 'target', 'before', 'payload', 'resource', 'display', 'limitation', 'history', 'expires']));
	}
	public function propose(string $toolName, array $args, string $requestId): array {
		$identity = $this->identity();
		$this->tickets->start($identity, $requestId);
		try {
			$p = $this->plan($toolName, $args);
			$p['id'] = $requestId;
			$p['expires'] = time() + 600;
			$p['binding'] = $this->binding($p['resource']);
			$p['approved'] = false;
			$p['digest'] = self::digest($p);
			$state = array_filter($this->state(), static fn ($v) => $v['expires'] > time());
			if (count($state) >= 20) {
				throw new Problem('Too many pending communications proposals.', 429);
			}
			$state[$requestId] = $p;
			$this->session->set(self::KEY, $state);
			if (!$this->tickets->move($identity, $requestId, ['preparing'], 'prepared')) {
				throw new Problem('This native proposal was cancelled.', 410);
			}
			return $this->view($p);
		} catch (\Throwable $error) {
			$this->tickets->cancel($identity, $requestId);
			throw $error;
		}
	}
	private function load(string $id, string $digest, array $states = ['prepared', 'approved']): array {
		if (!preg_match('/^[a-f0-9]{48}$/D', $id) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
			throw new Problem('Invalid native proposal.', 400);
		}
		$p = $this->state()[$id] ?? null;
		if (!$p || $p['expires'] <= time() || !hash_equals($p['digest'], $digest) || !in_array($this->tickets->state($this->identity(), $id), $states, true) || !hash_equals($p['binding'], $this->binding($p['resource']))) {
			throw new Problem('The proposal expired, was cancelled or was consumed.', 410);
		}
		return $p;
	}
	private function validate(array $p): void {
		$now = $this->plan($p['toolName'], $p['args']);
		// The latest Sent/Drafts window is observation metadata, not the approved effect.
		unset($now['existingIds']);
		$prior = array_intersect_key($p, $now);
		if (!hash_equals(self::digest($prior), self::digest($now))) {
			throw new Problem('The native draft, audience, payload or policy changed.', 409);
		}
	}
	public function approve(string $id, string $digest): array {
		$p = $this->load($id, $digest);
		$this->validate($p);
		if ($p['approved']) {
			return ['approved' => true];
		}
		if (!$this->tickets->move($this->identity(), $id, ['prepared'], 'approving')) {
			throw new Problem('This approval was consumed or cancelled.', 410);
		}
		$reference = ($p['provider'] === 'mail' ? 'account:' : 'room:') . $p['collectionId'] . '/action:' . $id;
		$entry = $this->journal->prepare($this->identity(), new JournalResource($p['provider'], $reference, ($p['provider'] === 'mail' ? 'account:' : 'room:') . $p['collectionId']), $p['toolName'], ['proposal' => $p, 'effects' => []], [], []);
		$p['actionId'] = $entry['id'];
		$p['ticket'] = $entry['ticket'];
		$p['approved'] = true;
		$state = $this->state();
		$state[$id] = $p;
		$this->session->set(self::KEY, $state);
		if (!$this->tickets->move($this->identity(), $id, ['approving'], 'approved')) {
			$this->journal->abort($this->identity(), $p['actionId'], $p['ticket']);
			throw new Problem('Approval was cancelled.', 410);
		}
		return ['approved' => true];
	}
	public function cancel(string $id): array {
		$identity = $this->identity();
		if ($this->tickets->state($identity, $id) === null) {
			return ['cancelled' => $this->tickets->cancel($identity, $id)];
		}
		$stopped = $this->tickets->move($identity, $id, ['preparing', 'prepared', 'approving', 'approved', 'awaiting_step', 'dispatching', 'cancelled'], 'cancelled');
		$p = $this->state()[$id] ?? null;
		if ($stopped && isset($p['ticket'])) {
			try {
				$this->journal->abort($identity, $p['actionId'], $p['ticket']);
			} catch (\Throwable) { /* retain durable uncertainty */
			}
		}
		return ['cancelled' => $stopped, 'message' => 'Further dispatch is stopped. An in-flight native effect cannot be cancelled by this control.'];
	}
	public function commit(string $id, string $digest, int $expectedStep): array {
		if ($expectedStep < 0 || $expectedStep > 2) {
			throw new Problem('A bounded native step is required.', 400);
		}
		$p = $this->load($id, $digest, ['prepared', 'approved', 'awaiting_step']);
		if (!$p['approved']) {
			throw new Problem('Individual approval is required.', 403);
		}
		$this->attempt = $p['actionId'];
		$identity = $this->identity();
		$lock = hash('sha256', 'fadenstack:communications:' . $p['provider'] . ':' . $p['collectionId']);
		$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		$replayedStep = false;
		try {
			$first = $this->tickets->state($identity, $id) === 'approved';
			$this->partial = !$first;
			$effects = $first || $p['provider'] === 'talk' ? [] : $this->journal->preview($identity, $p['actionId'])['snapshot']['forward']['effects'];
			$index = count($effects);
			if ($index !== $expectedStep) {
				$replayedStep = true;
				throw new Problem('This native step was already consumed or is out of order. Inspect without repeating it.', 409);
			}
			if ($first) {
				$this->validate($p);
			}
			$value = $p['provider'] === 'mail' ? $this->native->mailboxes((int)$p['collectionId']) : $this->native->talkRoom($p['collectionId']);
			if (!$value['policy']['writesEnabled'] || !hash_equals($value['authorityRevision'], $p['authority'])) {
				throw new Problem('Native access, audience or configuration changed.', 409);
			}
			if (!$this->tickets->move($identity, $id, [$first ? 'approved' : 'awaiting_step'], 'dispatching')) {
				throw new Problem('This native step was cancelled or dispatched.', 410);
			}
			if ($first) {
				$this->journal->markDispatched($identity, $p['actionId'], $p['ticket']);
			}
			if ($p['provider'] === 'talk') {
				$this->dispatched = true;
				$result = $this->native->talkRequest($value['policy'], 'send', $p['collectionId'], [...$p['payload'], 'referenceId' => 'faden-' . $p['id']]);
				$history = $this->native->talkRequest($value['policy'], 'history', $p['collectionId']);
				$found = array_values(array_filter($history, static fn ($m) => ($m['id'] ?? null) === ($result['id'] ?? null) && ($m['referenceId'] ?? '') === 'faden-' . $p['id'] && ($m['token'] ?? '') === $p['collectionId'] && ($m['message'] ?? '') === $p['payload']['message']));
				if (count($found) !== 1 || ($found[0]['actorId'] ?? '') !== $identity->userId || (isset($p['payload']['replyTo']) && ($found[0]['parent']['id'] ?? null) !== $p['payload']['replyTo'])) {
					throw new Problem('The native Talk result is unverified. Inspect without resending.', 503);
				}
				return $this->finish($p, ['messageId' => $result['id']], self::digest($found[0]));
			}
			$step = $p['steps'][$index] ?? throw new Problem('The native steps have already been consumed.', 410);
			if ($index > 0 && $effects[$index - 1]['phase'] !== 'observed') {
				throw new Problem('An earlier effect is uncertain. Inspect it without retrying.', 409);
			}
			$send = $p['toolName'] === 'nc_mail_send_draft';
			if ($index === 1 && $send) {
				$outbox = $this->native->mailRequest($value['policy'], 'outbox', $effects[0]['facts']['nativeId']);
				$this->verifyOutbox($p, $outbox);
				if (!hash_equals($effects[0]['facts']['revision'], self::digest($outbox))) {
					throw new Problem('The native outbox changed after staging.', 409);
				}
			}
			if ($index === 2) {
				$current = $this->native->mailMessage((int)$p['collectionId'], $p['args']['draftId']);
				if (!hash_equals($current['mime']['revision'], $p['before']['revision'])) {
					throw new Problem('The original draft changed. Its verified replacement/send is retained; the edited draft will not be removed.', 409);
				}
			}
			$this->journal->checkpointEffect($identity, $p['actionId'], $p['ticket'], $step, 'dispatched');
			$this->dispatched = true;
			if ($index === 0) {
				$result = $this->native->mailRequest($value['policy'], $send ? 'stage_outbox' : 'create_draft', 0, $p['payload']);
				$nativeId = self::id($result['id'] ?? null);
				if (($result['accountId'] ?? null) !== (int)$p['collectionId'] || ($result['sendAt'] ?? null) !== null) {
					throw new Problem('The native staged message could not be verified.', 503);
				}
				$outboxRevision = null;
				if ($send) {
					$outbox = $this->native->mailRequest($value['policy'], 'outbox', $nativeId);
					$this->verifyOutbox($p, $outbox);
					$outboxRevision = self::digest($outbox);
				}
				$this->journal->checkpointEffect($identity, $p['actionId'], $p['ticket'], $step, 'observed', ['nativeId' => $nativeId, ...($outboxRevision ? ['revision' => $outboxRevision] : [])]);
				return $this->awaitStep($p, $nativeId, $step);
			}
			if ($index === 1) {
				$nativeId = $effects[0]['facts']['nativeId'];
				$this->native->mailRequest($value['policy'], $send ? 'send_outbox' : 'persist_draft', $nativeId);
				$found = $this->findResult($p, $value);
				$this->journal->checkpointEffect($identity, $p['actionId'], $p['ticket'], $step, 'observed', ['nativeId' => $found['id'], 'revision' => $found['revision'], ...($send ? ['status' => 202] : [])]);
				if (count($p['steps']) > 2) {
					return $this->awaitStep($p, $found['id'], $step);
				}
				return $this->finish($p, ['draftId' => $found['id'], 'revision' => $found['revision']], $found['revision']);
			}
			$this->native->mailRequest($value['policy'], 'retire_draft', $p['args']['draftId']);
			$remaining = $this->native->mailMessages($value, $current['message']['mailboxId']);
			if (array_filter($remaining, static fn ($m) => $m['databaseId'] === $p['args']['draftId'] && !($m['flags']['deleted'] ?? false))) {
				throw new Problem('Original draft retirement could not be verified.', 503);
			}
			$this->journal->checkpointEffect($identity, $p['actionId'], $p['ticket'], $step, 'observed', ['nativeId' => $p['args']['draftId']]);
			$found = $effects[1]['facts'];
			return $this->finish($p, [$send ? 'messageId' : 'draftId' => $found['nativeId'], 'revision' => $found['revision'], 'originalDraftRetired' => true, ...($send ? ['smtp' => 'accepted', 'recipientDelivery' => 'unknown'] : [])], $found['revision']);
		} catch (\Throwable $error) {
			if ($replayedStep) {
				throw $error;
			}
			try {
				if ($this->dispatched || !$first) {
					$this->journal->finish($identity, $p['actionId'], $p['ticket'], 'unknown');
				} else {
					$this->journal->abort($identity, $p['actionId'], $p['ticket']);
				}
			} catch (\Throwable) { /* durable uncertainty remains */
			}
			$this->tickets->move($identity, $id, ['approved', 'dispatching', 'awaiting_step'], 'finished');
			throw $error;
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
	private function verifyOutbox(array $p, mixed $message): void {
		if (!is_array($message) || ($message['accountId'] ?? null) !== (int)$p['collectionId'] || ($message['sendAt'] ?? null) !== null || !in_array($message['status'] ?? 0, [0], true)) {
			throw new Problem('The unscheduled outbox changed or may already have been sent.', 409);
		}
		foreach (['subject', 'bodyPlain', 'bodyHtml', 'isHtml', 'isAiGenerated', 'smimeSign', 'smimeEncrypt', 'isPgpMime', 'requestMdn', 'inReplyToMessageId'] as $key) {
			if (($message[$key] ?? null) !== $p['payload'][$key]) {
				throw new Problem('The staged native message differs from the approved payload.', 409);
			}
		}
		foreach (['to', 'cc', 'bcc'] as $key) {
			if (self::addresses($message[$key] ?? []) !== $p['payload'][$key]) {
				throw new Problem('The staged recipients changed.', 409);
			}
		}
		if (count($message['attachments'] ?? []) !== count($p['attachmentFacts'])) {
			throw new Problem('The staged attachments changed.', 409);
		}
		foreach ($message['attachments'] ?? [] as $index => $part) {
			if (($part['fileName'] ?? null) !== $p['attachmentFacts'][$index]['fileName'] || ($part['mimeType'] ?? '') !== $p['attachmentFacts'][$index]['mime']) {
				throw new Problem('The staged attachment identities changed.', 409);
			}
		}
	}
	private function findResult(array $p, array $value): array {
		$candidates = [];
		foreach ($this->native->mailMessages($value, $p['boxId']) as $message) {
			if (in_array($message['databaseId'], $p['existingIds'], true) || ($message['subject'] ?? '') !== $p['payload']['subject']) {
				continue;
			}
			$actual = $this->native->mailMessage((int)$p['collectionId'], $message['databaseId']);
			$mime = $actual['mime'];
			if ($mime['bodyPlain'] !== $p['payload']['bodyPlain'] || $mime['bodyHtml'] !== $p['payload']['bodyHtml'] || $mime['isAiGenerated'] !== $p['payload']['isAiGenerated']) {
				continue;
			}
			$match = true;
			foreach (['to', 'cc', 'bcc'] as $key) {
				if (self::addresses($actual['message'][$key] ?? []) !== $p['payload'][$key]) {
					$match = false;
				}
			}
			$facts = array_map(static fn ($part) => array_intersect_key($part, array_flip(['fileName', 'mime', 'size', 'sha256'])), $mime['attachments']);
			if ($match && self::digest($facts) === self::digest($p['attachmentFacts'])) {
				$candidates[] = ['id' => $message['databaseId'], 'revision' => $mime['revision']];
			}
		}
		if (count($candidates) !== 1) {
			throw new Problem('The native effect is ambiguous. Inspect the retained partial result; never resend automatically.', 503);
		}
		return $candidates[0];
	}
	private function awaitStep(array $p, int $nativeId, string $step): array {
		$pending = $this->tickets->move($this->identity(), $p['id'], ['dispatching'], 'awaiting_step');
		return ['outcome' => $pending ? 'running' : 'partial', 'continue' => $pending, 'actionId' => $p['actionId'], 'provider' => $p['provider'], 'target' => $p['target'], 'nativeId' => $nativeId, 'step' => $step, 'history' => 'audit-only', 'limitation' => self::LIMITATION];
	}
	private function finish(array $p, array $result, string $revision): array {
		$this->journal->finish($this->identity(), $p['actionId'], $p['ticket'], 'verified', new VerifiedReadback($revision));
		$this->tickets->move($this->identity(), $p['id'], ['dispatching'], 'finished');
		$state = $this->state();
		unset($state[$p['id']]);
		$this->session->set(self::KEY, $state);
		return ['outcome' => 'verified', 'continue' => false, 'actionId' => $p['actionId'], 'provider' => $p['provider'], 'collectionId' => $p['collectionId'], 'mailboxId' => $p['boxId'] ?? null, 'target' => $p['target'], 'result' => $result, 'history' => 'audit-only', 'limitation' => self::LIMITATION];
	}
	public function authorizeResource(JournalResource $resource, bool $write = false): array {
		$prefix = $resource->provider === 'mail' ? 'account:' : 'room:';
		if (!in_array($resource->provider, ['mail', 'talk'], true) || !str_starts_with($resource->authorityReference, $prefix)
			|| !preg_match('/^' . preg_quote($resource->authorityReference, '/') . '\/action:[a-f0-9]{48}$/D', $resource->reference)) {
			throw new Problem('Unavailable native action.', 404);
		}
		$id = substr($resource->authorityReference, strlen($prefix));
		$value = $resource->provider === 'mail' ? $this->native->mailAccount($this->accountId($id), !$write) : $this->native->talkRoom($id, !$write);
		if ($write && (!$value['policy']['writesEnabled'] || ($resource->provider === 'talk' && !$value['canWrite']))) {
			throw new Problem('Native writes are disabled.', 403);
		}
		return $value;
	}
	public function actions(): array {
		return array_map(fn ($entry) => $this->auditItem($entry), $this->journal->list($this->identity(), null, ['mail', 'talk'], 50));
	}
	private function auditItem(array $entry): array {
		$r = JournalResource::fromArray($entry['resource']);
		$value = $this->authorizeResource($r);
		$name = $r->provider === 'mail' ? $value['account']['name'] : $value['room']['displayName'];
		return ['id' => $entry['id'], 'provider' => $r->provider, 'name' => $name, 'target' => ucfirst($r->provider) . ' ' . $name, 'state' => $entry['state'], 'eligible' => 'none', 'auditOnly' => true, 'version' => $entry['version'], 'createdAt' => $entry['createdAt']];
	}
	public function inspect(string $id): array {
		$entry = $this->journal->describe($this->identity(), $id);
		$item = $this->auditItem($entry);
		$effects = $entry['snapshotAvailable'] ? ($this->journal->preview($this->identity(), $id)['snapshot']['forward']['effects'] ?? []) : [];
		return ['action' => $item, 'observed' => 'recorded-attempt', 'journalState' => $entry['state'], 'outcome' => $entry['state'] === 'applied' ? 'verified_committed' : 'unknown',
			'effects' => $effects, 'repeatedDispatch' => false, 'message' => 'Recorded attempts and known native identifiers only. Unknown stages are never resumed or resent by inspection. Sending has no Undo or recipient-delivery guarantee.'];
	}
}
