<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Zammad\Db;

use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Remembers which version of a Zammad ticket was last handed to ContextChat.
 *
 * There is one ContextChat item per ticket, shared by every user that can see
 * it, and its content is the same no matter whose token fetched it, see
 * {@see \OCA\Zammad\ContextChat\TicketImportService::getTicketContent()}. So the
 * content only has to be submitted once per version of the ticket, by whichever
 * user's sweep comes across that version first. Every other user only needs to be
 * given access to it, see {@see ImportedTicketMapper}.
 *
 * Without this, every user re-submitted the full content of every ticket they can
 * see, and ContextChat re-indexed it each time: on a Zammad where all users can see
 * all tickets, that is the number of users times the work for every ticket and for
 * every change to one.
 *
 * A version is the modification time of the ticket in milliseconds, as the ticket
 * list reported it before the content was fetched. The content is fetched after
 * the list, so it is at least as new as the version recorded for it.
 */
class TicketContentMapper {

	public const TABLE_NAME = 'zammad_cc_content';

	/** Maximum number of ticket IDs to put into a single IN () clause */
	private const ID_CHUNK_SIZE = 1000;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param string $instance
	 * @param int $ticketId
	 * @return int|null the version last handed to ContextChat, null if none is known
	 * @throws Exception
	 */
	public function findVersion(string $instance, int $ticketId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('version')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('instance', $qb->createNamedParameter($instance)))
			->andWhere($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$version = $result->fetchOne();
		$result->closeCursor();
		return $version === false || $version === null ? null : (int)$version;
	}

	/**
	 * Remember that a version of the ticket has been handed to ContextChat.
	 *
	 * Two users submitting the same ticket at once may record their versions in
	 * either order. A version that ends up lower than what was submitted only costs
	 * one more submit later on, so no attempt is made to keep the higher one.
	 *
	 * @param string $instance
	 * @param int $ticketId
	 * @param int $version
	 * @return void
	 * @throws Exception
	 */
	public function setVersion(string $instance, int $ticketId, int $version): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('version', $qb->createNamedParameter($version, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('instance', $qb->createNamedParameter($instance)))
			->andWhere($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() !== 0) {
			return;
		}
		// no row yet, or one that already said exactly this
		$this->db->insertIgnoreConflict(self::TABLE_NAME, [
			'instance' => $instance,
			'ticket_id' => $ticketId,
			'version' => $version,
		]);
	}

	/**
	 * Forget what was submitted for the given tickets, so that the next user that
	 * comes across one of them submits it again.
	 *
	 * @param string $instance
	 * @param int[] $ticketIds
	 * @return void
	 * @throws Exception
	 */
	public function delete(string $instance, array $ticketIds): void {
		$ticketIds = array_values(array_unique(array_map('intval', $ticketIds)));
		foreach (array_chunk($ticketIds, self::ID_CHUNK_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE_NAME)
				->where($qb->expr()->eq('instance', $qb->createNamedParameter($instance)))
				->andWhere($qb->expr()->in('ticket_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	/**
	 * Forget everything that was submitted.
	 *
	 * @return void
	 * @throws Exception
	 */
	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME);
		$qb->executeStatement();
	}
}
