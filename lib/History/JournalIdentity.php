<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

/**
 * Construct only from the active server-side native identity/configuration.
 * scopeHash is not proof of a browser-claimed Fadenstack identity or grant.
 */
final class JournalIdentity {
	public readonly string $ownerHash;
	public readonly string $sessionHash;

	public function __construct(
		public readonly string $userId,
		public readonly string $scopeHash,
		string $sessionBinding,
	) {
		if ($userId === '' || strlen($userId) > 255 || !preg_match('/^[a-f0-9]{64}$/D', $scopeHash)
			|| strlen($sessionBinding) < 16 || strlen($sessionBinding) > 512) {
			throw new JournalException('invalid_identity', 400);
		}
		$this->ownerHash = hash('sha256', 'fadenstack-history-owner:' . $userId);
		$this->sessionHash = hash('sha256', $sessionBinding);
	}
}
