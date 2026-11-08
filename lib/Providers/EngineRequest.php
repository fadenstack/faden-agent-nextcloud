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
			$path = '/api/w/' . rawurlencode($endpoint['workspace']) . '/flows/get/p/' . implode('/', array_map('rawurlencode', explode('/', $reference)));
		} else {
			if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $reference)) {
				throw new Problem('Choose a canonical n8n workflow identifier.', 400);
			}
			$path = '/api/v1/workflows/' . rawurlencode($reference) . '?excludePinnedData=true';
		}
		return new self($actor, $endpoint['id'], $endpoint['provider'], $endpoint['stamp'], $path);
	}
}
