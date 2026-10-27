<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Identity;

use OCA\FadenstackAgent\Service\Configuration;
use OCP\Http\Client\IClientService;

/** Verifies a short Fadenstack agent token on the configured server; never stores it. */
final class CloudIdentityVerifier {
	public const MAX_PROFILE_BYTES = CloudApi::MAX_RESPONSE_BYTES;

	public function __construct(
		private Configuration $configuration,
		private IClientService $clients,
	) {
	}

	public function verify(#[\SensitiveParameter] string $accessToken): VerifiedCloudPrincipal {
		$response = (new CloudApi($this->configuration, $this->clients))->request($accessToken, 'profile');
		return VerifiedCloudPrincipal::fromAuthenticatedProfile($response['origin'], $response['agent'], $response['value']);
	}
}
