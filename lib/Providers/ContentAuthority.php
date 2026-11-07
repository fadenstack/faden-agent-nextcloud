<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;
use OCP\App\IAppManager;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

/** Public sibling APIs under the current native session; no sibling private tables. */
final class ContentAuthority {
	public const VERSIONS = ['collectives' => '4.7.1', 'forms' => '5.4.1', 'notes' => '6.1.0', 'bookmarks' => '17.0.0'];
	public function __construct(
		private NativeApiBridge $bridge,
		private ProviderPolicy $policies,
		private IAppManager $apps,
		private IUserSession $users,
		private IConfig $config,
		private IAppConfig $appConfig,
		private IGroupManager $groups,
		private IRootFolder $root,
	) {
	}
	public function policy(string $provider, bool $audit = false): array {
		$policy = $this->policies->content($provider, $audit);
		$this->policies->assertDisclosure($policy);
		if (!$this->apps->isEnabledForUser($provider, $this->users->getUser()) || $this->apps->getAppVersion($provider) !== (self::VERSIONS[$provider] ?? null)) {
			throw new Problem('This native content app version has not been enabled and tested.', 403);
		}
		return $policy;
	}
	public function request(array $policy, string $provider, string $operation, int $collection = 0, int $item = 0, array $payload = []): mixed {
		$response = $this->bridge->send(NativeRequest::content($policy['actor'], $provider, $operation, $collection, $item, $payload));
		try {
			$data = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			throw new Problem('Malformed native content JSON.', 503);
		}
		if (in_array($provider, ['forms', 'collectives'], true)) {
			if (!is_array($data) || !isset($data['ocs']['meta']) || !array_key_exists('data', $data['ocs']) || !in_array($data['ocs']['meta']['statuscode'] ?? null, [100, 200, 201], true) || ($data['ocs']['meta']['status'] ?? '') !== 'ok') {
				throw new Problem('The native content OCS operation was refused.', 503);
			}
			$data = $data['ocs']['data'];
		} elseif ($provider === 'bookmarks') {
			if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
				throw new Problem('The native Bookmarks operation was refused.', 503);
			}
		} elseif (!is_array($data) || !empty($data['errorMessage']) || (($data['error'] ?? false) !== false)) {
			throw new Problem('The native Notes operation was refused.', 503);
		}
		return $data;
	}
	public static function id(mixed $value): int {
		if (!is_int($value) || $value < 1 || $value > 999999999999999) {
			throw new Problem('Use a positive native content identifier.', 400);
		}
		return $value;
	}
	public static function boundedList(mixed $value, int $limit = 50): array {
		if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
			throw new Problem('Narrow the native content collection.', 413);
		}
		$seen = [];
		foreach ($value as $item) {
			$id = self::id($item['id'] ?? null);
			if (isset($seen[$id])) {
				throw new Problem('Duplicate native content membership.', 503);
			}
			$seen[$id] = true;
		}
		return $value;
	}
	public static function assertFormMembership(array $form, int $id): void {
		if (($form['id'] ?? null) !== $id) {
			throw new Problem('Unverified native form membership.', 503);
		}
		foreach (self::boundedList($form['questions'] ?? null) as $question) {
			if (($question['formId'] ?? null) !== $id || !is_string($question['type'] ?? null) || !is_string($question['text'] ?? null) || !is_bool($question['isRequired'] ?? null)) {
				throw new Problem('Unverified native question membership.', 503);
			}
			foreach (self::boundedList($question['options'] ?? null) as $option) {
				if (($option['questionId'] ?? null) !== $question['id'] || !is_string($option['text'] ?? null)) {
					throw new Problem('Unverified native option membership.', 503);
				}
			}
		}
	}

	/** Current public filesystem authority for Notes' configured destination, not private Notes tables. */
	public function notesDestination(array $policy, array $settings, string $category = ''): array {
		$path = $settings['notesPath'] ?? null;
		if (!is_string($path) || strlen($path) > 2048 || str_contains($path, '\\')) {
			throw new Problem('A canonical native Notes folder is required.', 503);
		}
		$parts = [...($path === '' ? [] : explode('/', $path)), ...($category === '' ? [] : explode('/', $category))];
		if (count($parts) > 32) {
			throw new Problem('Narrow the Notes folder depth.', 413);
		}
		foreach ($parts as $part) {
			if ($part === '' || $part === '.' || $part === '..' || preg_match('/[\x00-\x1f\x7f\\\\]/', $part)) {
				throw new Problem('Use a canonical native Notes category.', 400);
			}
		}
		$folder = $this->root->getUserFolder($policy['actor']);
		$missing = [];
		foreach ($parts as $i => $part) {
			if (!$folder->isReadable()) {
				throw new Problem('The native Notes folder is unreadable.', 403);
			}
			if (!$folder->nodeExists($part)) {
				$missing = array_slice($parts, $i);
				break;
			}
			$node = $folder->get($part);
			if (!$node instanceof Folder || !$node->isReadable()) {
				throw new Problem('The native Notes folder is unavailable.', 403);
			}
			$folder = $node;
		}
		if (!$folder->isReadable()) {
			throw new Problem('The native Notes folder is unreadable.', 403);
		}
		return ['canWrite' => $folder->isCreatable(), 'binding' => StructuredProvider::digest([$policy['actor'], $path, $category, $folder->getId(), $folder->getPermissions(), $missing])];
	}
	public function collection(string $provider, string $id, bool $audit = false): array {
		$policy = $this->policy($provider, $audit);
		$personal = in_array('personal', $policy['collections'], true);
		if (!in_array($id, $policy['collections'], true) && !($provider === 'forms' && $personal && preg_match('/^[1-9][0-9]{0,14}$/D', $id))) {
			throw new Problem('This native content collection is outside the configured scope.', 403);
		}
		$canWrite = false;
		$audience = 'unknown';
		if ($provider === 'collectives') {
			$all = $this->request($policy, $provider, 'list');
			$items = self::boundedList($all['collectives'] ?? null, 100);
			$data = array_values(array_filter($items, static fn ($c) => (string)$c['id'] === $id))[0] ?? null;
			if (!$data || ($data['trashTimestamp'] ?? null) !== null || ($data['isPageShare'] ?? true) !== false || !is_bool($data['canEdit'] ?? null) || !is_string($data['name'] ?? null)) {
				throw new Problem('Current collective membership is required.', 403);
			}
			$canWrite = $data['canEdit'];
			$audience = 'unknown'; // Page shares can expose content outside the team; do not invent a complete audience inventory.
			$authority = array_intersect_key($data, array_flip(['id', 'name', 'circleId', 'level', 'editPermissionLevel', 'sharePermissionLevel', 'canEdit', 'canShare', 'shareToken']));
		} elseif ($provider === 'forms' && $id !== 'personal') {
			$data = $this->request($policy, $provider, 'metadata', (int)$id);
			if (!is_array($data) || ($data['id'] ?? null) !== (int)$id || !is_string($data['ownerId'] ?? null) || !is_array($data['permissions'] ?? null) || !in_array('submit', $data['permissions'], true)) {
				throw new Problem('Current native form permission is required.', 403);
			}
			if (!in_array($id, $policy['collections'], true) && $data['ownerId'] !== $policy['actor']) {
				throw new Problem('The form is outside the personal collection.', 403);
			}
			self::assertFormMembership($data, (int)$id);
			$canWrite = ($data['canSubmit'] ?? null) === true;
			$audience = $this->privateForm($data, $policy['actor']) ? 'private' : 'unknown';
			$authority = array_intersect_key($data, array_flip(['id', 'ownerId', 'access', 'shares', 'permissions', 'isAnonymous', 'state', 'expires', 'confirmationEmailEnabled', 'fileId', 'fileFormat']));
		} elseif ($provider === 'forms') {
			$data = self::boundedList($this->request($policy, $provider, 'list'));
			// Native type=owned is checked again against each full form before use.
			// Public OCP configuration/group interfaces, matching the pinned Forms
			// creation policy. The native POST independently enforces the same rule.
			$restricted = $this->appConfig->getValueBool('forms', 'restrictCreation', false);
			$allowed = $this->appConfig->getValueArray('forms', 'creationAllowedGroups', []);
			$canWrite = !$restricted || array_intersect($this->groups->getUserGroupIds($this->users->getUser()), $allowed) !== [];
			$audience = 'private';
			$authority = [$policy['actor'], $canWrite];
		} elseif ($provider === 'notes') {
			$data = $this->request($policy, $provider, 'settings');
			if (!is_string($data['notesPath'] ?? null) || !is_string($data['fileSuffix'] ?? null)) {
				throw new Problem('Unverified native Notes destination.', 503);
			}
			$destination = $this->notesDestination($policy, $data);
			$canWrite = $destination['canWrite'];
			$authority = [$policy['actor'], $data, $destination['binding']]; // Never claim that the Notes folder is unshared.
		} else {
			$data = $this->request($policy, $provider, 'root');
			if (!is_array($data['data'] ?? null)) {
				throw new Problem('Unverified personal Bookmarks collection.', 503);
			}
			// Native crawling must retain Nextcloud's DNS/redirect-aware local-network
			// protection. A URL syntax check alone cannot enforce this boundary.
			$canWrite = $this->config->getSystemValue('allow_local_remote_servers', false) === false;
			$authority = [$policy['actor'], 'personal-root'];
		}
		return ['policy' => $policy, 'data' => $data, 'canWrite' => $canWrite, 'audience' => $audience,
			'authorityRevision' => StructuredProvider::digest([$provider, $id, $authority, $policy['policyVersion']])];
	}
	public function privateForm(array $form, string $actor): bool {
		return ($form['ownerId'] ?? null) === $actor && ($form['access']['permitAllUsers'] ?? null) === false && ($form['access']['showToAllUsers'] ?? null) === false && ($form['shares'] ?? null) === [] && ($form['fileId'] ?? null) === null && ($form['confirmationEmailEnabled'] ?? false) === false;
	}
}
