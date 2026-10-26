<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

use OCA\FadenstackAgent\Service\Problem;

/** Content-free error codes are safe to return without logging snapshots. */
class JournalException extends Problem {
	public function __construct(
		public readonly string $reason,
		int $status = 409,
	) {
		parent::__construct('Action history is unavailable (' . $reason . ').', $status);
	}
}
