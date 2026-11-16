<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Identity;

use OCA\FadenstackAgent\History\JournalCipher;
use OCA\FadenstackAgent\Service\Problem;

/** Internal authenticated-profile projection. Not a signed permit or approval. */
final class VerifiedCloudPrincipal {
	private function __construct(
		public readonly string $origin,
		public readonly string $tenantId,
		public readonly string $userId,
		public readonly string $agentId,
		public readonly string $deviceId,
		public readonly int $version,
		public readonly string $namespaceHash,
		public readonly string $profileStamp,
	) {
	}

	/** Call only with a profile fetched/authenticated by CloudIdentityVerifier. */
	public static function fromAuthenticatedProfile(string $origin, string $configuredAgent, array $profile): self {
		$principal = $profile['principal'] ?? null;
		if (($profile['object'] ?? null) !== 'agent.profile' || !is_array($principal)
			|| !is_int($profile['version'] ?? null) || $profile['version'] < 1 || ($profile['draft'] ?? null) !== false
			|| !is_string($profile['id'] ?? null) || !is_string($profile['slug'] ?? null)
			|| !in_array($configuredAgent, [$profile['id'], $profile['slug']], true)
			|| !is_array($profile['host_tool_contract'] ?? null) || !array_is_list($profile['host_tool_contract'])
			|| !is_array($profile['tool_modes'] ?? null) || !is_array($profile['extensions'] ?? null)) {
			throw new Problem('The configured Fadenstack server does not provide a verified published identity.', 503);
		}
		// From 0.7.1 the profile still answers while an organisation has turned the agent off; older servers omit the key.
		if (array_key_exists('enabled', $profile)) {
			if (!is_bool($profile['enabled'])) {
				throw new Problem('The configured Fadenstack server does not provide a verified published identity.', 503);
			}
			if ($profile['enabled'] === false) {
				throw new Problem(CloudApi::AGENT_DISABLED, 403);
			}
		}
		foreach (['tenant_id', 'user_id', 'agent_id', 'device_id'] as $key) {
			if (!is_string($principal[$key] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $principal[$key])) {
				throw new Problem('The Fadenstack device identity could not be verified.', 503);
			}
		}
		if (!hash_equals($profile['id'], $principal['agent_id'])) {
			throw new Problem('The Fadenstack agent identity changed.', 409);
		}
		$namespace = ['origin' => $origin, 'tenant' => $principal['tenant_id'], 'user' => $principal['user_id'], 'agent' => $principal['agent_id']];
		$stamp = ['principal' => $principal, 'version' => $profile['version'], 'contract' => $profile['host_tool_contract'],
			'modes' => $profile['tool_modes'], 'extensions' => $profile['extensions']];
		return new self($origin, $principal['tenant_id'], $principal['user_id'], $principal['agent_id'], $principal['device_id'],
			$profile['version'], hash('sha256', 'fadenstack-cloud-namespace-v1:' . JournalCipher::json($namespace)),
			hash('sha256', 'fadenstack-cloud-profile-v1:' . JournalCipher::json($stamp)));
	}
}
