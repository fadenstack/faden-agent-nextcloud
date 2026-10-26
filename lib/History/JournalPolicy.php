<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

final class JournalPolicy {
	public function __construct(
		public readonly int $retentionSeconds = 604800,
		public readonly int $maxPayloadBytes = 1048576,
		public readonly int $maxUserBytes = 26214400,
		public readonly int $maxResourceEntries = 50,
		public readonly int $maxUserEntries = 1000,
	) {
		if ($retentionSeconds < 1 || $retentionSeconds > 604800 || $maxPayloadBytes < 1 || $maxPayloadBytes > 1048576
			|| $maxUserBytes < 1 || $maxUserBytes > 26214400 || $maxResourceEntries < 1 || $maxResourceEntries > 50
			|| $maxUserEntries < 1 || $maxUserEntries > 1000) {
			throw new JournalException('invalid_policy', 500);
		}
	}
}
