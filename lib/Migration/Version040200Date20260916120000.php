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
use OCA\Zammad\ContextChat\TicketImportService;
use OCA\Zammad\Db\ImportedTicketMapper;
use OCP\Config\IUserConfig;
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
 *
 * Unreleased development builds shipped three different shapes of this table
 * under a migration of an earlier name. Nextcloud records an applied migration by
 * the version its class name carries, see \OC\DB\MigrationService::migrate(), so
 * those installs never run that migration again and the table they are left with
 * is whichever shape they happened to install. This migration carries a name they
 * have not seen, brings any of those shapes up to the current one and throws away
 * what they had imported, see {@see self::postSchemaChange()}.
 */
class Version040200Date20260916120000 extends SimpleMigrationStep {

	/**
	 * Index names of the development builds. Their columns differ from the ones we
	 * want, and an index is replaced under a new name rather than the same one so
	 * that dropping and adding it cannot end up in a single schema diff.
	 */
	private const LEGACY_INDEXES = [
		'zammad_cc_user_ticket',
		'zammad_cc_user_seen',
		'zammad_cc_ticket',
	];

	/** Whether the table was already there when this migration started */
	private bool $tableExisted = false;

	public function __construct(
		private IDBConnection $db,
		private IUserConfig $userConfig,
		private IContentManager $contentManager,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return void
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		// only a development build can have created it, this migration has never run
		$this->tableExisted = $schema->hasTable(ImportedTicketMapper::TABLE_NAME);
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

		$table = $schema->hasTable(ImportedTicketMapper::TABLE_NAME)
			? $schema->getTable(ImportedTicketMapper::TABLE_NAME)
			: $schema->createTable(ImportedTicketMapper::TABLE_NAME);

		if (!$table->hasColumn('id')) {
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->setPrimaryKey(['id']);
		}
		// the Zammad server the ticket was imported from, ticket IDs only mean
		// something within one of them and users can each point at their own
		if (!$table->hasColumn('instance')) {
			$table->addColumn('instance', Types::STRING, [
				'notnull' => true,
				'default' => '',
				'length' => 64,
			]);
		}
		if (!$table->hasColumn('user_id')) {
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
		}
		if (!$table->hasColumn('ticket_id')) {
			$table->addColumn('ticket_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
			]);
		}
		// the sweep during which this ticket was last seen in the user's ticket list
		if (!$table->hasColumn('last_seen')) {
			$table->addColumn('last_seen', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 20,
			]);
		}
		// the sweep during which this ticket was last handed to ContextChat, 0 while
		// it never has been. Being seen is not the same as having been imported: a
		// run that dies between the two leaves the row behind and the ticket has to
		// be picked up again no matter how old its modification time is by then
		if (!$table->hasColumn('last_import')) {
			$table->addColumn('last_import', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 20,
			]);
		}
		// consecutive failed import attempts, so that a ticket which cannot be
		// imported at all is eventually left behind instead of holding the sweep back
		if (!$table->hasColumn('failures')) {
			$table->addColumn('failures', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
		}

		foreach (self::LEGACY_INDEXES as $legacyIndex) {
			if ($table->hasIndex($legacyIndex)) {
				$table->dropIndex($legacyIndex);
			}
		}
		if (!$table->hasIndex('zammad_cc_inst_usr_tkt')) {
			$table->addUniqueIndex(['instance', 'user_id', 'ticket_id'], 'zammad_cc_inst_usr_tkt');
		}
		// the lookup of the tickets a sweep did not see
		if (!$table->hasIndex('zammad_cc_seen_idx')) {
			$table->addIndex(['instance', 'user_id', 'last_seen', 'ticket_id'], 'zammad_cc_seen_idx');
		}
		// the reference count of a ticket across users
		if (!$table->hasIndex('zammad_cc_tkt_idx')) {
			$table->addIndex(['instance', 'ticket_id'], 'zammad_cc_tkt_idx');
		}

		return $schema;
	}

	/**
	 * Throw away what a development build had imported.
	 *
	 * Its rows are keyed by the ticket ID alone or carry an instance we cannot tell
	 * apart from the current one, and the items behind them hold whatever the user
	 * who imported them last was allowed to read, internal articles included. They
	 * are taken out of ContextChat rather than left behind unreachable, and the
	 * sweep imports the tickets again from scratch.
	 *
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return void
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->tableExisted) {
			return;
		}
		$output->info('Dropping the Zammad tickets imported into ContextChat by a development build.');

		// a no-op while context_chat is not installed, nothing was imported then
		$this->contentManager->deleteProvider(Application::APP_ID, ContentProvider::ID);

		$qb = $this->db->getQueryBuilder();
		$qb->delete(ImportedTicketMapper::TABLE_NAME);
		$qb->executeStatement();

		// the sweep state of those rows says the tickets are in sync, which would
		// keep the import from picking them up again
		foreach (TicketImportService::CONFIG_KEYS as $key) {
			$this->userConfig->deleteKey(Application::APP_ID, $key);
		}
	}
}
