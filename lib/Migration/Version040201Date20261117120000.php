<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Zammad\Migration;

use Closure;
use OCA\Zammad\AppInfo\Application;
use OCA\Zammad\Db\ImportedTicketMapper;
use OCA\Zammad\Db\TicketContentMapper;
use OCP\Config\IUserConfig;
use OCP\Config\ValueType;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the table that records which version of a ticket was last handed to
 * ContextChat, see {@see TicketContentMapper}, and fills it in from the sweeps
 * that are already under way.
 *
 * Without filling it in, the first user to come across each ticket after the
 * update would submit it once more, which is one more indexing run of every ticket
 * that was imported so far.
 */
class Version040201Date20261117120000 extends SimpleMigrationStep {

	private const INSERT_CHUNK_SIZE = 1000;

	public function __construct(
		private IDBConnection $db,
		private IUserConfig $userConfig,
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

		if ($schema->hasTable(TicketContentMapper::TABLE_NAME)) {
			return null;
		}

		$table = $schema->createTable(TicketContentMapper::TABLE_NAME);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 20,
		]);
		$table->addColumn('instance', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('ticket_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
		]);
		// the modification time of the ticket in milliseconds, as the ticket list
		// reported it before the content that was submitted was fetched
		$table->addColumn('version', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
			'length' => 20,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['instance', 'ticket_id'], 'zammad_cc_cnt_inst_tkt');

		return $schema;
	}

	/**
	 * Record a version for every ticket we can tell was submitted after a known
	 * point in time.
	 *
	 * A ticket that was submitted during a sweep was submitted after that sweep
	 * started, so its content holds every change made before then and the start of
	 * the sweep is a safe version for it. That is the start of the running sweep for
	 * the tickets imported during it, and the watermark of the last completed sweep
	 * for the ones imported during that one, which is never later than its start.
	 * Anything older is left out and submitted once more when it is next looked at.
	 *
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return void
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(ImportedTicketMapper::TABLE_NAME) || !$schema->hasTable(TicketContentMapper::TABLE_NAME)) {
			return;
		}
		if ($this->hasContentRows()) {
			// already filled in, or in use by the import
			return;
		}

		$generations = $this->getIntValues('cc_sweep_generation');
		$started = $this->getIntValues('cc_sweep_started');
		$since = $this->getIntValues('cc_sweep_since');

		/** @var array<string, array<int, int>> $versions instance => ticket ID => version */
		$versions = [];
		$userIds = array_unique(array_merge(array_keys($started), array_keys($since)));
		foreach ($userIds as $userId) {
			$generation = max(1, $generations[$userId] ?? 1);
			$startedTs = $started[$userId] ?? 0;
			$sinceTs = $since[$userId] ?? 0;
			// while a sweep runs, its start is recorded and the watermark is the one of
			// the sweep before it. Once it has completed and is being cleaned up, its
			// start has been folded into the watermark
			$byGeneration = $startedTs > 0
				? [$generation => $startedTs, $generation - 1 => $sinceTs]
				: [$generation => $sinceTs];
			foreach ($byGeneration as $lastImport => $timestamp) {
				if ($lastImport < 1 || $timestamp <= 0) {
					continue;
				}
				foreach ($this->findImported((string)$userId, $lastImport) as [$instance, $ticketId]) {
					$version = $timestamp * 1000;
					if ($version > ($versions[$instance][$ticketId] ?? 0)) {
						$versions[$instance][$ticketId] = $version;
					}
				}
			}
		}

		$count = 0;
		foreach ($versions as $instance => $tickets) {
			foreach (array_chunk($tickets, self::INSERT_CHUNK_SIZE, true) as $chunk) {
				$this->db->beginTransaction();
				try {
					foreach ($chunk as $ticketId => $version) {
						$qb = $this->db->getQueryBuilder();
						$qb->insert(TicketContentMapper::TABLE_NAME)
							->values([
								'instance' => $qb->createNamedParameter((string)$instance),
								'ticket_id' => $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT),
								'version' => $qb->createNamedParameter($version, IQueryBuilder::PARAM_INT),
							]);
						$qb->executeStatement();
					}
					$this->db->commit();
				} catch (\Throwable $e) {
					$this->db->rollBack();
					throw $e;
				}
				$count += count($chunk);
			}
		}
		if ($count > 0) {
			$output->info('Recorded the ContextChat content version of ' . $count . ' Zammad tickets.');
		}
	}

	private function hasContentRows(): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(TicketContentMapper::TABLE_NAME)
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetchOne();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * @param string $key
	 * @return array<string, int> user ID => value, only values above 0
	 */
	private function getIntValues(string $key): array {
		$values = [];
		foreach ($this->userConfig->getValuesByUsers(Application::APP_ID, $key, ValueType::INT) as $userId => $value) {
			if ((int)$value > 0) {
				$values[(string)$userId] = (int)$value;
			}
		}
		return $values;
	}

	/**
	 * @param string $userId
	 * @param int $lastImport
	 * @return \Generator<array{string, int}> instance and ticket ID of every ticket
	 *                                        imported for the user during that sweep
	 */
	private function findImported(string $userId, int $lastImport): \Generator {
		$qb = $this->db->getQueryBuilder();
		$qb->select('instance', 'ticket_id')
			->from(ImportedTicketMapper::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('last_import', $qb->createNamedParameter($lastImport, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			yield [(string)$row['instance'], (int)$row['ticket_id']];
		}
		$result->closeCursor();
	}
}
