<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

/** Provider references, never an executable URL or a client permission assertion. */
final class JournalResource {
	public const PRIVATE_TEXT_RECOVERY = 'standard_private_v1';

	public function __construct(
		public readonly string $provider,
		public readonly string $reference,
		public readonly string $authorityReference,
		public readonly ?string $recovery = null,
	) {
		if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $provider)
			|| $reference === '' || strlen($reference) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $reference)
			|| $authorityReference === '' || strlen($authorityReference) > 255 || preg_match('/[\x00-\x1f\x7f]/', $authorityReference)
			|| ($recovery !== null && $recovery !== self::PRIVATE_TEXT_RECOVERY)) {
			throw new JournalException('invalid_resource', 400);
		}
	}

	public function key(JournalIdentity $identity): string {
		return hash('sha256', json_encode([$identity->ownerHash, $identity->scopeHash, $this->provider, $this->reference], JSON_THROW_ON_ERROR));
	}

	/** Private namespaces may still address the same native object or creation target. */
	public function conflictsWith(self $other): bool {
		return $this->provider === $other->provider && ($this->reference === $other->reference
			|| ($this->provider === 'files' && str_starts_with($this->authorityReference, 'file:')
				&& $this->authorityReference === $other->authorityReference));
	}

	public function toArray(): array {
		return ['provider' => $this->provider, 'reference' => $this->reference, 'authorityReference' => $this->authorityReference,
			...($this->recovery === null ? [] : ['recovery' => $this->recovery])];
	}

	public static function fromArray(array $resource): self {
		foreach (['provider', 'reference', 'authorityReference'] as $key) {
			if (!isset($resource[$key]) || !is_string($resource[$key])) {
				throw new JournalException('invalid_resource', 400);
			}
		}
		return new self($resource['provider'], $resource['reference'], $resource['authorityReference'], $resource['recovery'] ?? null);
	}
}
