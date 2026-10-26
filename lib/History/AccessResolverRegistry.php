<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

final class AccessResolverRegistry implements AccessResolver {
	/** @var array<string, AccessResolver> */
	private array $resolvers = [];

	public function register(string $provider, AccessResolver $resolver): void {
		if (isset($this->resolvers[$provider])) {
			throw new JournalException('duplicate_provider', 500);
		}
		$this->resolvers[$provider] = $resolver;
	}

	public function assertAccess(JournalIdentity $identity, JournalResource $resource, string $purpose): void {
		$resolver = $this->resolvers[$resource->provider] ?? null;
		if ($resolver === null) {
			throw new JournalException('provider_unavailable', 403);
		}
		$resolver->assertAccess($identity, $resource, $purpose);
	}
}
