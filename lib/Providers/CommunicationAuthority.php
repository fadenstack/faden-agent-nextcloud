<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;
use OCP\App\IAppManager;
use OCP\IUserSession;

/** Native public API authority. Never trusts model account/room membership claims. */
final class CommunicationAuthority {
	/** Request-local only. Native write endpoints still recheck the caller. */
	private array $accountReads = [];
	private array $mailboxReads = [];
	public function __construct(
		private NativeApiBridge $bridge,
		private ProviderPolicy $policies,
		private IAppManager $apps,
		private IUserSession $users,
	) {
	}

	public function policy(string $provider, bool $audit = false): array {
		$policy = $this->policies->communication($provider, $audit);
		$this->policies->assertDisclosure($policy);
		[$app, $version] = $provider === 'mail' ? ['mail', '5.13.1'] : ['spreed', '25.0.7'];
		if (!$this->apps->isEnabledForUser($app, $this->users->getUser()) || $this->apps->getAppVersion($app) !== $version) {
			throw new Problem('This native communications app version is not enabled and tested.', 403);
		}
		return $policy;
	}

	private function decode(array $response): array|string {
		if ($response['status'] === 304 && $response['body'] === '') {
			return [];
		}
		try {
			$data = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			throw new Problem('Malformed native communications response.', 503);
		}
		if (!is_array($data)) {
			throw new Problem('Malformed native communications response.', 503);
		}
		if (isset($data['ocs'])) {
			if (($data['ocs']['meta']['status'] ?? '') !== 'ok' || !in_array($data['ocs']['meta']['statuscode'] ?? null, [100, 200, 201, 202], true)
				|| !array_key_exists('data', $data['ocs'])) {
				throw new Problem('The native OCS communications request was refused.', 503);
			}
			$data = $data['ocs']['data'];
		} elseif (array_key_exists('status', $data) && is_string($data['status'])) {
			if ($data['status'] !== 'success' || !array_key_exists('data', $data)) {
				throw new Problem('The native Mail request was refused.', 503);
			}
			$data = $data['data'];
		}
		if (!is_array($data) && !is_string($data)) {
			throw new Problem('Unverified native communications result.', 503);
		}
		return $data;
	}

	public function mailRequest(array $policy, string $operation, int $id = 0, array $payload = []): array|string {
		try {
			$response = $this->bridge->send(NativeRequest::mail($policy['actor'], $operation, $id, $payload));
		} catch (Problem $error) {
			if ($error->status === 429) {
				throw new Problem('Native Mail rate limit reached for ' . $operation . '. No automatic retry was made.', 429);
			} throw $error;
		}
		if ($operation === 'retire_draft' && in_array(trim($response['body']), ['', 'null'], true)) {
			return [];
		}
		return $this->decode($response);
	}

	public function mailMessage(int $accountId, int $messageId, bool $audit = false): array {
		$value = $this->mailEnvelope($accountId, $messageId, $audit);
		$raw = $this->mailRequest($value['policy'], 'raw', $messageId);
		if (!is_string($raw)) {
			throw new Problem('Unverified native MIME source.', 503);
		}
		$mime = MailMime::parse($raw);
		foreach (['from', 'to', 'cc', 'bcc', 'subject'] as $field) {
			$value['message'][$field] = $mime[$field];
		}
		$nativeAttachments = $value['message']['attachments'] ?? [];
		if (!is_array($nativeAttachments) || count($nativeAttachments) !== count($mime['attachments'])) {
			throw new Problem('Native attachment membership could not be verified.', 503);
		}
		foreach ($mime['attachments'] as $index => &$attachment) {
			$part = $nativeAttachments[$index];
			if (($part['fileName'] ?? null) !== $attachment['fileName'] || strtolower($part['mime'] ?? '') !== $attachment['mime']
				|| !is_string($part['id'] ?? null) || !preg_match('/^[0-9]+(?:\.[0-9]+)*$/D', $part['id'])) {
				throw new Problem('Native MIME attachment identity is ambiguous.', 503);
			}
			$attachment['reference'] = 'mail-attachment:' . $messageId . ':' . $part['id'];
			$attachment['native'] = ['type' => 'message-attachment', 'mailboxId' => $value['message']['mailboxId'], 'uid' => $value['message']['uid'], 'id' => $part['id']];
		}
		unset($attachment);
		return [...$value, 'mime' => $mime];
	}

	public function mailMessages(array $account, int $mailboxId): array {
		if (!in_array($mailboxId, array_column($account['mailboxes'], 'databaseId'), true)) {
			throw new Problem('The mailbox is outside this account.', 403);
		}
		try {
			$this->mailRequest($account['policy'], 'sync', $mailboxId);
		} catch (Problem $error) {
			if ($error->status !== 409) {
				throw $error;
			} /* Native UI is syncing this cache. No message/read-flag write is retried. */
		}
		// Mail's own UI can hold the mailbox cache lock while its initial sync runs.
		// Only this read may wait and retry; effects are never retried here.
		for ($read = 0; ; $read++) {
			try {
				$messages = $this->mailRequest($account['policy'], 'messages', $mailboxId);
				break;
			} catch (Problem $error) {
				if ($error->status !== 409 || $read >= 10) {
					throw $error;
				}
				usleep(500000);
			}
		}
		if (!is_array($messages) || !array_is_list($messages) || count($messages) > 20) {
			throw new Problem('Narrow the native mailbox result.', 413);
		}
		foreach ($messages as $message) {
			if (!is_int($message['databaseId'] ?? null) || ($message['mailboxId'] ?? null) !== $mailboxId) {
				throw new Problem('Unverified native message collection.', 503);
			}
		}
		usort($messages, static fn ($a, $b) => [$b['dateInt'] ?? 0, $b['databaseId']] <=> [$a['dateInt'] ?? 0, $a['databaseId']]);
		return $messages;
	}

	public function talkRequest(array $policy, string $operation, string $token, array $payload = []): array {
		$result = $this->decode($this->bridge->send(NativeRequest::talk($policy['actor'], $operation, $token, $payload)));
		if (!is_array($result)) {
			throw new Problem('Unverified native Talk result.', 503);
		}
		return $result;
	}

	/** Safe projection: native connection usernames/hosts, signatures and aliases stay local. */
	public function mailAccounts(bool $audit = false): array {
		$policy = $this->policy('mail', $audit);
		$key = $policy['actor'] . ':' . $policy['policyVersion'];
		if (isset($this->accountReads[$key])) {
			return $this->accountReads[$key];
		}
		$accounts = $this->mailRequest($policy, 'accounts');
		if (!is_array($accounts) || !array_is_list($accounts) || count($accounts) > 100) {
			throw new Problem('Narrow the native Mail account selection.', 413);
		}
		$result = [];
		foreach ($accounts as $account) {
			if (!is_array($account) || !is_int($account['accountId'] ?? null)) {
				throw new Problem('Unverified native Mail account.', 503);
			}
			if (!in_array((string)$account['accountId'], $policy['collections'], true)) {
				continue;
			}
			foreach (['name', 'emailAddress'] as $field) {
				if (!is_string($account[$field] ?? null) || strlen($account[$field]) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $account[$field])) {
					throw new Problem('Unverified native Mail identity.', 503);
				}
			}
			$public = array_intersect_key($account, array_flip(['accountId', 'name', 'emailAddress', 'draftsMailboxId', 'sentMailboxId', 'isDelegated']));
			$result[] = ['policy' => $policy, 'account' => $public,
				'authorityRevision' => StructuredProvider::digest([$public, array_intersect_key($account, array_flip(['imapHost', 'imapPort', 'imapUser', 'imapSslMode', 'smtpHost', 'smtpPort', 'smtpUser', 'smtpSslMode', 'aliases', 'smimeCertificateId', 'authMethod']))])];
		}
		return $this->accountReads[$key] = $result;
	}

	public function mailAccount(int $id, bool $audit = false): array {
		if ($id < 1 || $id > 999999999999999) {
			throw new Problem('A native Mail account is required.', 400);
		}
		$policy = $this->policy('mail', $audit);
		if (!in_array((string)$id, $policy['collections'], true)) {
			throw new Problem('The Mail account is outside the configured scope.', 403);
		}
		$key = 'account:' . $id . ':' . $policy['policyVersion'];
		if (isset($this->accountReads[$key])) {
			return $this->accountReads[$key];
		}
		$account = $this->mailRequest($policy, 'account', $id);
		if (!is_array($account) || ($account['accountId'] ?? null) !== $id || !is_string($account['name'] ?? null) || !is_string($account['emailAddress'] ?? null)) {
			throw new Problem('Current native Mail account access is required.', 403);
		}
		$public = array_intersect_key($account, array_flip(['accountId', 'name', 'emailAddress', 'draftsMailboxId', 'sentMailboxId', 'isDelegated']));
		return $this->accountReads[$key] = ['policy' => $policy, 'account' => $public, 'authorityRevision' => StructuredProvider::digest([$public, array_intersect_key($account, array_flip(['imapHost', 'imapPort', 'imapUser', 'imapSslMode', 'smtpHost', 'smtpPort', 'smtpUser', 'smtpSslMode', 'smimeCertificateId', 'authMethod']))])];
	}

	/** Same-instance public download; never a URL from a tool argument or HTML body. */
	public function attachmentBytes(array $policy, int $messageId, string $part): string {
		$response = $this->bridge->send(NativeRequest::mailAttachment($policy['actor'], $messageId, $part));
		if (strlen($response['body']) > 262144) {
			throw new Problem('Narrow the native attachment.', 413);
		}
		return $response['body'];
	}

	public function mailboxes(int $accountId, bool $audit = false): array {
		$account = $this->mailAccount($accountId, $audit);
		$key = $accountId . ':' . $account['policy']['policyVersion'];
		if (isset($this->mailboxReads[$key])) {
			return $this->mailboxReads[$key];
		}
		$response = $this->mailRequest($account['policy'], 'mailboxes', $accountId);
		if (!is_array($response) || ($response['id'] ?? null) !== $accountId || !is_array($response['mailboxes'] ?? null)) {
			throw new Problem('Unverified native mailbox membership.', 503);
		}
		$result = [];
		$walk = static function (array $items, int $depth) use (&$walk, &$result, $accountId): void {
			if ($depth > 8 || !array_is_list($items)) {
				throw new Problem('Narrow the native mailbox hierarchy.', 413);
			}
			foreach ($items as $item) {
				if (!is_array($item) || ($item['accountId'] ?? null) !== $accountId || !is_int($item['databaseId'] ?? null) || $item['databaseId'] < 1
					|| !is_string($item['displayName'] ?? null) || strlen($item['displayName']) > 1024) {
					throw new Problem('Unverified native mailbox.', 503);
				}
				if (count($result) >= 100 || isset($result[$item['databaseId']])) {
					throw new Problem('Narrow the native mailbox selection.', 413);
				}
				$result[$item['databaseId']] = array_intersect_key($item, array_flip(['databaseId', 'accountId', 'displayName', 'specialRole', 'myAcls', 'shared']));
				$walk($item['mailboxes'] ?? [], $depth + 1);
			}
		};
		$walk($response['mailboxes'], 0);
		ksort($result, SORT_NUMERIC);
		return $this->mailboxReads[$key] = [...$account, 'mailboxes' => array_values($result)];
	}

	/** Resolve membership before content disclosure; the native endpoint rechecks the caller. */
	public function mailEnvelope(int $accountId, int $messageId, bool $audit = false): array {
		$account = $this->mailboxes($accountId, $audit);
		$message = $this->mailRequest($account['policy'], 'message', $messageId);
		if (!is_array($message) || ($message['databaseId'] ?? null) !== $messageId
			|| !in_array($message['mailboxId'] ?? null, array_column($account['mailboxes'], 'databaseId'), true)) {
			throw new Problem('The native message is outside this account.', 403);
		}
		return [...$account, 'message' => $message];
	}

	/** Stable audience excludes presence, unread counts and transient session identifiers. */
	public function talkRoom(string $token, bool $audit = false): array {
		$policy = $this->policy('talk', $audit);
		if (!in_array($token, $policy['collections'], true)) {
			throw new Problem('The Talk room is outside the configured scope.', 403);
		}
		$room = $this->talkRequest($policy, 'room', $token);
		if (($room['token'] ?? null) !== $token || ($room['actorType'] ?? '') !== 'users' || ($room['actorId'] ?? '') !== $policy['actor']
			|| !in_array($room['participantType'] ?? null, [1, 2, 3], true) || !in_array($room['type'] ?? null, [1, 2, 3], true)
			|| !is_int($room['permissions'] ?? null) || !is_string($room['displayName'] ?? null) || strlen($room['displayName']) > 2048) {
			throw new Problem('A supported room and current native membership are required.', 403);
		}
		$participants = $this->talkRequest($policy, 'participants', $token);
		if (!array_is_list($participants) || count($participants) > 100) {
			throw new Problem('Narrow the native Talk audience.', 413);
		}
		$audience = [];
		foreach ($participants as $participant) {
			if (($participant['roomToken'] ?? '') !== $token || !is_string($participant['actorId'] ?? null) || !is_string($participant['actorType'] ?? null)
				|| !is_string($participant['displayName'] ?? null) || strlen($participant['actorId']) > 1024 || strlen($participant['displayName']) > 2048
				|| !is_int($participant['participantType'] ?? null) || !is_int($participant['permissions'] ?? null)) {
				throw new Problem('Unverified native Talk audience.', 503);
			}
			$audience[] = array_intersect_key($participant, array_flip(['actorId', 'actorType', 'displayName', 'participantType', 'permissions']));
		}
		usort($audience, static fn ($a, $b) => [$a['actorType'], $a['actorId']] <=> [$b['actorType'], $b['actorId']]);
		if (!array_filter($audience, static fn ($a) => $a['actorType'] === 'users' && $a['actorId'] === $policy['actor'])) {
			throw new Problem('The caller is no longer in the native audience.', 403);
		}
		$stable = array_intersect_key($room, array_flip(['token', 'type', 'displayName', 'objectType', 'objectId', 'readOnly', 'lobbyState', 'permissions', 'defaultPermissions', 'participantType', 'sipEnabled', 'listable', 'messageExpiration', 'isArchived', 'mentionPermissions', 'attributes']));
		$canWrite = $policy['writesEnabled'] && ($room['permissions'] & 128) !== 0 && ($room['readOnly'] ?? 1) === 0
			&& (($room['lobbyState'] ?? 1) === 0 || in_array($room['participantType'], [1, 2], true)) && ($room['isArchived'] ?? true) === false;
		return ['policy' => $policy, 'room' => $stable, 'participants' => $audience, 'canWrite' => $canWrite,
			'audience' => $room['type'] === 3 || ($room['listable'] ?? 0) !== 0 ? 'public-or-discoverable' : 'room-members',
			'authorityRevision' => StructuredProvider::digest([$stable, $audience])];
	}
}
