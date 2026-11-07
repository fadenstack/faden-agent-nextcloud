<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;

/** Final bounded native payloads; no model-controlled endpoint or recipe. */
final class ContentPlan {
	public const TOOLS = ['collectives' => ['nc_collectives_read', 'nc_collectives_create_page', 'nc_collectives_delete_page'],
		'forms' => ['nc_forms_read', 'nc_forms_create_form', 'nc_forms_submit_response'], 'notes' => ['nc_notes_read', 'nc_notes_create'], 'bookmarks' => ['nc_bookmarks_read', 'nc_bookmarks_create']];
	public const LIMITATION = 'Native last-write-wins: native editors are not locked by our final check. Individual approval applies to this exact payload and audience. Native notifications may occur. Stop prevents subsequent dispatch, not an in-flight native request. Partial private Forms and known native identifiers are retained; an uncertain request is never repeated automatically. Audit only; no Undo or Redo. Collaborative bodies are never replaced.';
	public function __construct(
		private ContentAuthority $native,
		private Configuration $configuration,
	) {
	}
	public static function text(mixed $value, int $limit, bool $empty = false): string {
		if (!is_string($value) || mb_strlen($value) > $limit || (!$empty && trim($value) === '') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
			throw new Problem('Use bounded native content text.', 400);
		}
		return $value;
	}
	private static function title(mixed $value, int $limit = 200): string {
		$value = trim(self::text($value, $limit));
		if (preg_match('~[\r\n/\\\\]~', $value) || in_array($value, ['.', '..'], true)) {
			throw new Problem('Use a native title without path separators.', 400);
		}
		return $value;
	}
	public function resource(string $provider, string $collection, array $authority, mixed $snapshot, array $selection = []): array {
		if ($provider === 'forms' && ($authority['data']['ownerId'] ?? null) === $authority['policy']['actor'] && in_array('personal', $authority['policy']['collections'], true)) {
			$collection = 'personal';
		}
		return ['provider' => $provider, 'collectionId' => $collection,
			'resourceId' => 'nextcloud:' . $provider . ':' . rtrim(strtr(base64_encode($collection . "\0" . json_encode($selection ?: new \stdClass(), JSON_THROW_ON_ERROR)), '+/', '-_'), '='),
			'revision' => StructuredProvider::digest([$authority['authorityRevision'], $snapshot]), 'policyVersion' => $authority['policy']['policyVersion'],
			'sourceClass' => $authority['policy']['sourceClass'], 'audience' => $authority['audience'], 'filename' => json_encode($selection ?: new \stdClass(), JSON_THROW_ON_ERROR)];
	}
	public function read(string $provider, array $args, bool $audit = false): array {
		if (!isset(self::TOOLS[$provider])) {
			throw new Problem('Unsupported native content provider.', 400);
		}
		$allowed = match ($provider) {
			'collectives' => ['collectiveId'], 'forms' => ['formId'], 'notes' => ['category'], 'bookmarks' => ['query']
		};
		if (array_diff(array_keys($args), $allowed)) {
			throw new Problem('Unexpected native content read fields.', 400);
		}
		$policy = $this->native->policy($provider, $audit);
		$id = match ($provider) {
			'collectives' => isset($args['collectiveId']) ? (string)ContentAuthority::id($args['collectiveId']) : $policy['collections'][0], 'forms' => isset($args['formId']) ? (string)ContentAuthority::id($args['formId']) : (in_array('personal', $policy['collections'], true) ? 'personal' : $policy['collections'][0]), default => 'personal'
		};
		$value = $this->native->collection($provider, $id, $audit);
		if ($provider === 'collectives') {
			$pages = ContentAuthority::boundedList($this->native->request($policy, $provider, 'pages', (int)$id)['pages'] ?? null, 100);
			$pages = array_map(static fn ($p) => array_intersect_key($p, array_flip(['id', 'parentId', 'title', 'timestamp', 'size', 'fileName', 'filePath'])), $pages);
			$snapshot = ['collective' => array_intersect_key($value['data'], array_flip(['id', 'name', 'canEdit', 'level'])), 'pages' => $pages];
		} elseif ($provider === 'forms') {
			if ($id === 'personal') {
				$snapshot = ['forms' => array_map(static fn ($f) => array_intersect_key($f, array_flip(['id', 'title', 'permissions', 'state', 'submissionCount'])), $value['data'])];
			} else {
				$form = array_diff_key($value['data'], array_flip(['hash', 'lockedBy', 'lockedUntil', 'confirmationEmailBody', 'confirmationEmailSubject']));
				$form['shares'] = array_map(static fn ($share) => array_intersect_key($share, array_flip(['id', 'shareType', 'shareWith', 'displayName', 'permissions'])), $form['shares'] ?? []);
				$snapshot = ['form' => $form];
			}
		} elseif ($provider === 'notes') {
			$category = isset($args['category']) ? self::text($args['category'], 200, true) : null;
			$notes = ContentAuthority::boundedList($this->native->request($policy, $provider, 'list'));
			// Native first-use discovery can initialize the configured Notes folder.
			// Bind the resulting public authority, rather than the pre-initialization path.
			$value = $this->native->collection($provider, $id, $audit);
			$notes = array_values(array_filter($notes, static fn ($n) => $category === null || ($n['category'] ?? null) === $category));
			foreach ($notes as $note) {
				if (($note['error'] ?? false) !== false) {
					throw new Problem('The native note body could not be read.', 503);
				}
				self::text($note['content'] ?? null, 65536, true);
				self::text($note['title'] ?? null, 255);
			}
			$snapshot = ['notes' => $notes, 'destination' => $value['data']['notesPath'], 'audience' => 'unknown'];
		} else {
			$query = isset($args['query']) ? self::text($args['query'], 200, true) : '';
			$items = ContentAuthority::boundedList($this->native->request($policy, $provider, 'list')['data'] ?? null);
			$snapshot = ['bookmarks' => array_values(array_filter($items, static fn ($b) => $query === '' || mb_stripos(($b['title'] ?? '') . ' ' . ($b['url'] ?? ''), $query) !== false)), 'complete' => true];
		}
		if ($this->native->policy($provider, $audit)['policyVersion'] !== $policy['policyVersion']) {
			throw new Problem('The content policy changed.', 409);
		}
		return [...$snapshot, 'resource' => $this->resource($provider, $id, $value, $snapshot, $args)];
	}
	public function prepare(string $tool, array $args): array {
		$provider = explode('_', $tool)[1] ?? '';
		if (!isset(self::TOOLS[$provider]) || !in_array($tool, array_slice(self::TOOLS[$provider], 1), true)) {
			throw new Problem('Unsupported native content mutation.', 400);
		}
		$policy = $this->native->policy($provider);
		if (!$policy['writesEnabled'] || !$this->configuration->folderDispatchSupported() || !in_array($tool, $policy['processing']['tools'], true)) {
			throw new Problem('This native content write is disabled.', 403);
		}
		$operation = substr($tool, strlen('nc_' . $provider . '_'));
		$allowed = match ($tool) {
			'nc_collectives_create_page' => ['collectiveId', 'parentId', 'title'], 'nc_collectives_delete_page' => ['pageId'],
			'nc_forms_create_form' => ['title', 'questions'], 'nc_forms_submit_response' => ['formId', 'answers'],
			'nc_notes_create' => ['title', 'content', 'category'], 'nc_bookmarks_create' => ['url', 'title', 'tags'],
		};
		if (array_diff(array_keys($args), $allowed)) {
			throw new Problem('Unexpected native content mutation fields.', 400);
		}
		$before = null;
		$destinationBinding = null;
		$steps = [];
		$item = 0;
		if ($provider === 'collectives') {
			if ($operation === 'create_page') {
				$id = (string)ContentAuthority::id($args['collectiveId'] ?? null);
			} else {
				$item = ContentAuthority::id($args['pageId'] ?? null);
				$matches = [];
				foreach ($policy['collections'] as $candidate) {
					try {
						$snapshot = $this->read($provider, ['collectiveId' => (int)$candidate]);
					} catch (Problem $e) {
						if (in_array($e->status, [403, 404], true)) {
							continue;
						} throw $e;
					}
					foreach ($snapshot['pages'] as $page) {
						if ($page['id'] === $item) {
							$matches[] = [$candidate, $page];
						}
					}
				}
				if (count($matches) !== 1) {
					throw new Problem('The page must belong to one configured collective.', 403);
				}
				[$id, $before] = $matches[0];
			}
			$value = $this->native->collection($provider, $id);
			$current = $this->read($provider, ['collectiveId' => (int)$id]);
			if (!$value['canWrite']) {
				throw new Problem('Current native collective edit permission is required.', 403);
			}
			if ($operation === 'create_page') {
				$item = isset($args['parentId']) ? ContentAuthority::id($args['parentId']) : (array_values(array_filter($current['pages'], static fn ($p) => $p['parentId'] === 0))[0]['id'] ?? 0);
				if (!array_filter($current['pages'], static fn ($p) => $p['id'] === $item)) {
					throw new Problem('The parent is outside this collective.', 403);
				}
				$title = self::title($args['title'] ?? null, 255);
				foreach ($current['pages'] as $page) {
					if ($page['parentId'] === $item && mb_strtolower($page['title']) === mb_strtolower($title)) {
						throw new Problem('That native page title already exists.', 409);
					}
				}
				$payload = ['title' => $title];
			} else {
				if (!$before['parentId'] || array_filter($current['pages'], static fn ($p) => $p['parentId'] === $item)) {
					throw new Problem('Only a leaf page may be moved to the native trash. The home page and subtrees require the native app.', 403);
				}
				$payload = [];
			}
			$resource = $current['resource'];
			$target = 'Collective ' . $value['data']['name'] . ': ' . ($payload['title'] ?? $before['title']);
		} elseif ($provider === 'forms') {
			$id = $operation === 'create_form' ? 'personal' : (string)ContentAuthority::id($args['formId'] ?? null);
			$value = $this->native->collection($provider, $id);
			if (!$value['canWrite']) {
				throw new Problem('Current native Forms permission is required.', 403);
			}
			if ($operation === 'create_form') {
				$payload = ['title' => self::title($args['title'] ?? null, 255), 'questions' => self::questions($args['questions'] ?? null)];
				$steps = [['operation' => 'create_form'], ['operation' => 'configure_form']];
				foreach ($payload['questions'] as $index => $q) {
					$steps[] = ['operation' => 'create_question', 'question' => $index];
					if ($q['isRequired']) {
						$steps[] = ['operation' => 'configure_question', 'question' => $index];
					}
					if ($q['options']) {
						$steps[] = ['operation' => 'create_options', 'question' => $index];
					}
				}
				$before = null;
				$resource = $this->read('forms', [])['resource'];
			} else {
				$before = $value['data'];
				if (($before['confirmationEmailEnabled'] ?? false) || ($before['fileId'] ?? null) !== null) {
					throw new Problem('Forms with confirmation emails or linked exports require the native submission UI.', 403);
				}
				$payload = ['answers' => self::answers($args['answers'] ?? null, $before['questions'])];
				$resource = $this->read('forms', ['formId' => (int)$id])['resource'];
			}
			$target = $operation === 'create_form' ? 'New private form: ' . $payload['title'] : 'Submit to ' . $before['title'] . ' (owner ' . $before['ownerId'] . ')';
		} elseif ($provider === 'notes') {
			$id = 'personal';
			$current = $this->read($provider, []);
			$value = $this->native->collection($provider, $id);
			$category = self::text($args['category'] ?? '', 200, true);
			$destination = $this->native->notesDestination($policy, $value['data'], $category);
			if (!$destination['canWrite']) {
				throw new Problem('Current native Notes category creation permission is required.', 403);
			}
			$destinationBinding = $destination['binding'];
			foreach (explode('/', $category) as $part) {
				if ($part !== '') {
					self::title($part);
				}
			}
			$payload = ['title' => self::title($args['title'] ?? null), 'content' => self::text($args['content'] ?? null, 65536, true), 'category' => $category];
			foreach ($current['notes'] as $note) {
				if (($note['category'] ?? '') === $category && mb_strtolower($note['title']) === mb_strtolower($payload['title'])) {
					throw new Problem('That note title already exists. Existing note bodies are never replaced.', 409);
				}
			}
			$resource = $current['resource'];
			$target = 'New note: ' . $value['data']['notesPath'] . '/' . ($category === '' ? '' : $category . '/') . $payload['title'];
		} else {
			$id = 'personal';
			$value = $this->native->collection($provider, $id);
			$url = self::bookmarkUrl($args['url'] ?? null);
			$parts = parse_url($url);
			if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || !str_contains($parts['host'] ?? '', '.') || filter_var($parts['host'], FILTER_VALIDATE_IP) || preg_match('/(?:^|\.)(?:localhost|local|internal)$/i', $parts['host'])) {
				throw new Problem('Use a public HTTP(S) URL without credentials, a custom port, or a local/IP host.', 400);
			}
			$tags = $args['tags'] ?? [];
			if (!is_array($tags) || !array_is_list($tags) || count($tags) > 20) {
				throw new Problem('Use at most 20 bookmark tags.', 400);
			}
			$tags = array_map(static fn ($tag) => trim(self::text($tag, 80)), $tags);
			if (count(array_unique($tags)) !== count($tags)) {
				throw new Problem('Bookmark tags must be unique.', 400);
			}
			// Supplying both title and description prevents native synchronous
			// scraping. The separately disclosed background crawl still occurs.
			$payload = ['url' => $url, 'title' => isset($args['title']) ? trim(self::text($args['title'], 255)) : $url, 'description' => '', 'tags' => $tags];
			$current = $this->read($provider, []);
			foreach ($current['bookmarks'] as $bookmark) {
				if (($bookmark['url'] ?? null) === $url) {
					throw new Problem('That bookmark already exists. The create tool will not merge or overwrite it.', 409);
				}
			}
			$resource = $current['resource'];
			$target = 'New bookmark: ' . $url;
		}
		if (!$value['canWrite']) {
			throw new Problem('The native content destination or URL-fetch protection does not permit this write.', 403);
		}
		if (!in_array($value['audience'], $policy['processing']['destinationAudiences'], true) || !in_array(['source' => $policy['sourceClass'], 'destination' => $value['audience']], $policy['processing']['movements'], true)) {
			throw new Problem('The native destination audience is outside the movement policy.', 403);
		}
		$display = [['label' => 'Native account', 'after' => $policy['actor']], ['label' => 'Native destination', 'after' => $target]];
		if ($provider === 'notes') {
			$display = [...$display, ['label' => 'Title', 'after' => $payload['title']], ['label' => 'Body', 'after' => $payload['content']], ['label' => 'Category', 'after' => $payload['category'] === '' ? 'Uncategorized' : $payload['category']]];
		}
		if ($provider === 'forms' && $operation === 'create_form') {
			$display[] = ['label' => 'Title', 'after' => $payload['title']];
			foreach ($payload['questions'] as $i => $q) {
				$display[] = ['label' => 'Question ' . ($i + 1) . ': ' . $q['text'], 'after' => match ($q['type']) {
					'multiple_unique' => 'Single choice', 'multiple' => 'Checkboxes', 'short' => 'Short text', 'long' => 'Long text'
				} . ($q['isRequired'] ? ' · Required' : ' · Optional') . ($q['options'] ? "\n" . implode("\n", $q['options']) : '')];
			}
		}
		if ($provider === 'collectives') {
			$display[] = ['label' => $operation === 'create_page' ? 'Native parent page' : 'Page to move to trash', 'after' => '#' . $item . ($before ? ': ' . $before['title'] : '')];
		}
		if ($provider === 'bookmarks') {
			$display = [...$display, ['label' => 'URL', 'after' => $payload['url']], ['label' => 'Title', 'after' => $payload['title']], ['label' => 'Tags', 'after' => implode(', ', $payload['tags']) ?: 'None']];
			$display[] = ['label' => 'Native URL fetching', 'after' => 'Bookmarks queues a crawl of this URL. Its configured metadata, favicon, preview and archive services may fetch the target or contact external services. The destination website can observe Nextcloud’s request.'];
		}
		if ($tool === 'nc_forms_submit_response') {
			foreach ($before['questions'] as $q) {
				$answers = $payload['answers'][$q['id']] ?? [];
				if (in_array($q['type'], ['multiple_unique', 'multiple'], true)) {
					$answers = array_map(static fn ($id) => (array_values(array_filter($q['options'], static fn ($o) => (string)$o['id'] === $id))[0]['text'] ?? 'Unavailable option') . ' (option ' . $id . ')', $answers);
				}
				$display[] = ['label' => $q['text'] . ' (question ' . $q['id'] . ')', 'after' => $answers ? implode("\n", $answers) : 'No answer'];
			}
			$display[] = ['label' => 'Irreversible submission', 'after' => 'Answers are submitted as the signed-in native user; the form owner and authorized results readers can see them. Native owner notifications may occur. Submission cannot be undone.'];
		}
		return ['provider' => $provider, 'toolName' => $tool, 'args' => $args, 'operation' => $operation, 'collectionId' => $id, 'itemId' => $item, 'payload' => $payload, 'before' => $before, 'destinationBinding' => $destinationBinding,
			'target' => $target, 'resource' => $resource, 'authority' => $value['authorityRevision'], 'display' => $display, 'steps' => $steps, 'history' => 'audit-only', 'limitation' => self::LIMITATION];
	}
	/** Restricted canonical URL surface avoids native WHATWG normalization merging an existing bookmark. */
	public static function bookmarkUrl(mixed $input): string {
		$url = trim(self::text($input, 2048));
		$parts = parse_url($url);
		if (!is_array($parts) || !preg_match('/^https?:\/\//D', $url) || preg_match('/[^\x21-\x7e]|\\\\/', $url) || strpbrk($url, "\"<>`^{}'") !== false
			|| !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/D', $parts['host'] ?? '')
			|| isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || preg_match('/%(?![a-fA-F0-9]{2})/', $url)) {
			throw new Problem('Use a canonical public ASCII HTTP(S) URL without credentials or a custom port.', 400);
		}
		$path = $parts['path'] ?? '/';
		if (preg_match('~(?:^|/)(?:\.|%2e){1,2}(?:/|$)~i', $path)) {
			throw new Problem('Normalize the bookmark URL path before proposing it.', 400);
		}
		return $parts['scheme'] . '://' . $parts['host'] . $path . (isset($parts['query']) ? '?' . $parts['query'] : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
	}
	private static function questions(mixed $questions): array {
		if (!is_array($questions) || !array_is_list($questions) || count($questions) < 1 || count($questions) > 50) {
			throw new Problem('Use between 1 and 50 questions.', 400);
		}
		$result = [];
		foreach ($questions as $q) {
			if (!is_array($q) || array_diff(array_keys($q), ['text', 'type', 'mandatory', 'options']) || !in_array($q['type'] ?? null, ['short', 'long', 'multiple', 'checkbox'], true) || (isset($q['mandatory']) && !is_bool($q['mandatory']))) {
				throw new Problem('Unsupported native Forms question.', 400);
			}
			$options = $q['options'] ?? [];
			if (!is_array($options) || !array_is_list($options) || count($options) > 50 || (in_array($q['type'], ['multiple', 'checkbox'], true) ? count($options) < 2 : $options !== [])) {
				throw new Problem('Choice questions need 2–50 distinct options; text questions have none.', 400);
			}
			$options = array_map(static fn ($s) => trim(self::text($s, 500)), $options);
			if (count(array_unique($options)) !== count($options)) {
				throw new Problem('Duplicate choice labels would change answer meaning.', 400);
			}
			$result[] = ['text' => self::text($q['text'] ?? null, 500), 'type' => match ($q['type']) {
				'multiple' => 'multiple_unique', 'checkbox' => 'multiple', default => $q['type']
			}, 'isRequired' => $q['mandatory'] ?? false, 'options' => $options];
		}
		if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 100000) {
			throw new Problem('Narrow the aggregate form definition.', 413);
		}
		return $result;
	}
	private static function answers(mixed $answers, array $questions): array {
		if (!is_array($answers) || count($answers) > 50) {
			throw new Problem('Use answers keyed by native question ID.', 400);
		}
		$result = [];
		foreach ($answers as $id => $answer) {
			if (!preg_match('/^[1-9][0-9]{0,14}$/D', (string)$id)) {
				throw new Problem('Use native question IDs.', 400);
			}
			$q = array_values(array_filter($questions, static fn ($q) => $q['id'] === (int)$id))[0] ?? null;
			if (!$q || !in_array($q['type'], ['short', 'long', 'multiple_unique', 'multiple'], true)) {
				throw new Problem('Unsupported or foreign native question.', 400);
			}
			$values = is_array($answer) && array_is_list($answer) ? $answer : [$answer];
			if (count($values) > 50 || ($q['type'] !== 'multiple' && count($values) > 1)) {
				throw new Problem('Answer cardinality does not match the native question.', 400);
			}
			$values = array_map(static fn ($v) => self::text($v, 4000, true), $values);
			if (in_array($q['type'], ['multiple_unique', 'multiple'], true)) {
				foreach ($values as $v) {
					if (!ctype_digit($v) || !in_array((int)$v, array_column($q['options'], 'id'), true)) {
						throw new Problem('Use native option IDs, preserving their displayed meaning.', 400);
					}
				}
			}
			if (count(array_unique($values)) !== count($values)) {
				throw new Problem('Duplicate answers are unsupported.', 400);
			}
			$result[$id] = $values;
		}
		foreach ($questions as $q) {
			if (($q['isRequired'] ?? false) && (!isset($result[$q['id']]) || !$result[$q['id']] || $result[$q['id']] === [''])) {
				throw new Problem('Every required question needs an answer.', 400);
			}
		}
		return $result;
	}
}
