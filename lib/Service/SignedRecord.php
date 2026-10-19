<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Service;

/**
 * A placement's record as the Fadenstack server signed it, in the SDK's sidecar document
 * (`{"contract","record","signature","timestamp"?}`): the JSON exactly as the client wrote it, and the record's id.
 * It holds no content and no names: a keyed hash of the text, the model, the time and the signature.
 */
final class SignedRecord {
	public function __construct(
		public readonly string $id,
		public readonly string $json,
	) {
	}
}
