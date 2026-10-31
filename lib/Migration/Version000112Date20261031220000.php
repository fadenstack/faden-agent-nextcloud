<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Dispatch/cancellation metadata only. Payloads remain in the existing encrypted journal. */
class Version000112Date20261031220000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('fdn_provider_tickets')) return null;
		$table = $schema->createTable('fdn_provider_tickets');
		$table->addColumn('ticket_id', Types::STRING, ['notnull' => true, 'length' => 48]);
		$table->addColumn('owner_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('session_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('expires_at', Types::BIGINT, ['notnull' => true]);
		$table->setPrimaryKey(['ticket_id']);
		$table->addIndex(['owner_hash', 'expires_at'], 'fdn_pt_owner_expiry');
		$table->addIndex(['expires_at'], 'fdn_pt_expiry');
		return $schema;
	}
}
