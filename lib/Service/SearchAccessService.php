<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Service;

use OCP\App\IAppManager;
use OCP\FullTextSearch\IFullTextSearchManager;

/** Validated WebDAV query plans; the native DAV backend does the actual search. */
class SearchAccessService {
	public function __construct(
		private FileService $files,
		private IAppManager $apps,
		private IFullTextSearchManager $fulltext,
	) {
	}

	public static function filters(array $input): array {
		$allowed = ['query', 'scope', 'file_types', 'modified_after', 'modified_before', 'created_after', 'min_size', 'max_size', 'favorites_only', 'sort_by', 'sort_direction', 'limit', 'cursor'];
		if (array_diff(array_keys($input), $allowed)) {
			throw new Problem('Unsupported search parameter.');
		}
		$f = ['query' => '', 'scope' => 'all', 'file_types' => [], 'favorites_only' => false, 'sort_by' => 'relevance', 'sort_direction' => 'desc', 'limit' => 20, ...$input];
		if (!is_string($f['query']) || mb_strlen($f['query']) > 200 || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $f['query'])) {
			throw new Problem('Search query must be a filename or phrase of at most 200 characters, without a path.');
		}
		if (!is_string($f['scope']) || ($f['scope'] !== 'all' && !preg_match('/^nextcloud:files:[1-9][0-9]{0,14}$/D', $f['scope']))) {
			throw new Problem('Invalid search scope resource ID.');
		}
		if (!is_array($f['file_types']) || !array_is_list($f['file_types']) || count($f['file_types']) > 12) {
			throw new Problem('Use at most 12 file extensions or MIME types.');
		}
		foreach ($f['file_types'] as $type) {
			if (!is_string($type) || !preg_match('/^(\.?[a-zA-Z0-9]{1,16}|[a-zA-Z0-9.+-]+\/[a-zA-Z0-9.+*-]+)$/D', $type)) {
				throw new Problem('Invalid file extension or MIME type.');
			}
		}
		foreach (['modified_after', 'modified_before', 'created_after'] as $key) {
			if (array_key_exists($key, $f)) {
				$value = $f[$key];
				$valid = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/D', $value);
				$date = $valid ? \DateTimeImmutable::createFromFormat(str_contains($value, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP', $value) : false;
				$errors = \DateTimeImmutable::getLastErrors();
				if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
					throw new Problem('Dates must be valid ISO 8601 timestamps with a timezone.');
				}
				if ($key !== 'modified_before' && (int)$date->format('u') > 0) {
					$date = $date->modify('+1 second');
				}
				$f[$key] = $date->format(\DateTimeInterface::ATOM);
			}
		}
		foreach (['min_size', 'max_size'] as $key) {
			if (array_key_exists($key, $f) && (!is_int($f[$key]) || $f[$key] < 0 || $f[$key] > 9007199254740991)) {
				throw new Problem('Size bounds must be nonnegative safe integers.');
			}
		}
		if ((isset($f['min_size'], $f['max_size']) && $f['min_size'] > $f['max_size'])
			|| (isset($f['modified_after'], $f['modified_before']) && strtotime($f['modified_after']) > strtotime($f['modified_before']))) {
			throw new Problem('Search lower bounds must not exceed upper bounds.');
		}
		if (!is_bool($f['favorites_only']) || !is_int($f['limit']) || $f['limit'] < 1 || $f['limit'] > 50
			|| !in_array($f['sort_by'], ['relevance', 'modified', 'name', 'size'], true)
			|| !in_array($f['sort_direction'], ['asc', 'desc'], true)) {
			throw new Problem('Invalid search ordering, favorites flag or limit (1–50).');
		}
		if (array_key_exists('cursor', $f) && (!is_string($f['cursor']) || strlen($f['cursor']) > 512)) {
			throw new Problem('Invalid search cursor.');
		}
		return $f;
	}

	private static function xml(string $s): string {
		return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
	private static function comparison(string $op, string $property, string $literal): string {
		return '<d:' . $op . '><d:prop><' . $property . '/></d:prop><d:literal>' . self::xml($literal) . '</d:literal></d:' . $op . '>';
	}

	public function plan(array $filters): array {
		$f = self::filters($filters);
		if (isset($f['created_after']) && \OCP\Server::get(\OCP\ServerVersion::class)->getMajorVersion() < 35) {
			throw new Problem('Creation-date filtering requires the verified Nextcloud 35 WebDAV search backend. Use modification-date filters on this version.', 422);
		}
		$scope = $this->files->searchScope($f['scope']);
		$canPage = ($scope['can_page'] ?? false) && $f['sort_by'] !== 'relevance';
		$cursor = $f['cursor'] ?? null;
		unset($f['cursor']);
		$canonical = $f;
		ksort($canonical);
		$digest = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
		$offset = 0;
		$binding = $canPage ? $this->files->identity()['binding'] : '';
		if ($cursor !== null) {
			if (!$canPage || !is_string($cursor) || !str_contains($cursor, '.')) {
				throw new Problem('Native paging requires modified/name/size ordering in a single-storage scope.');
			}
			[$payload, $signature] = explode('.', $cursor, 2);
			if (!hash_equals(hash_hmac('sha256', $payload, $binding), $signature)) {
				throw new Problem('Invalid or cross-session search cursor.');
			}
			$data = json_decode(base64_decode(strtr($payload, '-_', '+/'), true) ?: '', true);
			if (!is_array($data) || ($data['query'] ?? '') !== $digest || ($data['expires'] ?? 0) < time()
				|| !is_int($data['offset'] ?? null) || $data['offset'] < 0 || $data['offset'] > 5000) {
				throw new Problem('Search cursor expired or filters changed. Repeat the same filters or start a new search.');
			}
			$offset = $data['offset'];
		}
		$conditions = [self::comparison('gte', 'oc:size', '0'), '<d:not>' . self::comparison('eq', 'oc:fileid', (string)$scope['id']) . '</d:not>'];
		foreach (['modified_after' => ['gte', 'd:getlastmodified'], 'modified_before' => ['lte', 'd:getlastmodified'], 'created_after' => ['gte', 'd:creationdate'], 'min_size' => ['gte', 'oc:size'], 'max_size' => ['lte', 'oc:size']] as $key => [$op, $property]) {
			if (isset($f[$key])) {
				$conditions[] = self::comparison($op, $property, (string)$f[$key]);
			}
		}
		if ($f['favorites_only']) {
			$conditions[] = self::comparison('eq', 'oc:favorite', 'yes');
		}
		if ($f['file_types']) {
			$types = [];
			foreach ($f['file_types'] as $type) {
				$types[] = str_contains($type, '/')
					? self::comparison(str_contains($type, '*') ? 'like' : 'eq', 'd:getcontenttype', str_replace('*', '%', $type))
					: self::comparison('like', 'd:displayname', '%.' . strtolower(ltrim($type, '.')));
			}
			$conditions[] = '<d:or>' . implode('', $types) . '</d:or>';
		}
		$q = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['query']);
		$exact = self::comparison('eq', 'd:displayname', $f['query']);
		$prefix = self::comparison('like', 'd:displayname', $q . '%');
		$contains = self::comparison('like', 'd:displayname', '%' . $q . '%');
		$tiers = $f['query'] === '' ? [''] : ($f['sort_by'] !== 'relevance' ? [$contains] : [
			$exact, '<d:and>' . $prefix . '<d:not>' . $exact . '</d:not></d:and>',
			'<d:and>' . $contains . '<d:not>' . $prefix . '</d:not></d:and>',
		]);
		$property = ['modified' => 'd:getlastmodified', 'name' => 'd:displayname', 'size' => 'oc:size', 'relevance' => 'd:getlastmodified'][$f['sort_by']];
		$direction = $f['sort_by'] === 'relevance' ? 'descending' : ($f['sort_direction'] === 'asc' ? 'ascending' : 'descending');
		$order = '<d:order><d:prop><' . $property . '/></d:prop><d:' . $direction . '/></d:order>'
			. '<d:order><d:prop><d:displayname/></d:prop><d:ascending/></d:order>';
		$queries = [];
		foreach ($tiers as $tier) {
			$queries[] = '<?xml version="1.0" encoding="UTF-8"?><d:searchrequest xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:s="https://github.com/icewind1991/SearchDAV/ns"><d:basicsearch>'
				. '<d:select><d:prop><oc:fileid/></d:prop></d:select><d:from><d:scope><d:href>' . self::xml($scope['href'])
				. '</d:href><d:depth>infinity</d:depth></d:scope></d:from><d:where><d:and>' . implode('', $conditions) . $tier
				. '</d:and></d:where><d:orderby>' . $order . '</d:orderby><d:limit><d:nresults>' . ($f['limit'] + 1)
				. '</d:nresults><s:firstresult>' . $offset . '</s:firstresult></d:limit></d:basicsearch></d:searchrequest>';
		}
		$next = null;
		if ($canPage && $offset + $f['limit'] <= 5000) {
			$payload = rtrim(strtr(base64_encode(json_encode(['query' => $digest, 'offset' => $offset + $f['limit'], 'expires' => time() + 600], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
			$next = $payload . '.' . hash_hmac('sha256', $payload, $binding);
		}
		return ['filters' => $f, 'queries' => $queries, 'backend' => 'webdav-search', 'next_cursor' => $next];
	}

	public function resolve(array $resourceIds, string $scope = 'all', bool $content = false): array {
		if (!array_is_list($resourceIds) || count($resourceIds) > 51) {
			throw new Problem('Resolve at most 51 search results per request.');
		}
		$folder = $scope === 'all' ? null : $this->files->resource($scope);
		if ($folder && !$folder['folder']) {
			throw new Problem('Search scope must be a folder.');
		}
		$results = [];
		foreach ($resourceIds as $id) {
			if (!is_string($id) || !preg_match('/^nextcloud:files:[1-9][0-9]{0,14}$/D', $id)) {
				throw new Problem('Invalid file resource ID.');
			}
		}
		foreach (array_unique($resourceIds) as $id) {
			if (!is_string($id) || !preg_match('/^nextcloud:files:[1-9][0-9]{0,14}$/D', $id)) {
				throw new Problem('Invalid file resource ID.');
			}
			try {
				$f = $this->files->resource($id);
				if ($content) {
					if ($f['folder']) {
						continue;
					}
					$this->files->assertContentAccess($id);
				}
				if ($folder && !str_starts_with($f['path'], rtrim($folder['path'], '/') . '/')) {
					continue;
				}
				$results[] = $f;
			} catch (Problem $e) {
				if (!in_array($e->status, [403, 404], true)) {
					throw $e;
				}
			} catch (\OCP\Files\NotFoundException|\OCP\Files\ForbiddenException|\OCP\Files\NotPermittedException $e) {
				// A deleted/revoked result is omitted, never disclosed from stale DAV data.
			}
		}
		return $results;
	}

	public function capabilities(): array {
		$available = $this->apps->isEnabledForUser('fulltextsearch')
			&& $this->apps->isEnabledForUser('files_fulltextsearch')
			&& $this->fulltext->isAvailable() && $this->fulltext->isProviderIndexed('files');
		return ['filename_search' => true, 'document_search' => true, 'indexed_content_search' => $available,
			'provider' => $available ? 'nextcloud-fulltextsearch:files' : null,
			'explanation' => $available ? 'Searches the native file index. Verify passages with document search; provider metadata/comments may also match.'
				: 'Cross-document content search is unavailable: no registered, indexed native Files full-text provider was detected. Filename search is a separate capability.'];
	}

	public function content(string $query, int $limit = 20, int $page = 1): array {
		if ($query === '' || mb_strlen($query) > 200 || preg_match('/[\x00-\x1f\x7f]/', $query) || $limit < 1 || $limit > 50 || $page < 1 || $page > 100) {
			throw new Problem('Use a search phrase of 1–200 characters, limit 1–50 and page 1–100.');
		}
		$status = $this->capabilities();
		if (!$status['indexed_content_search']) {
			return ['available' => false, ...$status, 'results' => []];
		}
		try {
			$groups = $this->fulltext->search(['providers' => ['files'], 'search' => $query, 'size' => $limit, 'page' => $page]);
		} catch (\Throwable $e) {
			throw new Problem('The native content-search provider could not complete this query. No filename fallback was performed.', 503);
		}
		$results = [];
		$received = 0;
		foreach ($groups as $group) {
			foreach ($group->getDocuments() as $document) {
				if ($received >= $limit) {
					break 2;
				}
				$received++;
				$id = 'nextcloud:files:' . $document->getId();
				if (!preg_match('/^nextcloud:files:[1-9][0-9]{0,14}$/D', $id)) {
					continue;
				}
				$authorized = $this->resolve([$id], 'all', true);
				if (!$authorized) {
					continue;
				}
				$excerpts = $document->getExcerpts();
				$excerpt = isset($excerpts[0]['excerpt']) && is_string($excerpts[0]['excerpt'])
					? mb_substr(strip_tags($excerpts[0]['excerpt']), 0, 600) : null;
				$results[] = ['file' => $authorized[0], 'excerpt' => $excerpt];
			}
		}
		return ['available' => true, 'provider' => $status['provider'], 'results' => $results, 'partial' => $received >= $limit,
			'next_page' => $received >= $limit && $page < 100 ? $page + 1 : null,
			'explanation' => $status['explanation']];
	}
}
