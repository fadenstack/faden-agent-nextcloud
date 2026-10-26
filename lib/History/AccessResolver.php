<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

/** Native authority must be checked before every preview/decrypt and attempt. */
interface AccessResolver {
	public function assertAccess(JournalIdentity $identity, JournalResource $resource, string $purpose): void;
}
