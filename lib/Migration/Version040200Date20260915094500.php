<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Zammad\Migration;

use Closure;
use OCA\Zammad\Db\ImportedTicketMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the table that records which tickets have been imported into ContextChat
 * for which user, so that tickets which disappear from a user's Zammad ticket
 * list can be found again and removed.
 */
class Version040200Date20260915094500 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(ImportedTicketMapper::TABLE_NAME)) {
			return null;
		}

		$table = $schema->createTable(ImportedTicketMapper::TABLE_NAME);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 20,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('ticket_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
		]);
		// the sweep during which this ticket was last seen in the user's ticket list
		$table->addColumn('last_seen', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
			'length' => 20,
		]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['user_id', 'ticket_id'], 'zammad_cc_user_ticket');
		// the lookup of the tickets a sweep did not see
		$table->addIndex(['user_id', 'last_seen'], 'zammad_cc_user_seen');
		// the reference count of a ticket across users
		$table->addIndex(['ticket_id'], 'zammad_cc_ticket');

		return $schema;
	}
}
