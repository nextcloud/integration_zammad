<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Zammad\Migration;

use Closure;
use OCA\Zammad\AppInfo\Application;
use OCA\Zammad\ContextChat\ContentProvider;
use OCA\Zammad\Db\ImportedTicketMapper;
use OCP\ContextChat\IContentManager;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the table that records which tickets have been imported into ContextChat
 * for which user, so that tickets which disappear from a user's Zammad ticket
 * list can be found again and removed.
 */
class Version040200Date20260915094500 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
		private IContentManager $contentManager,
	) {
	}

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
			// the table of a development version that predates the instance column,
			// its rows are keyed by ticket ID alone and cannot be assigned to one
			$table = $schema->getTable(ImportedTicketMapper::TABLE_NAME);
			if ($table->hasColumn('instance')) {
				return null;
			}
			$table->addColumn('instance', Types::STRING, [
				'notnull' => true,
				'default' => '',
				'length' => 64,
			]);
			$table->addColumn('last_import', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 20,
			]);
			$table->addColumn('failures', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			if ($table->hasIndex('zammad_cc_user_ticket')) {
				$table->dropIndex('zammad_cc_user_ticket');
			}
			if ($table->hasIndex('zammad_cc_ticket')) {
				$table->dropIndex('zammad_cc_ticket');
			}
			$table->addUniqueIndex(['instance', 'user_id', 'ticket_id'], 'zammad_cc_user_ticket');
			$table->addIndex(['instance', 'ticket_id'], 'zammad_cc_ticket');
			return $schema;
		}

		$table = $schema->createTable(ImportedTicketMapper::TABLE_NAME);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 20,
		]);
		// the Zammad server the ticket was imported from, ticket IDs only mean
		// something within one of them and users can each point at their own
		$table->addColumn('instance', Types::STRING, [
			'notnull' => true,
			'default' => '',
			'length' => 64,
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
		// the sweep during which this ticket was last handed to ContextChat, 0 while
		// it never has been. Being seen is not the same as having been imported: a
		// run that dies between the two leaves the row behind and the ticket has to
		// be picked up again no matter how old its modification time is by then
		$table->addColumn('last_import', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
			'length' => 20,
		]);
		// consecutive failed import attempts, so that a ticket which cannot be
		// imported at all is eventually left behind instead of holding the sweep back
		$table->addColumn('failures', Types::INTEGER, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['instance', 'user_id', 'ticket_id'], 'zammad_cc_user_ticket');
		// the lookup of the tickets a sweep did not see
		$table->addIndex(['user_id', 'last_seen'], 'zammad_cc_user_seen');
		// the reference count of a ticket across users
		$table->addIndex(['instance', 'ticket_id'], 'zammad_cc_ticket');

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return void
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Rows left behind by a development version that predates the instance
		// column. Its items are keyed by the ticket ID alone and hold whatever the
		// user who imported them last was allowed to read, internal articles
		// included, so they are taken out of ContextChat rather than left behind
		// unreachable. The sweep imports the tickets again under their new item IDs,
		// this only costs the record of what had already been handed over.
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('ticket_id')
			->from(ImportedTicketMapper::TABLE_NAME)
			->where($qb->expr()->eq('instance', $qb->createNamedParameter('')));
		$result = $qb->executeQuery();
		$itemIds = [];
		while (($row = $result->fetch()) !== false) {
			$itemIds[] = (string)(int)$row['ticket_id'];
		}
		$result->closeCursor();

		// a no-op while context_chat is not installed, nothing was imported then
		foreach (array_chunk($itemIds, 500) as $chunk) {
			$this->contentManager->deleteContent(Application::APP_ID, ContentProvider::ID, $chunk);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete(ImportedTicketMapper::TABLE_NAME)
			->where($qb->expr()->eq('instance', $qb->createNamedParameter('')));
		$qb->executeStatement();
	}
}
