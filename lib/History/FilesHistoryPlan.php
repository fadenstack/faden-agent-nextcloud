<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

/** Server-owned preview data. Neither an approval nor a native execution permit. */
final class FilesHistoryPlan {
	public readonly string $digest;

	public function __construct(
		public readonly string $actionId,
		public readonly string $direction,
		public readonly int $version,
		public readonly string $scopeHash,
		public readonly int $fileId,
		public readonly int $parentId,
		public readonly string $target,
		public readonly string $expectedEtag,
		public readonly string $before,
		public readonly string $content,
	) {
		$this->digest = hash('sha256', JournalCipher::json([
			'actionId' => $actionId, 'direction' => $direction, 'version' => $version,
			'scopeHash' => $scopeHash, 'fileId' => $fileId, 'parentId' => $parentId,
			'target' => $target, 'expectedEtag' => $expectedEtag, 'before' => $before, 'content' => $content,
		]));
	}
}
