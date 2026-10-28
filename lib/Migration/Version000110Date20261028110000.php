<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Migration;

use Doctrine\DBAL\Types\Type;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Actual retained-byte accounting must not overflow after journal corruption. */
class Version000110Date20261028110000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$schema->getTable('fdn_jr_owners')->getColumn('used_bytes')->setType(Type::getType(Types::BIGINT));
		return $schema;
	}
}
