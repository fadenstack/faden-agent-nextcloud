<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Own metadata only; encrypted journal rows and their MACs are never rewritten. */
class Version000103Date20261027100000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$owners = $schema->getTable('fdn_jr_owners');
		if (!$owners->hasColumn('action_seq')) {
			$owners->addColumn('action_seq', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
		$maximum = '(SELECT COALESCE(MAX(resource_seq), 0) FROM *PREFIX*fdn_journal WHERE *PREFIX*fdn_journal.owner_hash = *PREFIX*fdn_jr_owners.owner_hash)';
		$this->db->executeStatement('UPDATE *PREFIX*fdn_jr_owners SET action_seq = ' . $maximum . ' WHERE action_seq < ' . $maximum);
	}
}
