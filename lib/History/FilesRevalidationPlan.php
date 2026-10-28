<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

/** An exact settled-state observation. It grants no write or history permission. */
final class FilesRevalidationPlan {
	public readonly string $direction;
	public readonly string $digest;
	public readonly string $content;

	public function __construct(
		public readonly string $actionId,
		public readonly int $version,
		public readonly string $scopeHash,
		public readonly int $fileId,
		public readonly int $parentId,
		public readonly string $target,
		public readonly string $recordedRevision,
		public readonly string $expectedEtag,
		public readonly string $before,
		public readonly string $settledState,
	) {
		$this->direction = 'revalidate';
		$this->content = $before;
		$this->digest = hash('sha256', JournalCipher::json([
			'actionId' => $actionId, 'version' => $version, 'scopeHash' => $scopeHash, 'fileId' => $fileId,
			'parentId' => $parentId, 'target' => $target, 'recordedRevision' => $recordedRevision,
			'expectedEtag' => $expectedEtag, 'content' => $before, 'state' => $settledState, 'direction' => 'revalidate',
		]));
	}
}
