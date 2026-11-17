<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Migration;

use OCA\FadenstackAgent\Service\DataErasure;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Nextcloud runs uninstall steps when the app is disabled and when it is removed (`occ app:remove` without
 * `--keep-data`, or Remove in the app settings). Both erase the personal data the app keeps; see DataErasure.
 */
final class EraseOnUninstall implements IRepairStep {
	public function __construct(
		private DataErasure $erasure,
	) {
	}

	public function getName(): string {
		return 'Erase the agent history, open tickets and workflow-engine credentials';
	}

	public function run(IOutput $output): void {
		$this->erasure->everything();
		$output->info('Agent history, open tickets and workflow-engine credentials erased; the server settings stay.');
	}
}
