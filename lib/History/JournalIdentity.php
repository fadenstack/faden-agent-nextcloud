<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Identity\VerifiedCloudPrincipal;

/**
 * Construct only from the active server-side native identity/configuration.
 * scopeHash is not proof of a browser-claimed Fadenstack identity or grant.
 */
final class JournalIdentity {
	public readonly string $ownerHash;
	public readonly string $sessionHash;

	/** Stable ownership namespace; current policy belongs in the session binding. */
	public static function nativeScope(): string {
		return hash('sha256', 'nextcloud-files-native-history-v2');
	}

	/** New pair-scoped namespace only. Does not migrate or expose legacy native-only entries. */
	public static function forPrincipal(string $nativeUserId, string $nativeScopeHash, string $nativeSessionBinding, VerifiedCloudPrincipal $principal): self {
		// Validate the native inputs before deriving opaque cloud-scoped bindings.
		new self($nativeUserId, $nativeScopeHash, $nativeSessionBinding);
		$scope = hash('sha256', 'fadenstack-history-principal-v2:' . JournalCipher::json([$nativeScopeHash, $principal->namespaceHash]));
		$session = hash('sha256', 'fadenstack-history-session-v2:' . JournalCipher::json([$nativeSessionBinding, $principal->namespaceHash,
			$principal->deviceId, $principal->profileStamp]));
		return new self($nativeUserId, $scope, $session);
	}

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
