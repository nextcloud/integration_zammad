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
	 * @param string $userId
	 * @param int[] $ticketIds
	 * @param int $generation
	 * @return int[] those of $ticketIds that had no row yet, i.e. that have never
	 *               been handed to ContextChat for this user
	 * @throws Exception
	 */
	public function markSeen(string $userId, array $ticketIds, int $generation): array {
		$ticketIds = array_values(array_unique(array_map('intval', $ticketIds)));
		$new = [];
		foreach (array_chunk($ticketIds, self::ID_CHUNK_SIZE) as $chunk) {
			$known = $this->filterKnown($userId, $chunk);
			if ($known !== []) {
				$qb = $this->db->getQueryBuilder();
				$qb->update(self::TABLE_NAME)
					->set('last_seen', $qb->createNamedParameter($generation, IQueryBuilder::PARAM_INT))
					->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
					->andWhere($qb->expr()->in('ticket_id', $qb->createNamedParameter($known, IQueryBuilder::PARAM_INT_ARRAY)));
				$qb->executeStatement();
			}
			foreach (array_diff($chunk, $known) as $ticketId) {
				$new[] = $ticketId;
				// a concurrent import of the same ticket may have inserted the row in
				// the meantime, which is exactly what we would have written ourselves
				$this->db->insertIgnoreConflict(self::TABLE_NAME, [
					'user_id' => $userId,
					'ticket_id' => $ticketId,
					'last_seen' => $generation,
				]);
			}
		}
		return $new;
	}

	/**
	 * The tickets of a user that the sweep $generation did not come across, in
	 * ascending ticket ID order.
	 *
	 * @param string $userId
	 * @param int $generation
	 * @param int $afterTicketId only return tickets with a higher ID, to page through the candidates
	 * @param int $limit
	 * @return int[]
	 * @throws Exception
	 */
	public function findStale(string $userId, int $generation, int $afterTicketId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lt('last_seen', $qb->createNamedParameter($generation, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('ticket_id', $qb->createNamedParameter($afterTicketId, IQueryBuilder::PARAM_INT)))
			->orderBy('ticket_id', 'ASC')
			->setMaxResults($limit);
		return $this->fetchTicketIds($qb);
	}

	/**
	 * @param int $ticketId
	 * @return string[] every user this ticket has been imported for
	 * @throws Exception
	 */
	public function findUsersForTicket(int $ticketId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
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
	 * @param string $userId
	 * @return int
	 * @throws Exception
	 */
	public function findMaxLastSeen(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('last_seen'))
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();
		return $max === false || $max === null ? 0 : (int)$max;
	}

	/**
	 * @param string $userId
	 * @return int[] every ticket that has been imported for this user
	 * @throws Exception
	 */
	public function findForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->fetchTicketIds($qb);
	}

	/**
	 * @param int[] $ticketIds
	 * @return int[] those of $ticketIds that no user has access to any more
	 * @throws Exception
	 */
	public function filterUnreferenced(array $ticketIds): array {
		$ticketIds = array_values(array_unique(array_map('intval', $ticketIds)));
		$unreferenced = [];
		foreach (array_chunk($ticketIds, self::ID_CHUNK_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('ticket_id')
				->from(self::TABLE_NAME)
				->where($qb->expr()->in('ticket_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$referenced = $this->fetchTicketIds($qb);
			$unreferenced = array_merge($unreferenced, array_values(array_diff($chunk, $referenced)));
		}
		return $unreferenced;
	}

	/**
	 * @param string $userId
	 * @param int $ticketId
	 * @return void
	 * @throws Exception
	 */
	public function delete(string $userId, int $ticketId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
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
	 * @param string $userId
	 * @param int[] $ticketIds
	 * @return int[] those of $ticketIds that already have a row for this user
	 * @throws Exception
	 */
	private function filterKnown(string $userId, array $ticketIds): array {
		if ($ticketIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('ticket_id', $qb->createNamedParameter($ticketIds, IQueryBuilder::PARAM_INT_ARRAY)));
		return $this->fetchTicketIds($qb);
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
