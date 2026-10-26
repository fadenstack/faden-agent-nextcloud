<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Only app-owned tables. No native application schema is modified. */
class Version000102Date20261026090000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('fdn_jr_owners')) {
			$table = $schema->createTable('fdn_jr_owners');
			$table->addColumn('owner_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('used_bytes', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('entries', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['owner_hash']);
		}
		if (!$schema->hasTable('fdn_jr_resources')) {
			$table = $schema->createTable('fdn_jr_resources');
			$table->addColumn('resource_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('owner_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('active_id', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('resource_seq', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('entries', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['resource_hash']);
			$table->addIndex(['owner_hash'], 'fdn_jrr_owner');
		}
		if (!$schema->hasTable('fdn_journal')) {
			$table = $schema->createTable('fdn_journal');
			foreach (['action_id' => 32, 'owner_hash' => 64, 'scope_hash' => 64, 'resource_hash' => 64,
				'operation' => 100, 'state' => 32, 'direction' => 8, 'metadata_mac' => 128] as $name => $length) {
				$table->addColumn($name, Types::STRING, ['length' => $length, 'notnull' => true]);
			}
			foreach (['ticket_hash', 'session_hash'] as $name) {
				$table->addColumn($name, Types::STRING, ['length' => 64, 'notnull' => false]);
			}
			$table->addColumn('resource_json', Types::TEXT, ['notnull' => true]);
			$table->addColumn('payload', Types::TEXT, ['notnull' => false]);
			foreach (['version', 'resource_seq', 'payload_bytes'] as $name) {
				$table->addColumn($name, Types::INTEGER, ['notnull' => true]);
			}
			foreach (['created_at', 'updated_at', 'expires_at'] as $name) {
				$table->addColumn($name, Types::BIGINT, ['notnull' => true]);
			}
			$table->setPrimaryKey(['action_id']);
			$table->addIndex(['owner_hash', 'scope_hash', 'resource_hash', 'resource_seq'], 'fdn_jr_resource');
			$table->addIndex(['owner_hash', 'expires_at'], 'fdn_jr_expiry');
		}
		if (!$schema->hasTable('fdn_jr_attempts')) {
			$table = $schema->createTable('fdn_jr_attempts');
			foreach (['action_id' => 32, 'direction' => 8, 'outcome' => 32, 'reason' => 64, 'receipt_mac' => 128] as $name => $length) {
				$table->addColumn($name, Types::STRING, ['length' => $length, 'notnull' => true]);
			}
			$table->addColumn('version', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['action_id', 'version']);
		}
		return $schema;
	}
}
