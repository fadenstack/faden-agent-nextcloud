<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;

/** Native personal authority; saved Whiteboard bytes are not a live canvas. */
final class WorkflowAuthority {
	public const VERSIONS = ['whiteboard' => '2.1.2', 'flow' => '3.0.0'];
	public function __construct(
		private NativeApiBridge $bridge,
		private ProviderPolicy $policies,
		private ScopePolicy $files,
		private IRootFolder $root,
		private IAppManager $apps,
		private IUserSession $users,
	) {
	}
	public function policy(string $provider, bool $audit = false): array {
		$policy = $this->policies->workflow($provider, $audit);
		$this->policies->assertDisclosure($policy);
		$app = $provider === 'flow' ? 'workflowengine' : 'whiteboard';
		if (!$this->apps->isEnabledForUser($app, $this->users->getUser()) || $this->apps->getAppVersion($app) !== self::VERSIONS[$provider]
			|| ($provider === 'flow' && (!$this->apps->isEnabledForUser('files_automatedtagging', $this->users->getUser()) || $this->apps->getAppVersion('files_automatedtagging') !== '6.0.1'))) {
			throw new Problem('This native workflow app version has not been enabled and tested.', 403);
		}
		return $policy;
	}
	public function request(array $policy, string $operation, int $id = 0, array $payload = []): mixed {
		$response = $this->bridge->send(NativeRequest::flow($policy['actor'], $operation, $id, $payload));
		try {
			$data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			throw new Problem('Malformed native Flow JSON.', 503);
		}
		if (!is_array($data) || ($data['ocs']['meta']['status'] ?? null) !== 'ok' || !in_array($data['ocs']['meta']['statuscode'] ?? null, [100, 200, 201], true) || !array_key_exists('data', $data['ocs'] ?? [])) {
			throw new Problem('The native Flow operation was refused. Native password confirmation may require a handoff.', 503);
		}
		return $data['ocs']['data'];
	}
	public function rules(array $policy): array {
		$data = $this->request($policy, 'list');
		return self::nativeRules($data, $policy['actor']);
	}
	/** Native GET adds scope metadata absent from POST. Verify before projecting. */
	public static function nativeRules(mixed $data, string $actor): array {
		if (!is_array($data)) {
			throw new Problem('Malformed native personal Flow collection.', 503);
		}
		if (count($data) > 50) {
			throw new Problem('Narrow the personal Flow collection.', 413);
		}
		$rules = [];
		foreach ($data as $class => $group) {
			if (!is_string($class) || !is_array($group) || !array_is_list($group)) {
				throw new Problem('Unverified native Flow grouping.', 503);
			}
			foreach ($group as $rule) {
				$id = is_array($rule) ? ($rule['id'] ?? null) : null;
				if ((!is_int($id) && !is_string($id)) || ($rule['class'] ?? null) !== $class || !preg_match('/^[1-9][0-9]{0,14}$/D', (string)$id) || isset($rules[(int)$id])) {
					throw new Problem('Unverified native personal rule membership.', 503);
				}
				if (!in_array($rule['scope_type'] ?? null, [1, '1'], true) || ($rule['scope_actor_id'] ?? null) !== $actor) {
					throw new Problem('Unverified native personal Flow owner.', 403);
				}
				unset($rule['scope_type'], $rule['scope_actor_id']);
				$rules[(int)$rule['id']] = $rule;
			}
		}
		if (count($rules) > 50) {
			throw new Problem('Narrow the personal Flow collection.', 413);
		}
		return $rules;
	}
	public function tag(array $policy, int $id): array {
		$x = DavResponse::document($this->bridge->send(NativeRequest::flowTag($policy['actor'], $id)), 'http://owncloud.org/ns');
		$rows = $x->query('/d:multistatus/d:response');
		$row = $rows->item(0);
		if ($rows->length !== 1 || (string)$x->evaluate('string(d:propstat/d:prop/c:id)', $row) !== (string)$id
			|| (string)$x->evaluate('string(d:propstat/d:prop/c:user-visible)', $row) !== 'true'
			|| (string)$x->evaluate('string(d:propstat/d:prop/c:user-assignable)', $row) !== 'true') {
			throw new Problem('Only a currently visible and assignable native tag can be used.', 403);
		}
		return ['id' => $id, 'name' => ContentPlan::text((string)$x->evaluate('string(d:propstat/d:prop/c:display-name)', $row), 255)];
	}
	public function tags(array $policy): array {
		$x = DavResponse::document($this->bridge->send(NativeRequest::flowTags($policy['actor'])), 'http://owncloud.org/ns');
		$rows = $x->query('/d:multistatus/d:response');
		if ($rows->length > 51) {
			throw new Problem('Narrow the native tag collection in Flow.', 413);
		}
		$tags = [];
		foreach ($rows as $row) {
			$id = (string)$x->evaluate('string(d:propstat/d:prop/c:id)', $row);
			if ($id === '') {
				continue;
			} // DAV collection itself has no tag ID.
			if (!preg_match('/^[1-9][0-9]{0,14}$/D', $id) || isset($tags[$id])) {
				throw new Problem('Unverified native tag membership.', 503);
			}
			if ((string)$x->evaluate('string(d:propstat/d:prop/c:user-visible)', $row) === 'true' && (string)$x->evaluate('string(d:propstat/d:prop/c:user-assignable)', $row) === 'true') {
				$tags[$id] = ['id' => (int)$id, 'name' => ContentPlan::text((string)$x->evaluate('string(d:propstat/d:prop/c:display-name)', $row), 255)];
			}
		}
		return array_values($tags);
	}
	/** Resolve paths and existing Files references under the original Files policy. */
	public function node(array $policy, string $reference, string $purpose): array {
		$home = $this->root->getUserFolder($policy['actor']);
		if (preg_match('/^nextcloud:files:([1-9][0-9]{0,14})$/D', $reference, $m)) {
			$nodes = $home->getById((int)$m[1]);
		} else {
			if (strlen($reference) > 2048 || str_contains($reference, '\\') || str_contains($reference, ':')) {
				throw new Problem('Use a native Files reference or relative path.', 400);
			}
			$path = ltrim($reference, '/');
			$parts = $path === '' ? [] : explode('/', $path);
			if (count($parts) > 32) {
				throw new Problem('Narrow the native folder depth.', 413);
			}
			foreach ($parts as $part) {
				NativeRequest::segment($part);
			}
			try {
				$nodes = [$path === '' ? $home : $home->get($path)];
			} catch (\OCP\Files\NotFoundException) {
				throw new Problem('The native source is unavailable.', 404);
			}
		}
		foreach ($nodes as $node) {
			try {
				$scope = $this->files->describeNode($node, $purpose);
				$this->policies->assertDisclosure($policy, $scope['sourceClass']);
				return ['node' => $node, 'scope' => $scope, 'path' => ltrim($home->getRelativePath($node->getPath()), '/')];
			} catch (Problem $error) {
				if (!in_array($error->status, [403, 404], true)) {
					throw $error;
				}
			}
		}
		throw new Problem('The native source is unavailable in this agent scope.', 403);
	}
	public function board(array $policy, string $path): array {
		$value = $this->node($policy, $path, 'read');
		$file = $value['node'];
		if (!$file instanceof File || !str_ends_with($file->getName(), '.whiteboard') || $file->getSize() > 262144) {
			throw new Problem('Use one saved whiteboard up to 256 KiB.', 413);
		}
		$before = $file->getEtag();
		$stream = $file->fopen('rb');
		if (!is_resource($stream)) {
			throw new Problem('The stored board cannot be read.', 403);
		}
		try {
			$raw = stream_get_contents($stream, 262145);
		} finally {
			fclose($stream);
		}
		if (!is_string($raw) || strlen($raw) > 262144 || $file->getEtag() !== $before) {
			throw new Problem('The saved board changed or is too large.', 409);
		}
		try {
			$scene = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			throw new Problem('The saved board is not bounded native JSON.', 400);
		}
		if (!$scene instanceof \stdClass || !is_array($scene->elements ?? null) || count($scene->elements) > 1000 || isset($scene->libraryRef)) {
			throw new Problem('Use a saved scene, not a library pointer.', 400);
		}
		return [...$value, 'scene' => $scene, 'digest' => hash('sha256', $raw), 'revision' => $before];
	}
	public function destination(array $policy, string $path, string $name): array {
		NativeRequest::segment($name);
		if (!str_ends_with($name, '.whiteboard') || mb_strlen($name) > 100) {
			throw new Problem('Use a whiteboard filename of at most 100 characters.', 400);
		}
		$value = $this->node($policy, $path, 'write');
		$folder = $value['node'];
		if (!$folder instanceof Folder || !$folder->getStorage()->isLocal()) {
			throw new Problem('Use a tested local native folder.', 403);
		}
		if ($folder->nodeExists($name)) {
			throw new Problem('The destination already exists. Nothing will be replaced.', 409);
		}
		$audience = $value['scope']['audience'];
		if ($audience === 'unknown' || !in_array($audience, $policy['processing']['destinationAudiences'], true)) {
			throw new Problem('This board destination audience is not permitted.', 403);
		}
		return $value;
	}
	public function createBoard(array $policy, string $folderPath, string $name): array {
		return $this->bridge->send(NativeRequest::createWhiteboard($policy['actor'], $folderPath, $name));
	}
	/** Discovery checks native ACLs without reading scenes or granting a target. */
	public function boardScopeFlags(array $policy): array {
		$home = $this->root->getUserFolder($policy['actor']);
		$result = ['read' => false, 'create' => false];
		foreach (['read' => 'sources', 'create' => 'destinations'] as $operation => $key) {
			foreach ($policy['processing'][$key] as $scope) {
				if ($scope['kind'] === 'user-files') {
					$nodes = [$home];
				} elseif (preg_match('/^nextcloud:files:([1-9][0-9]{0,14})$/D', $scope['resourceId'] ?? '', $m)) {
					$nodes = $home->getById((int)$m[1]);
				} else {
					continue;
				}
				foreach ($nodes as $node) {
					if (!$node->isReadable()) {
						continue;
					}
					try {
						// A readable collection can contain differently classified
						// children. Actual scene/target disclosure is checked per call.
						$grant = $this->files->describeNode($node, $operation === 'create' ? 'write' : 'metadata');
						if ($operation === 'read' && ($node instanceof Folder || ($node instanceof File && str_ends_with($node->getName(), '.whiteboard')))) {
							if ($node instanceof File) {
								$this->policies->assertDisclosure($policy, $grant['sourceClass']);
							}
							$result['read'] = true;
						} elseif ($operation === 'create' && $node instanceof Folder && $node->isCreatable() && $node->getStorage()->isLocal()
							&& $grant['audience'] !== 'unknown' && in_array($grant['audience'], $policy['processing']['destinationAudiences'], true)) {
							$result['create'] = true;
						}
					} catch (Problem $error) {
						if (!in_array($error->status, [403, 404], true)) {
							throw $error;
						}
					}
				}
			}
		}
		return $result;
	}
}
