<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

/** Construct only after a typed backend handler independently verifies native state. */
final class VerifiedReadback {
	public function __construct(
		public readonly string $revision,
		public readonly ?JournalResource $resource = null,
	) {
		if ($revision === '' || strlen($revision) > 255 || preg_match('/[\x00-\x1f\x7f]/', $revision)) {
			throw new JournalException('invalid_readback', 400);
		}
	}
}
