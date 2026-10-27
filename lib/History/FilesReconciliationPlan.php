<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

/** Exact native observation, not a write recipe or permission. */
final class FilesReconciliationPlan {
	public readonly string $digest;
	public readonly string $direction;
	public readonly string $content;

	public function __construct(
		public readonly string $actionId,
		public readonly int $version,
		public readonly string $scopeHash,
		public readonly int $fileId,
		public readonly string $target,
		public readonly string $expectedEtag,
		public readonly string $before,
		public readonly string $outcome,
		public readonly string $settledState,
		public readonly string $attemptDirection,
	) {
		$this->direction = 'reconcile';
		$this->content = $before;
		$this->digest = hash('sha256', JournalCipher::json([
			'actionId' => $actionId, 'version' => $version, 'scopeHash' => $scopeHash, 'fileId' => $fileId,
			'target' => $target, 'expectedEtag' => $expectedEtag, 'content' => $before, 'outcome' => $outcome,
			'settledState' => $settledState, 'attemptDirection' => $attemptDirection, 'direction' => 'reconcile',
		]));
	}
}
