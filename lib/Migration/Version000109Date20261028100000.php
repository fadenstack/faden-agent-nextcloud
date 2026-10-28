<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Migration;

use OCA\FadenstackAgent\History\JournalCipher;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Independent operational accounting; never changes a journal seal or payload. */
class Version000109Date20261028100000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
		private JournalCipher $cipher,
	) {
	}

	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('fdn_jr_alloc')) {
			$table = $schema->createTable('fdn_jr_alloc');
			foreach (['action_id' => 32, 'owner_hash' => 64, 'resource_hash' => 64] as $name => $length) {
				$table->addColumn($name, Types::STRING, ['length' => $length, 'notnull' => true]);
			}
			foreach (['created_at', 'expires_at'] as $name) {
				$table->addColumn($name, Types::BIGINT, ['notnull' => true]);
			}
			$table->addColumn('payload_bytes', Types::INTEGER, ['notnull' => true]);
			$table->setPrimaryKey(['action_id']);
			$table->addIndex(['owner_hash'], 'fdn_jra_owner');
		}
		if (!$schema->hasTable('fdn_jr_barriers')) {
			$table = $schema->createTable('fdn_jr_barriers');
			$table->addColumn('owner_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('reason', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['owner_hash']);
		}
		if (!$schema->hasTable('fdn_jr_control')) {
			$table = $schema->createTable('fdn_jr_control');
			$table->addColumn('control_name', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('cursor_id', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
			$table->addColumn('generation', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['control_name']);
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
		foreach (['safety', 'retention'] as $name) {
			$result = $this->db->executeQuery('SELECT control_name FROM *PREFIX*fdn_jr_control WHERE control_name = ?', [$name]);
			$exists = $result->fetch() !== false;
			$result->closeCursor();
			if (!$exists) {
				$this->db->executeStatement('INSERT INTO *PREFIX*fdn_jr_control (control_name, cursor_id, generation) VALUES (?, ?, ?)', [$name, '', 0]);
			}
		}
		$after = '';
		do {
			$result = $this->db->executeQuery('SELECT * FROM *PREFIX*fdn_journal WHERE action_id > ? ORDER BY action_id ASC LIMIT 25', [$after]);
			$rows = $result->fetchAll();
			$result->closeCursor();
			foreach ($rows as $row) {
				$after = $row['action_id'];
				try {
					$this->cipher->verify($row);
				} catch (\Throwable) {
					// Already unreadable legacy rows have no trustworthy allocation to copy.
					continue;
				}
				$result = $this->db->executeQuery('SELECT action_id FROM *PREFIX*fdn_jr_alloc WHERE action_id = ?', [$after]);
				$exists = $result->fetch() !== false;
				$result->closeCursor();
				if (!$exists) {
					$this->db->executeStatement('INSERT INTO *PREFIX*fdn_jr_alloc (action_id, owner_hash, resource_hash, created_at, expires_at, payload_bytes) VALUES (?, ?, ?, ?, ?, ?)',
						[$after, $row['owner_hash'], $row['resource_hash'], (int)$row['created_at'], (int)$row['expires_at'], (int)$row['payload_bytes']]);
				}
			}
		} while (count($rows) === 25);
	}
}
