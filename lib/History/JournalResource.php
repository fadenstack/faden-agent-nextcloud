<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

/** Provider references, never an executable URL or a client permission assertion. */
final class JournalResource {
	public function __construct(
		public readonly string $provider,
		public readonly string $reference,
		public readonly string $authorityReference,
	) {
		if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $provider)
			|| $reference === '' || strlen($reference) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $reference)
			|| $authorityReference === '' || strlen($authorityReference) > 255 || preg_match('/[\x00-\x1f\x7f]/', $authorityReference)) {
			throw new JournalException('invalid_resource', 400);
		}
	}

	public function key(JournalIdentity $identity): string {
		return hash('sha256', json_encode([$identity->ownerHash, $identity->scopeHash, $this->provider, $this->reference], JSON_THROW_ON_ERROR));
	}

	public function toArray(): array {
		return ['provider' => $this->provider, 'reference' => $this->reference, 'authorityReference' => $this->authorityReference];
	}

	public static function fromArray(array $resource): self {
		foreach (['provider', 'reference', 'authorityReference'] as $key) {
			if (!isset($resource[$key]) || !is_string($resource[$key])) {
				throw new JournalException('invalid_resource', 400);
			}
		}
		return new self($resource['provider'], $resource['reference'], $resource['authorityReference']);
	}
}
