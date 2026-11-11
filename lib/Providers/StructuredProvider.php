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

/** Bounded public Tables/Deck APIs. Preflight is deliberately not advertised as CAS. */
final class StructuredProvider {
	private const KEY = 'fadenstack_agent.structured';
	public const LIMITATION = 'Native last-write-wins: another native editor can change this object after our final check. Review each action individually. Native notifications may occur. Audit only; no conflict-safe Undo or Redo.';
	private const TOOLS = ['tables' => ['nc_tables_read', 'nc_tables_add_column', 'nc_tables_add_row', 'nc_tables_update_row', 'nc_tables_delete_row'],
		'deck' => ['nc_deck_read', 'nc_deck_patch', 'nc_deck_create', 'nc_deck_move', 'nc_deck_assign', 'nc_deck_label']];
	private ?string $attempt = null;
	private bool $dispatched = false;
	public function __construct(
		private StructuredAuthority $native,
		private Configuration $configuration,
		private IUserSession $users,
		private ISession $session,
		private JournalService $journal,
		private ILockingProvider $locks,
		private StructuredTickets $tickets,
	) {
	}

	public function attemptId(): ?string {
		return $this->attempt;
	}
	public function attemptOutcome(): string {
		return $this->dispatched ? 'unknown' : 'not-dispatched';
	}
	public static function digest(mixed $value): string {
		$sort = static function (mixed $item) use (&$sort): mixed {
			if (!is_array($item)) {
				return $item;
			}
			if (!array_is_list($item)) {
				ksort($item, SORT_STRING);
			}
			return array_map($sort, $item);
		};
		return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}
	private static function id(mixed $id): int {
		if (!is_int($id) || $id < 1 || $id > 999999999999999) {
			throw new Problem('Use a positive native identifier.', 400);
		}
		return $id;
	}
	private static function text(mixed $value, int $max, bool $empty = false): string {
		if (!is_string($value) || strlen($value) > $max || (!$empty && trim($value) === '') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
			throw new Problem('Invalid bounded native text.', 400);
		}
		return $value;
	}
	private function policy(string $provider, bool $audit = false): array {
		return $this->native->policy($provider, $audit);
	}
	private function request(array $policy, string $provider, string $operation, int $id, int $item = 0, int $stack = 0, array $payload = []): array {
		return $this->native->request($policy, $provider, $operation, $id, $item, $stack, $payload);
	}
	public function authority(string $provider, int $id, bool $audit = false): array {
		return $this->native->collection($provider, $id, $audit);
	}
	public function capabilities(): array {
		$result = [];
		foreach (['tables', 'deck'] as $provider) {
			try {
				$policy = $this->policy($provider);
				$tools = [];
				foreach ($policy['collections'] as $id) {
					try {
						$authority = $this->authority($provider, (int)$id);
					} catch (Problem) {
						continue;
					}
					$tools[] = self::TOOLS[$provider][0];
					if (!$policy['writesEnabled'] || !$this->configuration->folderDispatchSupported()) {
						continue;
					}
					foreach (array_slice(self::TOOLS[$provider], 1) as $tool) {
						$right = $tool === 'nc_tables_add_column' ? 'manage' : (str_contains($tool, 'delete') ? 'delete' : (str_contains($tool, 'create') || $tool === 'nc_tables_add_row' ? 'create' : 'update'));
						if ($authority['rights'][$right]) {
							$tools[] = $tool;
						}
					}
				}
				if (!$tools) {
					continue;
				}
				$tools = array_values(array_intersect(array_unique($tools), $policy['processing']['tools']));
				if (!$tools) {
					continue;
				}
				$result[] = ['provider' => $provider, 'apiVersion' => $provider === 'tables' ? 'tables-2.4-api1' : 'deck-1.19-api1', 'policyVersion' => $policy['policyVersion'],
					'enabled' => true, 'tools' => $tools, 'conflict' => count($tools) > 1 ? 'native' : 'read-only', 'history' => 'audit-only',
					'refresh' => 'child-reload', 'canRead' => true, 'canWrite' => count($tools) > 1];
			} catch (Problem $error) {
				if ($error->status !== 403) {
					throw $error;
				}
			}
		}
		return $result;
	}
	private function snapshot(string $provider, int $id, bool $audit = false): array {
		$authority = $this->authority($provider, $id, $audit);
		$policy = $authority['policy'];
		if ($provider === 'tables') {
			$columns = $this->request($policy, $provider, 'columns', $id);
			$rows = $this->request($policy, $provider, 'rows', $id);
			if (!array_is_list($columns) || count($columns) > 50 || !array_is_list($rows) || count($rows) > 100) {
				throw new Problem('Use a table with at most 50 columns and 100 rows.', 413);
			}
			$columnIds = [];
			foreach ($columns as $column) {
				$columnId = self::id($column['id'] ?? null);
				if (isset($columnIds[$columnId]) || ($column['tableId'] ?? null) !== $id || !is_string($column['title'] ?? null) || !is_string($column['type'] ?? null)) {
					throw new Problem('Malformed native column membership.', 503);
				}
				$columnIds[$columnId] = true;
			}
			$rowIds = [];
			foreach ($rows as &$row) {
				$rowId = self::id($row['id'] ?? null);
				if (isset($rowIds[$rowId]) || ($row['tableId'] ?? null) !== $id || !is_array($row['data'] ?? null) || !array_is_list($row['data'])) {
					throw new Problem('Malformed native row membership.', 503);
				}
				$rowIds[$rowId] = true;
				$seen = [];
				foreach ($row['data'] as $cell) {
					$cid = self::id($cell['columnId'] ?? null);
					if (!isset($columnIds[$cid]) || isset($seen[$cid]) || !array_key_exists('value', $cell)) {
						throw new Problem('Malformed native cell membership.', 503);
					}
					$seen[$cid] = true;
				}
				$row['revision'] = self::digest($row);
			}
			unset($row);
			usort($columns, static fn ($a, $b) => $a['id'] <=> $b['id']);
			usort($rows, static fn ($a, $b) => $a['id'] <=> $b['id']);
			$schema = self::digest([$id, $columns]);
			$snapshot = ['table' => ['id' => $id, 'title' => $authority['data']['title']], 'columns' => $columns, 'rows' => $rows, 'schemaRevision' => $schema];
		} else {
			$stacks = $this->request($policy, $provider, 'stacks', $id);
			if (!array_is_list($stacks) || count($stacks) > 20) {
				throw new Problem('Use a board with at most 20 stacks.', 413);
			}
			$total = 0;
			$cards = [];
			$stackIds = [];
			foreach ($stacks as &$stack) {
				$sid = self::id($stack['id'] ?? null);
				if (!array_key_exists('cards', $stack)) {
					$stack['cards'] = [];
				}
				if (isset($stackIds[$sid]) || ($stack['boardId'] ?? null) !== $id || !is_array($stack['cards']) || !array_is_list($stack['cards'])) {
					throw new Problem('Malformed native stack membership.', 503);
				}
				$stackIds[$sid] = true;
				foreach ($stack['cards'] as &$card) {
					$cid = self::id($card['id'] ?? null);
					if (isset($cards[$cid]) || ($card['stackId'] ?? null) !== $sid || ++$total > 50) {
						throw new Problem('Use at most 50 verified cards in one board.', 413);
					}
					$cards[$cid] = true;
					$card['revision'] = self::digest($card);
				}
				unset($card);
			}
			unset($stack);
			$board = array_intersect_key($authority['data'], array_flip(['id', 'title', 'color', 'labels', 'users', 'permissions', 'archived']));
			$snapshot = ['id' => $id, 'board' => $board, 'stacks' => $stacks];
		}
		if ($this->policy($provider, $audit)['policyVersion'] !== $policy['policyVersion']) {
			throw new Problem('The native policy changed.', 409);
		}
		$resource = ['provider' => $provider, 'resourceId' => 'nextcloud:' . $provider . ':' . $id, 'collectionId' => (string)$id,
			'revision' => self::digest([$authority['authorityRevision'], $snapshot]), 'sourceClass' => $policy['sourceClass'],
			'audience' => $authority['audience'], 'policyVersion' => $policy['policyVersion']];
		return ['snapshot' => $snapshot, 'resource' => $resource, 'authority' => $authority];
	}
	public function read(string $provider, int $collectionId): array {
		$value = $this->snapshot($provider, $collectionId);
		return ['snapshot' => $value['snapshot'], 'resource' => $value['resource']];
	}
	private function plan(string $tool, array $args): array {
		$provider = str_starts_with($tool, 'nc_tables_') ? 'tables' : 'deck';
		if (!in_array($tool, array_slice(self::TOOLS[$provider], 1), true)) {
			throw new Problem('Unsupported structured operation.', 400);
		}
		$id = self::id($args[$provider === 'tables' ? 'tableId' : 'boardId'] ?? null);
		$value = $this->snapshot($provider, $id);
		$policy = $value['authority']['policy'];
		if (!$policy['writesEnabled'] || !$this->configuration->folderDispatchSupported()) {
			throw new Problem('Native writes are disabled.', 403);
		}
		if (($value['authority']['data']['isFederated'] ?? false) || ($value['authority']['data']['externalId'] ?? null) !== null) {
			throw new Problem('Federated native writes are not certified.', 403);
		}
		if (!in_array($tool, $policy['processing']['tools'], true)) {
			throw new Problem('This tool is outside the administrator scope.', 403);
		}
		$op = substr($tool, strlen('nc_' . $provider . '_'));
		$right = $op === 'add_column' ? 'manage' : ($op === 'delete_row' ? 'delete' : (in_array($op, ['add_row', 'create'], true) ? 'create' : 'update'));
		if (!$value['authority']['rights'][$right]) {
			throw new Problem('The current native role cannot perform this action.', 403);
		}
		$snapshot = $value['snapshot'];
		$item = 0;
		$stack = 0;
		$before = null;
		$allowed = $provider === 'tables' ? ['tableId', 'expectedSchemaRevision', ...match ($op) {
			'add_column' => ['title', 'type'], 'add_row' => ['cells'], 'update_row' => ['cells', 'rowId', 'expectedRowRevision'], 'delete_row' => ['rowId', 'expectedRowRevision'],
		}] : ['boardId', ...match ($op) {
			'create' => ['stackId', 'title', 'description', 'duedate'], 'patch' => ['cardId', 'expectedRevision', 'changes'],
			'move' => ['cardId', 'expectedRevision', 'stackId', 'order'], 'assign' => ['cardId', 'expectedRevision', 'userId'], 'label' => ['cardId', 'expectedRevision', 'labelId'],
		}];
		if (array_diff(array_keys($args), $allowed)) {
			throw new Problem('Unexpected native mutation fields.', 400);
		}
		if ($provider === 'tables') {
			if (($op === 'add_column' && count($snapshot['columns']) >= 50) || ($op === 'add_row' && count($snapshot['rows']) >= 100)) {
				throw new Problem('This creation would exceed the supported table size.', 413);
			}
			if (($args['expectedSchemaRevision'] ?? null) !== $snapshot['schemaRevision']) {
				throw new Problem('Read the current table schema before proposing a change.', 409);
			}
			if (isset($args['rowId'])) {
				$item = self::id($args['rowId']);
				$before = array_values(array_filter($snapshot['rows'], static fn ($row) => $row['id'] === $item))[0] ?? null;
				if (!$before || ($args['expectedRowRevision'] ?? null) !== $before['revision']) {
					throw new Problem('The row changed or is outside this table.', 409);
				}
			}
			if ($op === 'add_column') {
				$title = trim(self::text($args['title'] ?? null, 80));
				if (!in_array($args['type'] ?? null, ['text', 'number'], true)) {
					throw new Problem('Use a plain text or number column.', 400);
				}
				foreach ($snapshot['columns'] as $column) {
					if (mb_strtolower($column['title']) === mb_strtolower($title)) {
						throw new Problem('That column already exists.', 409);
					}
				}
				$payload = ['title' => $title, 'type' => $args['type'], 'subtype' => $args['type'] === 'text' ? 'line' : '', 'mandatory' => false];
			} elseif ($op === 'delete_row') {
				$payload = [];
			} else {
				$payload = ['data' => $this->cells($args['cells'] ?? null, $snapshot['columns'])];
			}
		} else {
			$all = [];
			foreach ($snapshot['stacks'] as $s) {
				foreach ($s['cards'] as $card) {
					$all[$card['id']] = $card;
				}
			}
			if ($op === 'create' && count($all) >= 50) {
				throw new Problem('This creation would exceed the supported board size.', 413);
			}
			if ($op !== 'create') {
				$item = self::id($args['cardId'] ?? null);
				$before = $all[$item] ?? null;
				if (!$before || ($args['expectedRevision'] ?? null) !== $before['revision'] || ($before['archived'] ?? false)) {
					throw new Problem('The card changed or is outside the active board.', 409);
				}
				$stack = $before['stackId'];
			}
			if (in_array($op, ['create', 'move'], true)) {
				$destination = self::id($args['stackId'] ?? null);
				if (!array_filter($snapshot['stacks'], static fn ($s) => $s['id'] === $destination && empty($s['deletedAt']))) {
					throw new Problem('The destination stack is outside this board.', 403);
				}
			}
			if ($op === 'create') {
				$stack = $destination;
				$payload = ['title' => trim(self::text($args['title'] ?? null, 255)), 'description' => self::text($args['description'] ?? '', 2000, true),
					'type' => 'plain', 'order' => 999, 'duedate' => self::date($args['duedate'] ?? null)];
			} elseif ($op === 'patch') {
				$changes = $args['changes'] ?? null;
				if (!is_array($changes) || !$changes || array_diff(array_keys($changes), ['title', 'description', 'duedate', 'done'])) {
					throw new Problem('Use supported card fields.', 400);
				}
				$payload = array_intersect_key($before, array_flip(['title', 'description', 'type', 'owner', 'order', 'duedate', 'startdate', 'archived', 'done', 'color']));
				$payload['owner'] = is_array($payload['owner']) ? ($payload['owner']['primaryKey'] ?? $payload['owner']['uid'] ?? '') : $payload['owner'];
				foreach ($changes as $key => $data) {
					$payload[$key] = in_array($key, ['duedate', 'done'], true) ? self::date($data) : ($key === 'title' ? trim(self::text($data, 255)) : self::text($data, 4000, true));
				}
			} elseif ($op === 'move') {
				$order = $args['order'] ?? 0;
				if (!is_int($order) || $order < 0 || $order > 10000) {
					throw new Problem('Use an integer card order between 0 and 10000.', 400);
				}
				$payload = ['stackId' => $destination, 'order' => $order];
			} elseif ($op === 'assign') {
				$user = self::text($args['userId'] ?? null, 80);
				$members = array_map(static fn ($u) => is_array($u) ? ($u['primaryKey'] ?? $u['uid'] ?? '') : '', $snapshot['board']['users'] ?? []);
				if (!in_array($user, $members, true)) {
					throw new Problem('Only a current native board member can be assigned.', 403);
				}
				$payload = ['userId' => $user, 'type' => 0];
			} else {
				$label = self::id($args['labelId'] ?? null);
				if (!array_filter($snapshot['board']['labels'] ?? [], static fn ($l) => $l['id'] === $label)) {
					throw new Problem('The label is outside this board.', 403);
				}
				$payload = ['labelId' => $label];
			}
		}
		$limitation = self::LIMITATION . ($op === 'move' ? ' Moving reorders neighboring cards and applies the destination stack’s native completion rules.' : '');
		$display = [];
		if ($provider === 'tables') {
			if ($op === 'add_column') {
				$display[] = ['label' => 'New column', 'before' => null, 'after' => $payload['title'] . ' (' . $payload['type'] . ')'];
			} else {
				$previous = $before ? array_column($before['data'], 'value', 'columnId') : [];
				$values = $op === 'delete_row' ? $previous : $payload['data'];
				foreach ($values as $cid => $data) {
					$column = array_values(array_filter($snapshot['columns'], static fn ($c) => $c['id'] === (int)$cid))[0];
					$display[] = ['label' => $column['title'], 'before' => $previous[$cid] ?? null, 'after' => $op === 'delete_row' ? null : $data];
				}
			}
		} elseif ($op === 'create' || $op === 'patch') {
			foreach ($op === 'patch' ? $args['changes'] : array_intersect_key($payload, array_flip(['title', 'description', 'duedate'])) as $key => $data) {
				$display[] = ['label' => ['title' => 'Title', 'description' => 'Description', 'duedate' => 'Due date', 'done' => 'Completed at'][$key], 'before' => $before[$key] ?? null, 'after' => $payload[$key]];
			}
		} elseif ($op === 'move') {
			$source = array_values(array_filter($snapshot['stacks'], static fn ($s) => $s['id'] === $stack))[0];
			$target = array_values(array_filter($snapshot['stacks'], static fn ($s) => $s['id'] === $destination))[0];
			$display[] = ['label' => 'Stack', 'before' => $source['title'], 'after' => $target['title']];
			$display[] = ['label' => 'Position', 'before' => $before['order'], 'after' => $payload['order']];
		} elseif ($op === 'assign') {
			$display[] = ['label' => 'Add assignee', 'before' => null, 'after' => $payload['userId']];
		} else {
			$label = array_values(array_filter($snapshot['board']['labels'], static fn ($l) => $l['id'] === $payload['labelId']))[0];
			$display[] = ['label' => 'Add label', 'before' => null, 'after' => $label['title']];
		}
		return ['provider' => $provider, 'toolName' => $tool, 'operation' => $op, 'collectionId' => $id, 'itemId' => $item, 'stackId' => $stack,
			'args' => $args, 'payload' => $payload, 'before' => $before, 'target' => ucfirst($provider) . ' ' . $id . ': ' . ($provider === 'tables' ? $snapshot['table']['title'] : $snapshot['board']['title']) . ($item ? ', ' . ($provider === 'tables' ? 'row ' : 'card ') . $item : ''),
			'resource' => $value['resource'], 'authorityRevision' => $value['authority']['authorityRevision'], 'display' => $display, 'limitation' => $limitation, 'history' => 'audit-only'];
	}
	private function cells(mixed $cells, array $columns): array {
		if (!is_array($cells) || !$cells || count($cells) > 12) {
			throw new Problem('Use 1–12 targeted cells.', 400);
		}
		$result = [];
		foreach ($cells as $key => $value) {
			if (!preg_match('/^[1-9][0-9]{0,14}$/D', (string)$key)) {
				throw new Problem('Use native column identifiers.', 400);
			}
			$column = array_values(array_filter($columns, static fn ($c) => $c['id'] === (int)$key))[0] ?? null;
			if (!$column || !in_array($column['type'], ['text', 'number'], true) || ($column['type'] === 'text' && !in_array($column['subtype'] ?? '', ['', 'line'], true))) {
				throw new Problem('Only native plain text and number columns can be edited.', 400);
			}
			if ($column['type'] === 'text') {
				$result[$key] = self::text($value, 2000, true);
			} elseif ((is_int($value) || is_float($value)) && is_finite((float)$value)) {
				$result[$key] = $value;
			} else {
				throw new Problem('A finite numeric cell value is required.', 400);
			}
		}
		return $result;
	}
	private static function date(mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
			throw new Problem('Use an ISO date with timezone or null.', 400);
		}
		try {
			$date = new \DateTimeImmutable($value);
			$errors = \DateTimeImmutable::getLastErrors();
			if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
				throw new \InvalidArgumentException();
			}
			return $date->format('Y-m-d\TH:i:sP');
		} catch (\Throwable) {
			throw new Problem('Invalid native date.', 400);
		}
	}
	private function state(): array {
		$value = $this->session->get(self::KEY);
		return is_array($value) ? $value : [];
	}
	private function identity(): JournalIdentity {
		return new JournalIdentity($this->users->getUser()?->getUID() ?? throw new Problem('Sign in to Nextcloud.', 401), JournalIdentity::nativeScope(), hash('sha256', $this->session->getId()));
	}
	private function binding(array $resource): string {
		return self::digest([$this->identity()->userId, $this->session->getId(), $this->configuration->get(), $resource['policyVersion']]);
	}
	private function view(array $p): array {
		return array_intersect_key($p, array_flip(['id', 'digest', 'toolName', 'provider', 'target', 'before', 'payload', 'resource', 'display', 'limitation', 'history', 'expires']));
	}
	public function propose(string $toolName, array $args, string $requestId): array {
		$this->tickets->start($this->identity(), $requestId);
		try {
			$p = $this->plan($toolName, $args);
		} catch (\Throwable $error) {
			$this->tickets->cancel($this->identity(), $requestId);
			throw $error;
		}
		$p['id'] = $requestId;
		$p['expires'] = time() + 600;
		$p['approved'] = false;
		$p['binding'] = $this->binding($p['resource']);
		$p['digest'] = self::digest($p);
		$state = array_filter($this->state(), static fn ($item) => $item['expires'] > time());
		if (count($state) >= 20) {
			throw new Problem('Too many pending native previews.', 429);
		}
		$state[$p['id']] = $p;
		$this->session->set(self::KEY, $state);
		if (!$this->tickets->move($this->identity(), $requestId, ['preparing'], 'prepared')) {
			throw new Problem('This native preview was cancelled.', 410);
		}
		return $this->view($p);
	}
	private function load(string $id, string $digest): array {
		if (!preg_match('/^[a-f0-9]{48}$/D', $id) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
			throw new Problem('Invalid native preview.', 400);
		}
		$p = $this->state()[$id] ?? null;
		if (!$p || $p['expires'] <= time() || !hash_equals($p['digest'], $digest)
			|| !in_array($this->tickets->state($this->identity(), $id), ['prepared', 'approving', 'approved'], true)) {
			throw new Problem('The preview expired, was cancelled or was consumed.', 410);
		}
		return $p;
	}
	private function validate(array $p): void {
		$now = $this->plan($p['toolName'], $p['args']);
		if (!hash_equals($p['binding'], $this->binding($now['resource'])) || !hash_equals(self::digest(array_intersect_key($p, $now)), self::digest($now))) {
			throw new Problem('The approved native object, permissions or policy changed.', 409);
		}
	}
	public function approve(string $id, string $digest): array {
		$p = $this->load($id, $digest);
		$this->validate($p);
		if (!$p['approved']) {
			if (!$this->tickets->move($this->identity(), $id, ['prepared'], 'approving')) {
				throw new Problem('This native preview is no longer pending.', 410);
			}
			$kind = $p['provider'] === 'deck' ? 'card' : ($p['operation'] === 'add_column' ? 'column' : 'row');
			$reference = 'collection:' . $p['collectionId'] . '/' . $kind . ':' . ($p['itemId'] ?: $p['id']);
			$entry = $this->journal->prepare($this->identity(), new JournalResource($p['provider'], $reference, 'collection:' . $p['collectionId']), $p['toolName'], $p, [], ['nativeRevision' => $p['resource']['revision']], 600);
			$p['actionId'] = $entry['id'];
			$p['ticket'] = $entry['ticket'];
			$p['approved'] = true;
			if (!$this->tickets->move($this->identity(), $id, ['approving'], 'approved')) {
				$this->journal->abort($this->identity(), $p['actionId'], $p['ticket']);
				throw new Problem('Approval was cancelled before dispatch.', 410);
			}
			$state = $this->state();
			$state[$id] = $p;
			$this->session->set(self::KEY, $state);
		}
		return ['approved' => true];
	}
	public function cancel(string $id, string $digest): array {
		// The request nonce is known before the asynchronous preview returns.
		// A cancellation tombstone cannot be revived by a late sibling request.
		$cancelled = $this->tickets->cancel($this->identity(), $id);
		if (!$cancelled) {
			return ['cancelled' => false, 'outcome' => 'dispatched-or-finished'];
		}
		$p = $this->state()[$id] ?? null;
		$state = $this->state();
		unset($state[$id]);
		$this->session->set(self::KEY, $state);
		if (isset($p['ticket'])) {
			try {
				$this->journal->abort($this->identity(), $p['actionId'], $p['ticket']);
			} catch (\Throwable) { /* durable journal still retains the claim */
			}
		}
		return ['cancelled' => true];
	}
	public function commit(string $id, string $digest): array {
		$p = $this->load($id, $digest);
		if (!$p['approved'] || !isset($p['ticket'])) {
			throw new Problem('Individual approval is required.', 403);
		}
		$this->attempt = $p['actionId'];
		$lock = 'fadenstack:structured:' . $p['provider'] . ':' . $p['collectionId'];
		$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		$identity = $this->identity();
		$dispatched = false;
		try {
			$this->validate($p);
			if (!$this->tickets->move($identity, $id, ['approved'], 'dispatching')) {
				throw new Problem('This native action was cancelled or already dispatched.', 410);
			}
			$state = $this->state();
			unset($state[$id]);
			$this->session->set(self::KEY, $state);
			$this->journal->markDispatched($identity, $p['actionId'], $p['ticket']);
			$dispatched = true;
			$this->dispatched = true;
			$policy = $this->policy($p['provider']);
			$response = $this->request($policy, $p['provider'], $p['operation'], $p['collectionId'], $p['itemId'], $p['stackId'], $p['payload']);
			$after = $this->snapshot($p['provider'], $p['collectionId']);
			$result = $this->verify($p, $response, $after['snapshot']);
			$kind = $p['provider'] === 'deck' ? 'card' : ($p['operation'] === 'add_column' ? 'column' : 'row');
			// Keep the journal's immutable operation reference. Only refine its native
			// authority after verifying the new ID, as existing Files creation does.
			$reference = 'collection:' . $p['collectionId'] . '/' . $kind . ':' . ($p['itemId'] ?: $p['id']);
			$actual = new JournalResource($p['provider'], $reference, 'collection:' . $p['collectionId'] . '/' . $kind . ':' . $result['id']);
			$this->journal->finish($identity, $p['actionId'], $p['ticket'], 'verified', new VerifiedReadback($after['resource']['revision'], $actual));
			$this->tickets->move($identity, $id, ['dispatching'], 'finished');
			return ['status' => $p['operation'] === 'delete_row' ? 'deleted' : 'saved', 'outcome' => 'verified', 'actionId' => $p['actionId'],
				'target' => $p['target'], 'provider' => $p['provider'], 'collectionId' => $p['collectionId'], 'result' => $result,
				'resource' => $after['resource'], 'history' => 'audit-only', 'limitation' => self::LIMITATION];
		} catch (\Throwable $error) {
			try {
				if ($dispatched) {
					$this->journal->finish($identity, $p['actionId'], $p['ticket'], 'unknown');
				} else {
					$this->journal->abort($identity, $p['actionId'], $p['ticket']);
				}
			} catch (\Throwable) { /* retain durable uncertainty */
			}
			$this->tickets->move($identity, $id, ['prepared', 'approving', 'approved', 'dispatching'], 'finished');
			$state = $this->state();
			unset($state[$id]);
			$this->session->set(self::KEY, $state);
			throw $error;
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
	private function verify(array $p, array $response, array $snapshot): array {
		if ($p['provider'] === 'tables') {
			$id = $p['itemId'] ?: self::id($response['id'] ?? null);
			$items = $p['operation'] === 'add_column' ? $snapshot['columns'] : $snapshot['rows'];
			$item = array_values(array_filter($items, static fn ($row) => $row['id'] === $id))[0] ?? null;
			if ($p['operation'] === 'delete_row') {
				if ($item !== null || ($response['id'] ?? null) !== $id || ($response['tableId'] ?? null) !== $p['collectionId']) {
					throw new Problem('Native row deletion could not be verified.', 503);
				}
				return ['id' => $id, 'deleted' => true];
			}
			if (!$item || ($item['tableId'] ?? null) !== $p['collectionId']) {
				throw new Problem('Native result membership could not be verified.', 503);
			}
			if ($p['operation'] === 'add_column') {
				foreach (['title', 'type', 'subtype', 'mandatory'] as $key) {
					if (($item[$key] ?? null) !== $p['payload'][$key]) {
						throw new Problem('The saved column differs from the approved payload.', 503);
					}
				}
			} else {
				$cells = array_column($item['data'], 'value', 'columnId');
				$expected = $p['payload']['data'];
				if ($p['before']) {
					foreach ($p['before']['data'] as $cell) {
						if (!array_key_exists($cell['columnId'], $expected)) {
							$expected[$cell['columnId']] = $cell['value'];
						}
					}
				}
				foreach ($expected as $key => $value) {
					if (!array_key_exists($key, $cells) || !$this->equal($cells[$key], $value)) {
						throw new Problem('The saved row differs from the approved cells or untouched fields.', 503);
					}
				}
			}
			return ['id' => $id];
		}
		$id = $p['itemId'] ?: self::id($response['id'] ?? null);
		$card = null;
		foreach ($snapshot['stacks'] as $stack) {
			foreach ($stack['cards'] as $candidate) {
				if ($candidate['id'] === $id) {
					$card = $candidate;
				}
			}
		}
		if (!$card) {
			throw new Problem('The native card could not be read back.', 503);
		}
		$expected = match ($p['operation']) {
			'move' => $p['payload'], 'assign', 'label' => [], default => $p['payload']
		};
		foreach ($expected as $key => $value) {
			if ($key === 'owner') {
				$actual = is_array($card[$key] ?? null) ? ($card[$key]['primaryKey'] ?? $card[$key]['uid'] ?? '') : ($card[$key] ?? null);
			} else {
				$actual = $card[$key] ?? null;
			}
			if (in_array($key, ['duedate', 'startdate', 'done'], true) && $actual !== null && $value !== null) {
				if (strtotime($actual) !== strtotime($value)) {
					throw new Problem('The saved card date differs from the approved payload.', 503);
				}
			} elseif ($actual !== $value) {
				throw new Problem('The saved card differs from the approved payload.', 503);
			}
		}
		if ($p['operation'] === 'assign') {
			$assigned = array_map(static fn ($u) => $u['participant']['uid'] ?? $u['participant']['primaryKey'] ?? $u['participant'] ?? '', $card['assignedUsers'] ?? []);
			if (!in_array($p['payload']['userId'], $assigned, true)) {
				throw new Problem('The native assignment could not be verified.', 503);
			}
		}
		if ($p['operation'] === 'label' && !array_filter($card['labels'] ?? [], static fn ($l) => $l['id'] === $p['payload']['labelId'])) {
			throw new Problem('The native card label could not be verified.', 503);
		}
		if ($p['before']) {
			foreach (['title', 'description', 'type', 'owner', 'order', 'stackId', 'duedate', 'startdate', 'color', 'archived', 'done'] as $key) {
				if (array_key_exists($key, $p['payload']) || ($p['operation'] === 'move' && $key === 'done')) {
					continue;
				}
				$before = $p['before'][$key] ?? null;
				$observed = $card[$key] ?? null;
				if ($key === 'owner') {
					$before = is_array($before) ? ($before['primaryKey'] ?? $before['uid'] ?? '') : $before;
					$observed = is_array($observed) ? ($observed['primaryKey'] ?? $observed['uid'] ?? '') : $observed;
				}
				if ($observed !== $before) {
					throw new Problem('An untouched card field changed; inspect the native result.', 503);
				}
			}
			foreach (['labels', 'assignedUsers'] as $key) {
				$beforeIds = array_column($p['before'][$key] ?? [], 'id');
				$afterIds = array_column($card[$key] ?? [], 'id');
				if (array_diff($beforeIds, $afterIds)) {
					throw new Problem('An existing card label or assignment changed; inspect the result.', 503);
				}
			}
		}
		return ['id' => $id, 'stackId' => $card['stackId']];
	}
	private function equal(mixed $a, mixed $b): bool {
		return $a === $b || ((is_int($b) || is_float($b)) && is_numeric($a) && (float)$a === (float)$b);
	}

	/** Public native authorization for the existing journal resolver and read-only audit. */
	public function authorizeResource(JournalResource $resource, bool $write = false): array {
		return $this->native->resource($resource, $write);
	}
	public function actions(): array {
		$result = [];
		foreach ($this->journal->list($this->identity(), null, ['tables', 'deck'], 50) as $entry) {
			try {
				$result[] = $this->auditItem($entry);
			} catch (\Throwable) {
				continue;
			}
		}
		return $result;
	}
	private function auditItem(array $entry): array {
		$resource = JournalResource::fromArray($entry['resource']);
		$value = $this->authorizeResource($resource);
		return ['id' => $entry['id'], 'provider' => $resource->provider, 'name' => $value['data']['title'], 'target' => ucfirst($resource->provider) . ' ' . $value['data']['id'],
			'state' => $entry['state'], 'eligible' => 'none', 'auditOnly' => true, 'version' => $entry['version'], 'createdAt' => $entry['createdAt']];
	}
	public function inspect(string $id): array {
		if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
			throw new Problem('Unavailable native action.', 404);
		}
		$entry = $this->journal->describe($this->identity(), $id);
		$item = $this->auditItem($entry);
		$resource = JournalResource::fromArray($entry['resource']);
		$authority = $this->authorizeResource($resource);
		$current = $this->snapshot($resource->provider, $authority['data']['id'], true)['snapshot'];
		$found = false;
		if (preg_match('/\/(row|column|card):([1-9][0-9]{0,14})$/D', $resource->authorityReference, $match)
			|| preg_match('/\/(row|column|card):([1-9][0-9]{0,14})$/D', $resource->reference, $match)) {
			if ($resource->provider === 'tables') {
				foreach ($match[1] === 'row' ? $current['rows'] : $current['columns'] as $row) {
					if ($row['id'] === (int)$match[2]) {
						$found = true;
					}
				}
			} else {
				foreach ($current['stacks'] as $stack) {
					foreach ($stack['cards'] as $card) {
						if ($card['id'] === (int)$match[2]) {
							$found = true;
						}
					}
				}
			}
		}
		return ['action' => $item, 'observed' => $found ? 'present' : 'not_in_active_collection', 'journalState' => $entry['state'], 'outcome' => $entry['state'] === 'applied' ? 'verified_committed' : 'unknown',
			'repeatedDispatch' => false, 'message' => 'The recorded outcome is retained. Current collection access does not prove the row or card is unchanged. Inspection sends no write. No Undo is certified.'];
	}
}
