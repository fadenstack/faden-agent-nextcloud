<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Identity\CloudApi;
use OCA\FadenstackAgent\Service\Problem;

/** Native-computed hash only. The short cloud token is request-local, never journaled. */
final class EngineProvenance {
	public function __construct(
		private CloudApi $cloud,
	) {
	}
	public function sign(string $provider, string $source, #[\SensitiveParameter] string $token): array {
		$media = $provider === 'windmill' ? 'application/vnd.windmill.flow+json' : 'application/vnd.n8n.workflow+json';
		$result = $this->cloud->request($token, 'provenance/records', ['content_sha256' => hash('sha256', $source), 'media_type' => $media, 'edit_kind' => 'generated']);
		$value = $result['value'];
		$record = $value['record'] ?? null;
		if (!$record instanceof \stdClass || ($record->contract ?? null) !== 'provenance/v1'
			|| !is_string($record->id ?? null) || strlen($record->id) > 100 || $record->id === ''
			|| ($record->media_type ?? null) !== $media || ($record->edit_kind ?? null) !== 'generated'
			|| !is_string($value['signature'] ?? null) || strlen($value['signature']) < 32 || strlen($value['signature']) > 20000) {
			throw new Problem('A signed origin record could not be obtained. No engine action was dispatched.', 503);
		}
		// The configured signer receives our exact digest. Pin its published
		// signing leaf and verify the returned record before storing the carrier.
		EngineJson::encode($value);
		$anchors = $this->cloud->request($token, 'provenance/anchors');
		if ($result['origin'] !== $anchors['origin'] || $result['agent'] !== $anchors['agent']) {
			throw new Problem('The origin signing service changed.', 409);
		}
		self::verifySignature($value, $anchors['value']);
		return $value;
	}
	private static function decode(string $value): string {
		if (!preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
			throw new \UnexpectedValueException();
		}
		$decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
		if (!is_string($decoded)) {
			throw new \UnexpectedValueException();
		}
		return $decoded;
	}
	private static function integer(string $bytes): string {
		$value = ltrim($bytes, "\0");
		if ($value === '') {
			$value = "\0";
		}
		if (ord($value[0]) >= 128) {
			$value = "\0" . $value;
		}
		return "\x02" . chr(strlen($value)) . $value;
	}
	/** Publisher leaf-key pinning, not arbitrary embedded-certificate trust. */
	public static function verifySignature(array $signed, array $anchors): void {
		try {
			$parts = explode('.', $signed['signature']);
			if (count($parts) !== 3) {
				throw new \UnexpectedValueException();
			}
			$header = json_decode(self::decode($parts[0]), true, 8, JSON_THROW_ON_ERROR);
			$body = EngineJson::decode(self::decode($parts[1]));
			$raw = self::decode($parts[2]);
			if (($header['alg'] ?? null) !== 'ES256' || isset($header['crit']) || strlen($raw) !== 64
				|| !hash_equals(EngineJson::encode($signed['record']), EngineJson::encode($body))
				|| !is_string($header['x5c'][0] ?? null) || strlen($header['x5c'][0]) > 16000) {
				throw new \UnexpectedValueException();
			}
			$leaf = @openssl_x509_read("-----BEGIN CERTIFICATE-----\n" . chunk_split($header['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n");
			if ($leaf === false || !is_array($anchors['signing_chains'] ?? null) || count($anchors['signing_chains']) > 32) {
				throw new \UnexpectedValueException();
			}
			$fingerprint = openssl_x509_fingerprint($leaf, 'sha256');
			$pinned = false;
			foreach ($anchors['signing_chains'] as $chain) {
				if (!is_string($chain)) {
					throw new \UnexpectedValueException();
				}
				$known = @openssl_x509_read($chain);
				if ($known !== false && $fingerprint !== false && hash_equals($fingerprint, openssl_x509_fingerprint($known, 'sha256'))) {
					$pinned = true;
				}
			}
			$key = openssl_pkey_get_public($leaf);
			$details = $key === false ? false : openssl_pkey_get_details($key);
			if (!$pinned || $details === false || $details['type'] !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
				throw new \UnexpectedValueException();
			}
			$integers = self::integer(substr($raw, 0, 32)) . self::integer(substr($raw, 32));
			$der = "\x30" . chr(strlen($integers)) . $integers;
			if (openssl_verify($parts[0] . '.' . $parts[1], $der, $key, OPENSSL_ALGO_SHA256) !== 1) {
				throw new \UnexpectedValueException();
			}
		} catch (\Throwable) {
			throw new Problem('The workflow origin signature could not be verified against this publisher.', 503);
		}
	}
}
