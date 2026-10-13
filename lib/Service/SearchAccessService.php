<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Service;

/** Validated WebDAV query plans; the native DAV backend does the actual search. */
class SearchAccessService {
	public function __construct(
		private FileService $files,
	) {
	}

	public static function filters(array $input): array {
		$allowed = ['query', 'scope', 'file_types', 'modified_after', 'modified_before', 'created_after', 'min_size', 'max_size', 'favorites_only', 'sort_by', 'sort_direction', 'limit'];
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
				$date = is_string($f[$key]) ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $f[$key]) : false;
				$errors = \DateTimeImmutable::getLastErrors();
				if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
					throw new Problem('Dates must be valid ISO 8601 timestamps with a timezone.');
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
		$scope = $this->files->searchScope($f['scope']);
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
			$queries[] = '<?xml version="1.0" encoding="UTF-8"?><d:searchrequest xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:basicsearch>'
				. '<d:select><d:prop><oc:fileid/></d:prop></d:select><d:from><d:scope><d:href>' . self::xml($scope['href'])
				. '</d:href><d:depth>infinity</d:depth></d:scope></d:from><d:where><d:and>' . implode('', $conditions) . $tier
				. '</d:and></d:where><d:orderby>' . $order . '</d:orderby><d:limit><d:nresults>' . ($f['limit'] + 1)
				. '</d:nresults></d:limit></d:basicsearch></d:searchrequest>';
		}
		return ['filters' => $f, 'queries' => $queries, 'backend' => 'webdav-search'];
	}

	public function resolve(array $resourceIds, string $scope = 'all'): array {
		if (!array_is_list($resourceIds) || count($resourceIds) > 51) {
			throw new Problem('Resolve at most 51 search results per request.');
		}
		$folder = $scope === 'all' ? null : $this->files->resource($scope);
		if ($folder && !$folder['folder']) {
			throw new Problem('Search scope must be a folder.');
		}
		$results = [];
		foreach (array_unique($resourceIds) as $id) {
			if (!is_string($id) || !preg_match('/^nextcloud:files:[1-9][0-9]{0,14}$/D', $id)) {
				throw new Problem('Invalid file resource ID.');
			}
			try {
				$f = $this->files->resource($id);
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
}
