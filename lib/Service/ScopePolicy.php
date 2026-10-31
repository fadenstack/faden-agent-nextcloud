<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Service;

use OCA\FadenstackAgent\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\Share\IManager;

/** Native policy only restricts the SDK's published contract; it never grants a central tool. */
class ScopePolicy {
	public const CONFIG_KEY = 'scope_policy';
	public const FILE_TOOLS = ['nc_get_file_metadata', 'nc_search_in_file', 'nc_search_content', 'nc_search_files',
		'nc_get_selected_file', 'nc_read_file', 'nc_list_folder', 'nc_create_text_file', 'nc_update_text_file'];
	public const HISTORY_TOOLS = ['nc_actions_undo', 'nc_actions_redo', 'nc_actions_reconcile', 'nc_actions_revalidate'];
	public const EXTENDED_READ_TOOLS = ['nc_files_organize_read', 'nc_files_shares_read'];
	public const FILE_MUTATION_TOOLS = ['nc_files_copy', 'nc_files_move', 'nc_files_share_readonly'];
	public const STRUCTURED_TOOLS = ['nc_tables_read', 'nc_tables_add_column', 'nc_tables_add_row', 'nc_tables_update_row', 'nc_tables_delete_row',
		'nc_deck_read', 'nc_deck_patch', 'nc_deck_create', 'nc_deck_move', 'nc_deck_assign', 'nc_deck_label'];
	public const DAV_TOOLS = ['nc_calendar_list', 'nc_calendar_read', 'nc_calendar_create', 'nc_calendar_patch', 'nc_calendar_delete',
		'nc_tasks_read', 'nc_tasks_create', 'nc_tasks_patch', 'nc_tasks_delete',
		'nc_contacts_list', 'nc_contacts_find', 'nc_contacts_read', 'nc_contacts_create', 'nc_contacts_patch', 'nc_contacts_delete'];
	private const LIMITS = ['calls' => 30, 'mutations' => 10, 'elapsedMs' => 180000, 'resultBytes' => 262144, 'records' => 200, 'bulkItems' => 20];
	private const CLASSES = ['standard', 'protected', 'unknown'];
	private const AUDIENCES = ['private', 'shared', 'public', 'unknown'];

	public function __construct(
		private IConfig $config,
		private IRootFolder $root,
		private IUserSession $users,
		private IManager $shares,
	) {
	}

	private function uid(): string {
		$user = $this->users->getUser();
		if ($user === null) {
			throw new Problem('Sign in to Nextcloud.', 401);
		}
		return $user->getUID();
	}

	/** Returns only public policy metadata; no credentials, share tokens or recipient lists. */
	public function getPolicy(string $uid, string $serverOrigin): array {
		if ($uid !== $this->uid()) {
			throw new Problem('The native account changed.', 401);
		}
		$development = $this->config->getAppValue(Application::APP_ID, 'development', '0') === '1';
		$configured = Configuration::origin($this->config->getAppValue(Application::APP_ID, 'server_url', ''), $development);
		if ($this->config->getAppValue(Application::APP_ID, 'integration_enabled', '0') !== '1'
			|| !hash_equals($configured, Configuration::origin($serverOrigin, $development))) {
			throw new Problem('The configured integration is unavailable.', 403);
		}
		$settings = $this->settings();
		$policy = array_intersect_key($settings, array_flip(['sources', 'destinations', 'tools', 'sourceClasses', 'downstreamPolicy', 'destinationAudiences', 'movements', 'limits']));
		$stamp = [$uid, $configured, $this->config->getAppValue(Application::APP_ID, 'agent', ''),
			$this->config->getAppValue(Application::APP_ID, 'installed_version', ''), $settings, $development,
			$this->config->getAppValue(Application::APP_ID, 'files_bounded_content_enabled', '0')];
		return ['version' => hash('sha256', json_encode($stamp, JSON_THROW_ON_ERROR)), 'gatewayOrigin' => $configured,
			'developmentLoopback' => $development, 'boundedContentSearch' => $development && $this->config->getAppValue(Application::APP_ID, 'files_bounded_content_enabled', '0') === '1', ...$policy];
	}

	public function configStamp(): string {
		return $this->currentPolicy()['version'];
	}

	/** Server opt-in and native allowlist are mandatory even for direct HTTP requests. */
	public function assertFilesReadTool(string $name): void {
		if ($this->config->getAppValue(Application::APP_ID, 'files_reads_enabled', '0') !== '1'
			|| !in_array($name, self::EXTENDED_READ_TOOLS, true)
			|| !in_array($name, $this->currentPolicy()['tools'], true)) {
			throw new Problem('This Files read tool is unavailable under the current native policy.', 403);
		}
	}

	/** Server opt-in and development mode are mandatory for experimental folder creation. */
	public function assertFolderCreateTool(): void {
		if ($this->config->getAppValue(Application::APP_ID, 'development', '0') !== '1'
			|| $this->config->getAppValue(Application::APP_ID, 'folder_create_enabled', '0') !== '1'
			|| !in_array('nc_files_create_folder', $this->currentPolicy()['tools'], true)) {
			throw new Problem('Folder creation is unavailable under the current native policy.', 403);
		}
	}

	private function currentPolicy(): array {
		return $this->getPolicy($this->uid(), $this->config->getAppValue(Application::APP_ID, 'server_url', ''));
	}

	public function assertFileMutationTool(string $name): void {
		if ($this->config->getAppValue(Application::APP_ID, 'development', '0') !== '1'
			|| $this->config->getAppValue(Application::APP_ID, 'files_mutations_enabled', '0') !== '1'
			|| !in_array($name, self::FILE_MUTATION_TOOLS, true) || !in_array($name, $this->currentPolicy()['tools'], true)) {
			throw new Problem('This Files operation is outside the configured native tool scope.', 403);
		}
	}

	/** Sharing changes the audience even when the content is unchanged. */
	public function assertShareMovement(File $file): void {
		$policy = $this->currentPolicy();
		$grant = $this->describeNode($file, 'read');
		$this->describeNode($file, 'write');
		if (!in_array('shared', $policy['destinationAudiences'], true)
			|| !in_array(['source' => $grant['sourceClass'], 'destination' => 'shared'], $policy['movements'], true)) {
			throw new Problem('Sharing this content with another user is outside the configured movement policy.', 403);
		}
	}

	/** Empty configuration preserves the existing native-user Files integration, without downstream attestation. */
	private function settings(): array {
		$raw = $this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, '');
		if ($raw === '') {
			$tools = self::FILE_TOOLS;
			if ($this->config->getAppValue(Application::APP_ID, 'history_enabled', '0') === '1') {
				$tools = [...$tools, ...self::HISTORY_TOOLS];
			}
			if ($this->config->getAppValue(Application::APP_ID, 'files_reads_enabled', '0') === '1') {
				$tools = [...$tools, ...self::EXTENDED_READ_TOOLS];
			}
			if ($this->config->getAppValue(Application::APP_ID, 'development', '0') === '1'
				&& $this->config->getAppValue(Application::APP_ID, 'folder_create_enabled', '0') === '1') {
				$tools = [...$tools, 'nc_files_create_folder'];
			}
			return ['revision' => 'legacy-files-v1', 'sources' => [['kind' => 'user-files']], 'destinations' => [['kind' => 'user-files']],
				'tools' => $tools, 'sourceClasses' => ['standard'], 'downstreamPolicy' => 'unverified',
				'destinationAudiences' => ['private'], 'movements' => [['source' => 'standard', 'destination' => 'private']],
				'classificationDefault' => 'standard', 'classificationRules' => [], 'limits' => self::LIMITS];
		}
		try {
			if (strlen($raw) > 32768) {
				throw new \InvalidArgumentException();
			}
			$value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
			if (!is_array($value) || array_is_list($value)) {
				throw new \InvalidArgumentException();
			}
			$this->keys($value, ['revision', 'sources', 'destinations', 'tools', 'sourceClasses', 'downstreamPolicy', 'destinationAudiences', 'movements', 'classificationDefault', 'classificationRules', 'limits']);
			$sources = $this->scopes($value['sources'] ?? []);
			$destinations = $this->scopes($value['destinations'] ?? []);
			$tools = $this->enumList($value['tools'] ?? [], [...self::FILE_TOOLS, ...self::HISTORY_TOOLS, ...self::EXTENDED_READ_TOOLS, ...self::FILE_MUTATION_TOOLS, ...self::DAV_TOOLS, ...self::STRUCTURED_TOOLS, 'nc_files_create_folder']);
			if (!$sources || !$tools || !is_string($value['revision'] ?? null) || strlen($value['revision']) < 1 || strlen($value['revision']) > 100) {
				throw new \InvalidArgumentException();
			}
			if ($this->config->getAppValue(Application::APP_ID, 'files_reads_enabled', '0') !== '1') {
				$tools = array_values(array_diff($tools, self::EXTENDED_READ_TOOLS));
			}
			if ($this->config->getAppValue(Application::APP_ID, 'development', '0') !== '1'
				|| $this->config->getAppValue(Application::APP_ID, 'files_mutations_enabled', '0') !== '1') {
				$tools = array_values(array_diff($tools, self::FILE_MUTATION_TOOLS));
			}
			if ($this->config->getAppValue(Application::APP_ID, 'development', '0') !== '1'
				|| $this->config->getAppValue(Application::APP_ID, 'provider_reads_enabled', '0') !== '1') {
				$tools = array_values(array_diff($tools, self::DAV_TOOLS));
			}
			if ($this->config->getAppValue(Application::APP_ID, 'development', '0') !== '1'
				|| $this->config->getAppValue(Application::APP_ID, 'folder_create_enabled', '0') !== '1') {
				$tools = array_values(array_diff($tools, ['nc_files_create_folder']));
			}
			$classes = $this->enumList($value['sourceClasses'] ?? ['standard'], self::CLASSES);
			$audiences = $this->enumList($value['destinationAudiences'] ?? ['private'], self::AUDIENCES);
			$downstream = $value['downstreamPolicy'] ?? 'unverified';
			$default = $value['classificationDefault'] ?? 'unknown';
			if (!in_array($downstream, ['unverified', 'configured'], true) || !in_array($default, self::CLASSES, true) || !$classes) {
				throw new \InvalidArgumentException();
			}
			$movements = $value['movements'] ?? [];
			$rules = $value['classificationRules'] ?? [];
			if (!is_array($movements) || !array_is_list($movements) || count($movements) > 12 || !is_array($rules) || !array_is_list($rules) || count($rules) > 32) {
				throw new \InvalidArgumentException();
			}
			foreach ($movements as &$rule) {
				if (!is_array($rule)) {
					throw new \InvalidArgumentException();
				}
				$this->keys($rule, ['source', 'destination']);
				if (!in_array($rule['source'] ?? null, self::CLASSES, true) || !in_array($rule['destination'] ?? null, self::AUDIENCES, true)) {
					throw new \InvalidArgumentException();
				}
				$rule = ['source' => $rule['source'], 'destination' => $rule['destination']];
			}
			unset($rule);
			foreach ($rules as &$rule) {
				if (!is_array($rule) || !in_array($rule['class'] ?? null, self::CLASSES, true)) {
					throw new \InvalidArgumentException();
				}
				$this->keys($rule, ['scope', 'class']);
				$rule['scope'] = $this->scopes([$rule['scope'] ?? null])[0];
			}
			unset($rule);
			$limits = $value['limits'] ?? [];
			if (!is_array($limits)) {
				throw new \InvalidArgumentException();
			}
			$this->keys($limits, array_keys(self::LIMITS));
			$limits = [...self::LIMITS, ...$limits];
			foreach ($limits as $key => $limit) {
				if (!is_int($limit) || $limit < 1 || $limit > self::LIMITS[$key]) {
					throw new \InvalidArgumentException();
				}
			}
			return ['revision' => $value['revision'], 'sources' => $sources, 'destinations' => $destinations, 'tools' => $tools,
				'sourceClasses' => $classes, 'downstreamPolicy' => $downstream, 'destinationAudiences' => $audiences,
				'movements' => $movements, 'classificationDefault' => $default, 'classificationRules' => $rules, 'limits' => $limits];
		} catch (\Throwable $e) {
			throw new Problem('The administrator scope policy is invalid. No agent operation is available.', 503);
		}
	}

	private function keys(array $value, array $allowed): void {
		if (array_diff(array_keys($value), $allowed)) {
			throw new \InvalidArgumentException();
		}
	}

	private function enumList(mixed $value, array $allowed): array {
		if (!is_array($value) || !array_is_list($value) || count($value) !== count(array_unique($value, SORT_REGULAR)) || array_diff($value, $allowed)) {
			throw new \InvalidArgumentException();
		}
		return $value;
	}

	private function scopes(mixed $scopes): array {
		if (!is_array($scopes) || !array_is_list($scopes) || count($scopes) > 8) {
			throw new \InvalidArgumentException();
		}
		$normalized = [];
		foreach ($scopes as $scope) {
			if (!is_array($scope)) {
				throw new \InvalidArgumentException();
			}
			if (($scope['kind'] ?? null) === 'user-files') {
				$this->keys($scope, ['kind']);
				$normalized[] = ['kind' => 'user-files'];
			} else {
				$this->keys($scope, ['kind', 'resourceId']);
				if (!in_array($scope['kind'] ?? null, ['file', 'folder'], true)) {
					throw new \InvalidArgumentException();
				}
				$this->id($scope['resourceId'] ?? '');
				$normalized[] = ['kind' => $scope['kind'], 'resourceId' => $scope['resourceId']];
			}
		}
		if (count(array_unique(array_map(fn ($scope) => json_encode($scope, JSON_THROW_ON_ERROR), $normalized))) !== count($normalized)) {
			throw new \InvalidArgumentException();
		}
		return $normalized;
	}

	private function id(string $reference): int {
		if (!preg_match('/^nextcloud:files:([1-9][0-9]{0,14})$/D', $reference, $match)) {
			throw new Problem('Use a canonical Nextcloud file reference.', 400);
		}
		return (int)$match[1];
	}

	private function nodes(int $id): array {
		return $this->root->getUserFolder($this->uid())->getById($id);
	}

	private function within(Node $node, Node $folder): bool {
		return $node->getPath() === $folder->getPath() || str_starts_with($node->getPath(), rtrim($folder->getPath(), '/') . '/');
	}

	private function matches(Node $node, array $scope): bool {
		if ($scope['kind'] === 'user-files') {
			return $this->within($node, $this->root->getUserFolder($this->uid()));
		}
		$id = $this->id($scope['resourceId']);
		if ($scope['kind'] === 'file') {
			return $node instanceof File && $node->getId() === $id;
		}
		foreach ($this->nodes($id) as $folder) {
			if ($folder instanceof Folder && $folder->isReadable() && $this->within($node, $folder)) {
				return true;
			}
		}
		return false;
	}

	public function resolve(string $reference, string $purpose = 'metadata'): array {
		foreach ($this->nodes($this->id($reference)) as $node) {
			try {
				return $this->describeNode($node, $purpose);
			} catch (Problem $e) {
				if (!in_array($e->status, [403, 404], true)) {
					throw $e;
				}
			}
		}
		throw new Problem('The source is unavailable in the configured agent scope.', 404);
	}

	public function describeNode(Node $node, string $purpose = 'metadata', bool $probe = true): array {
		if (!in_array($purpose, ['metadata', 'read', 'write', 'history'], true)) {
			throw new Problem('Invalid native resource purpose.');
		}
		$policy = $this->currentPolicy();
		if (!$node->isReadable() || !$this->within($node, $this->root->getUserFolder($this->uid()))) {
			throw new Problem('The source is unavailable in the configured agent scope.', 404);
		}
		if ($purpose === 'write' && (($node instanceof Folder && !$node->isCreatable()) || ($node instanceof File && !$node->isUpdateable()))) {
			throw new Problem('The destination is not writable.', 403);
		}
		$scope = null;
		foreach ($policy[$purpose === 'write' ? 'destinations' : 'sources'] as $candidate) {
			if ($this->matches($node, $candidate)) {
				$scope = $candidate;
				break;
			}
		}
		if ($scope === null) {
			throw new Problem('The source is unavailable in the configured agent scope.', 404);
		}
		if ($probe && in_array($purpose, ['read', 'history'], true) && $node instanceof File) {
			$stream = $node->fopen('rb');
			if (!is_resource($stream)) {
				throw new Problem('The source is unavailable in the configured agent scope.', 403);
			}
			fclose($stream);
		}
		$class = $this->classification($node);
		if (in_array($purpose, ['read', 'history'], true) && ($class === 'unknown' || !in_array($class, $policy['sourceClasses'], true)
			|| ($class === 'protected' && $policy['downstreamPolicy'] !== 'configured'))) {
			throw new Problem('This source is not allowed for the configured agent processing policy.', 403);
		}
		return ['resourceId' => 'nextcloud:files:' . $node->getId(), 'revision' => $node->getEtag(),
			'sourceClass' => $class, 'audience' => $this->audience($node), 'scope' => $scope];
	}

	/** Disable the read probe only while holding an already-authorized native write stream. */
	public function assertMovement(Node $source, Node $destination, bool $probe = true): void {
		$policy = $this->currentPolicy();
		$from = $this->describeNode($source, 'read', $probe);
		$to = $this->describeNode($destination, 'write');
		if ($to['audience'] === 'unknown' || !in_array($to['audience'], $policy['destinationAudiences'], true)
			|| !in_array(['source' => $from['sourceClass'], 'destination' => $to['audience']], $policy['movements'], true)) {
			throw new Problem('This content cannot be saved to the destination audience under the configured agent policy.', 403);
		}
	}

	private function classification(Node $node): string {
		$settings = $this->settings();
		$matched = [];
		foreach ($settings['classificationRules'] as $rule) {
			// A configured protected folder that cannot be resolved must not silently downgrade a shared child.
			if ($rule['scope']['kind'] === 'folder' && !$this->nodes($this->id($rule['scope']['resourceId']))) {
				return 'unknown';
			}
			if ($this->matches($node, $rule['scope'])) {
				$matched[] = $rule['class'];
			}
		}
		foreach (['unknown', 'protected', 'standard'] as $class) {
			if (in_array($class, $matched, true)) {
				return $class;
			}
		}
		return $settings['classificationDefault'];
	}

	/** Public IManager access list includes inherited parent sharing. Never expose its recipients/tokens. */
	public function audience(Node $node): string {
		try {
			$list = $this->shares->getAccessList($node, true, false);
			$users = $list['users'] ?? [];
			$mail = $list['mail'] ?? [];
			$public = $list['public'] ?? false;
			$remote = $list['remote'] ?? false;
			if (!is_array($users) || !is_array($mail) || !is_bool($public) || !is_bool($remote)
				|| array_filter([...$users, ...$mail], fn ($value) => !is_string($value))) {
				return 'unknown';
			}
			if ($public) {
				return 'public';
			}
			if ($remote || $mail || array_diff($users, [$this->uid()])) {
				return 'shared';
			}
			return $node->getOwner()?->getUID() === $this->uid() ? 'private' : 'unknown';
		} catch (\Throwable $e) {
			return 'unknown';
		}
	}

	/** Narrow before DAV/native indexed discovery; never query a broader scope then filter its counts. */
	public function searchScope(string $requested = 'all'): string {
		$scopes = $this->currentPolicy()['sources'];
		foreach ($scopes as $scope) {
			if ($scope['kind'] === 'user-files') {
				if ($requested !== 'all') {
					$this->resolve($requested);
				}
				return $requested;
			}
		}
		if ($requested === 'all' && count($scopes) === 1 && $scopes[0]['kind'] === 'folder') {
			return $scopes[0]['resourceId'];
		}
		foreach ($scopes as $scope) {
			if ($scope['kind'] === 'folder' && $requested === $scope['resourceId']) {
				$this->resolve($requested);
				return $requested;
			}
		}
		throw new Problem('Choose a configured source folder before searching.', 403);
	}

	public function indexedContentAllowed(): bool {
		$policy = $this->currentPolicy();
		$settings = $this->settings();
		$class = $settings['classificationDefault'];
		return in_array(['kind' => 'user-files'], $policy['sources'], true) && !$settings['classificationRules']
			&& $class !== 'unknown' && in_array($class, $policy['sourceClasses'], true)
			&& ($class !== 'protected' || $policy['downstreamPolicy'] === 'configured');
	}
}
