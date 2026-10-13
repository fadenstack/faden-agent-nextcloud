<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Service;

class Problem extends \RuntimeException {
	public function __construct(
		string $message,
		public readonly int $status = 400,
	) {
		parent::__construct($message);
	}
}
