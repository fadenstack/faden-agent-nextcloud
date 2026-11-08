<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;

/** Closed native engine routes. There is deliberately no URL/header/method tool. */
final class EngineRequest {
	private function __construct(
		public readonly string $actor,
		public readonly string $connection,
		public readonly string $provider,
		public readonly string $stamp,
		public readonly string $path,
		public readonly string $method = 'GET',
		public readonly ?string $body = null,
		public readonly bool $nullable = false,
		public readonly bool $jobAck = false,
	) {
	}
	public static function probe(string $actor, array $endpoint): self {
		$path = $endpoint['provider'] === 'windmill' ? '/api/w/' . rawurlencode($endpoint['workspace']) . '/users/whoami' : '/api/v1/workflows?limit=1';
		return new self($actor, $endpoint['id'], $endpoint['provider'], $endpoint['stamp'], $path);
	}
	public static function list(string $actor, array $endpoint): self {
		$path = $endpoint['provider'] === 'windmill' ? '/api/w/' . rawurlencode($endpoint['workspace']) . '/flows/list?per_page=21&page=0&show_archived=false' : '/api/v1/workflows?limit=21&excludePinnedData=true';
		return new self($actor, $endpoint['id'], $endpoint['provider'], $endpoint['stamp'], $path);
	}
	public static function definition(string $actor, array $endpoint, string $reference): self {
		if ($endpoint['provider'] === 'windmill') {
			if (strlen($reference) > 255 || !preg_match('~^[uf]/[a-zA-Z0-9_-]+(/[a-zA-Z0-9_-]+)+$~D', $reference)) {
				throw new Problem('Choose a canonical Windmill flow path.', 400);
			}
			$path = '/api/w/' . rawurlencode($endpoint['workspace']) . '/flows/get/' . implode('/', array_map('rawurlencode', explode('/', $reference)));
		} else {
			if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $reference)) {
				throw new Problem('Choose a canonical n8n workflow identifier.', 400);
			}
			$path = '/api/v1/workflows/' . rawurlencode($reference) . '?excludePinnedData=true';
		}
		return new self($actor, $endpoint['id'], $endpoint['provider'], $endpoint['stamp'], $path);
	}
	public static function ownDraft(string $actor, array $endpoint, string $path): self {
		self::windmillPath($actor, $endpoint, $path);
		return new self($actor, $endpoint['id'], 'windmill', $endpoint['stamp'], '/api/w/' . rawurlencode($endpoint['workspace']) . '/drafts/get_own/flow/' . $path, nullable: true);
	}
	private static function windmillPath(string $actor, array $endpoint, string $path): void {
		if ($endpoint['provider'] !== 'windmill') {
			throw new Problem('Choose a Windmill draft connection.', 400);
		}
		self::definition($actor, $endpoint, $path);
	}
	public static function saveOwnDraft(string $actor, array $endpoint, string $path, string $definition): self {
		self::windmillPath($actor, $endpoint, $path);
		$value = EngineJson::decode($definition);
		if (!$value instanceof \stdClass) {
			throw new Problem('A new Windmill draft needs an object definition.', 400);
		}
		return new self($actor, $endpoint['id'], 'windmill', $endpoint['stamp'], '/api/w/' . rawurlencode($endpoint['workspace']) . '/drafts/update/flow/' . $path,
			'POST', EngineJson::encode(['value' => $value, 'last_sync' => '1970-01-01T00:00:00Z', 'force' => false]));
	}
	public static function findN8nDraft(string $actor, array $endpoint, string $name): self {
		if ($endpoint['provider'] !== 'n8n' || $name === '' || strlen($name) > 200) {
			throw new Problem('Choose one n8n draft name.', 400);
		}
		return new self($actor, $endpoint['id'], 'n8n', $endpoint['stamp'], '/api/v1/workflows?limit=2&excludePinnedData=true&name=' . rawurlencode($name));
	}
	public static function createN8nDraft(string $actor, array $endpoint, string $definition): self {
		if ($endpoint['provider'] !== 'n8n') {
			throw new Problem('Choose an n8n draft connection.', 400);
		}
		$value = EngineJson::decode($definition);
		if (!$value instanceof \stdClass || property_exists($value, 'active') || property_exists($value, 'projectId') || property_exists($value, 'shared')) {
			throw new Problem('Use a new inactive personal n8n draft.', 400);
		}
		return new self($actor, $endpoint['id'], 'n8n', $endpoint['stamp'], '/api/v1/workflows', 'POST', EngineJson::encode($value));
	}
	public static function windmillRun(string $actor, array $endpoint, string $version, string $jobId, string $args): self {
		if ($endpoint['provider'] !== 'windmill' || !preg_match('/^[1-9][0-9]{0,18}$/D', $version)
			|| (string)(int)$version !== $version || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $jobId)
			|| !EngineJson::decode($args) instanceof \stdClass) {
			throw new Problem('Choose one exact deployed version, unique job ID and bounded argument object.', 400);
		}
		return new self($actor, $endpoint['id'], 'windmill', $endpoint['stamp'], '/api/w/' . rawurlencode($endpoint['workspace']) . '/jobs/run/fv/' . $version . '?job_id=' . $jobId,
			'POST', $args, jobAck: true);
	}
	public static function windmillJob(string $actor, array $endpoint, string $jobId): self {
		if ($endpoint['provider'] !== 'windmill' || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $jobId)) {
			throw new Problem('Choose a canonical Windmill job ID.', 400);
		}
		return new self($actor, $endpoint['id'], 'windmill', $endpoint['stamp'], '/api/w/' . rawurlencode($endpoint['workspace']) . '/jobs_u/get/' . $jobId . '?no_logs=true&no_code=true');
	}
}
