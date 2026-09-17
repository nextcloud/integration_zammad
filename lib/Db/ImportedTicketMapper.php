<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Zammad\Db;

use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Remembers which Zammad tickets have been handed to ContextChat for which user.
 *
 * The Zammad ticket list only ever contains the tickets a user may read, so a
 * ticket that stops showing up in it has either been deleted or is no longer
 * accessible to that user. Comparing a completed sweep against these rows is what
 * surfaces those tickets. The rows double as a reference count that tells us when
 * the last user has lost access to a ticket and its content can be dropped.
 *
 * Every user can point at their own Zammad server and a ticket ID only means
 * something within one of them, so a row is keyed by the instance it came from,
 * see {@see \OCA\Zammad\ContextChat\TicketImportService::getInstanceId()}.
 */
class ImportedTicketMapper {

	public const TABLE_NAME = 'zammad_cc_tickets';

	/** Maximum number of ticket IDs to put into a single IN () clause */
	private const ID_CHUNK_SIZE = 1000;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Remember that the given tickets were accessible to the user during the sweep
	 * identified by $generation.
	 *
	 * @param string $instance
	 * @param string $userId
	 * @param int[] $ticketIds
	 * @param int $generation
	 * @return int[] those of $ticketIds that have not been handed to ContextChat
	 *               for this user yet
	 * @throws Exception
	 */
	public function markSeen(string $instance, string $userId, array $ticketIds, int $generation): array {
		$ticketIds = array_values(array_unique(array_map('intval', $ticketIds)));
		$pending = [];
		foreach (array_chunk($ticketIds, self::ID_CHUNK_SIZE) as $chunk) {
			$known = $this->findKnown($instance, $userId, $chunk);
			if ($known !== []) {
				$qb = $this->db->getQueryBuilder();
				$qb->update(self::TABLE_NAME)
					->set('last_seen', $qb->createNamedParameter($generation, IQueryBuilder::PARAM_INT))
					->where($qb->expr()->eq('instance', $qb->createNamedParameter($instance)))
					->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
					->andWhere($qb->expr()->in('ticket_id', $qb->createNamedParameter(array_keys($known), IQueryBuilder::PARAM_INT_ARRAY)));
				$qb->executeStatement();
			}
			foreach ($known as $ticketId => $lastImport) {
				if ($lastImport === 0) {
					$pending[] = $ticketId;
				}
			}
			foreach (array_diff($chunk, array_keys($known)) as $ticketId) {
				$pending[] = $ticketId;
				// a concurrent import of the same ticket may have inserted the row in
				// the meantime, which is exactly what we would have written ourselves
				$this->db->insertIgnoreConflict(self::TABLE_NAME, [
					'instance' => $instance,
					'user_id' => $userId,
					'ticket_id' => $ticketId,
					'last_seen' => $generation,
				]);
			}
		}
		return $pending;
	}

	/**
	 * Remember that a ticket has been handed to ContextChat for the user.
	 *
	 * @param string $instance
	 * @param string $userId
	 * @param int $ticketId
	 * @param int $generation
	 * @return void
	 * @throws Exception
	 */
	public function markImported(string $instance, string $userId, int $ticketId, int $generation): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('last_seen', $qb->createNamedParameter($generation, IQueryBuilder::PARAM_INT))
			->set('last_import', $qb->createNamedParameter($generation, IQueryBuilder::PARAM_INT))
			->set('failures', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('instance', $qb->createNamedParameter($instance)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() !== 0) {
			return;
		}
		// no row of ours to update, or one that already said exactly this
		$this->db->insertIgnoreConflict(self::TABLE_NAME, [
			'instance' => $instance,
			'user_id' => $userId,
			'ticket_id' => $ticketId,
			'last_seen' => $generation,
			'last_import' => $generation,
		]);
	}

	/**
	 * Have the given tickets handed to ContextChat again by every user that still
	 * has a row for them.
	 *
	 * Used when a user loses access to a ticket the others keep. Both the submit
	 * that built the access list of the item and the revoke that takes this user
	 * out of it only queue an action in ContextChat, and the two are not ordered
	 * against each other, so the list can end up still holding the revoked user.
	 * Importing the ticket again rebuilds that list from these rows.
	 *
	 * @param string $instance
	 * @param int[] $ticketIds
	 * @return void
	 * @throws Exception
	 */
	public function markPending(string $instance, array $ticketIds): void {
		$ticketIds = array_values(array_unique(array_map('intval', $ticketIds)));
		foreach (array_chunk($ticketIds, self::ID_CHUNK_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE_NAME)
				->set('last_import', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('instance', $qb->createNamedParameter($instance)))
				->andWhere($qb->expr()->in('ticket_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	/**
	 * Count an import attempt that failed.
	 *
	 * @param string $instance
	 * @param string $userId
	 * @param int $ticketId
	 * @return int how often importing this ticket has failed in a row
	 * @throws Exception
	 */
	public function noteFailure(string $instance, string $userId, int $ticketId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('failures')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('instance', $qb->createNamedParameter($instance)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$failures = $result->fetchOne();
		$result->closeCursor();
		// only one job ever writes the rows of a user, so nobody can race us here
		$failures = ($failures === false || $failures === null ? 0 : (int)$failures) + 1;

		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('failures', $qb->createNamedParameter($failures, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('instance', $qb->createNamedParameter($instance)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() !== 0) {
			return $failures;
		}
		// No row of ours to update, the count would be thrown away and start over at
		// one on every attempt. That is exactly the runaway the cap is there to stop:
		// the ticket would hold the watermark just below its modification time
		// forever and every sweep would import every ticket modified after it again.
		// The row can be missing because a concurrent revoke took it away, which the
		// cleanup of the next sweep undoes again for a ticket that is really gone.
		$this->db->insertIgnoreConflict(self::TABLE_NAME, [
			'instance' => $instance,
			'user_id' => $userId,
			'ticket_id' => $ticketId,
			'failures' => $failures,
		]);
		return $failures;
	}

	/**
	 * The tickets of a user that the sweep $generation did not come across, in
	 * ascending ticket ID order.
	 *
	 * @param string $instance
	 * @param string $userId
	 * @param int $generation
	 * @param int $afterTicketId only return tickets with a higher ID, to page through the candidates
	 * @param int $limit
	 * @return int[]
	 * @throws Exception
	 */
	public function findStale(string $instance, string $userId, int $generation, int $afterTicketId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('instance', $qb->createNamedParameter($instance)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lt('last_seen', $qb->createNamedParameter($generation, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('ticket_id', $qb->createNamedParameter($afterTicketId, IQueryBuilder::PARAM_INT)))
			->orderBy('ticket_id', 'ASC')
			->setMaxResults($limit);
		return $this->fetchTicketIds($qb);
	}

	/**
	 * @param string $instance
	 * @param int $ticketId
	 * @return string[] every user this ticket has been imported for
	 * @throws Exception
	 */
	public function findUsersForTicket(string $instance, int $ticketId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('instance', $qb->createNamedParameter($instance)))
			->andWhere($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$userIds = [];
		while (($row = $result->fetch()) !== false) {
			$userIds[] = (string)$row['user_id'];
		}
		$result->closeCursor();
		return $userIds;
	}

	/**
	 * The highest sweep number any of the user's rows carries, 0 if they have none.
	 *
	 * @param string $instance
	 * @param string $userId
	 * @return int
	 * @throws Exception
	 */
	public function findMaxLastSeen(string $instance, string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('last_seen'))
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('instance', $qb->createNamedParameter($instance)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();
		return $max === false || $max === null ? 0 : (int)$max;
	}

	/**
	 * @param string $userId
	 * @return string[] every Zammad instance this user has imported tickets from
	 * @throws Exception
	 */
	public function findInstances(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('instance')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$instances = [];
		while (($row = $result->fetch()) !== false) {
			$instances[] = (string)$row['instance'];
		}
		$result->closeCursor();
		return $instances;
	}

	/**
	 * Every ticket that has been imported for a user, grouped by the Zammad
	 * instance it came from. A user that has moved their account to another
	 * Zammad server still has the rows of the one they came from.
	 *
	 * @param string $userId
	 * @return array<string, int[]> instance => ticket IDs
	 * @throws Exception
	 */
	public function findForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('instance', 'ticket_id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$ticketIds = [];
		while (($row = $result->fetch()) !== false) {
			$ticketIds[(string)$row['instance']][] = (int)$row['ticket_id'];
		}
		$result->closeCursor();
		return $ticketIds;
	}

	/**
	 * @param string $instance
	 * @param int[] $ticketIds
	 * @return int[] those of $ticketIds that no user has access to any more
	 * @throws Exception
	 */
	public function filterUnreferenced(string $instance, array $ticketIds): array {
		$ticketIds = array_values(array_unique(array_map('intval', $ticketIds)));
		$unreferenced = [];
		foreach (array_chunk($ticketIds, self::ID_CHUNK_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('ticket_id')
				->from(self::TABLE_NAME)
				->where($qb->expr()->eq('instance', $qb->createNamedParameter($instance)))
				->andWhere($qb->expr()->in('ticket_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$referenced = $this->fetchTicketIds($qb);
			$unreferenced = array_merge($unreferenced, array_values(array_diff($chunk, $referenced)));
		}
		return $unreferenced;
	}

	/**
	 * @param string $instance
	 * @param string $userId
	 * @param int $ticketId
	 * @return void
	 * @throws Exception
	 */
	public function delete(string $instance, string $userId, int $ticketId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('instance', $qb->createNamedParameter($instance)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * @param string $userId
	 * @return void
	 * @throws Exception
	 */
	public function deleteForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * @param string $instance
	 * @param string $userId
	 * @param int[] $ticketIds
	 * @return array<int, int> those of $ticketIds that already have a row for this
	 *                         user, mapped to the sweep they were last imported in
	 * @throws Exception
	 */
	private function findKnown(string $instance, string $userId, array $ticketIds): array {
		if ($ticketIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id', 'last_import')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('instance', $qb->createNamedParameter($instance)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('ticket_id', $qb->createNamedParameter($ticketIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $qb->executeQuery();
		$known = [];
		while (($row = $result->fetch()) !== false) {
			$known[(int)$row['ticket_id']] = (int)$row['last_import'];
		}
		$result->closeCursor();
		return $known;
	}

	/**
	 * @param IQueryBuilder $qb a query selecting the ticket_id column
	 * @return int[]
	 * @throws Exception
	 */
	private function fetchTicketIds(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$ticketIds = [];
		while (($row = $result->fetch()) !== false) {
			$ticketIds[] = (int)$row['ticket_id'];
		}
		$result->closeCursor();
		return $ticketIds;
	}
}
