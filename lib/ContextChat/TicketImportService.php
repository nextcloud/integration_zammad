<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Zammad\ContextChat;

use DateTime;
use Exception;
use OCA\Zammad\AppInfo\Application;
use OCA\Zammad\BackgroundJob\ImportTicketsJob;
use OCA\Zammad\Db\ImportedTicketMapper;
use OCA\Zammad\Service\ZammadAPIService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\ContextChat\ContentItem;
use OCP\ContextChat\IContentManager;
use OCP\ContextChat\Type\UpdateAccessOp;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Imports Zammad tickets into ContextChat.
 *
 * Every connected user gets their own background job, which walks through the
 * tickets that user can see one chunk per run and starts over once it reaches
 * the end, so that ticket updates keep flowing into ContextChat.
 *
 * Zammad has no way of telling us that a ticket was deleted or that a user lost
 * access to it, but its ticket list only ever contains the tickets a user may
 * read. So once a sweep has walked the whole list, every ticket we imported for
 * that user that the sweep did not come across has disappeared. Those tickets are
 * checked one by one and then revoked, see {@see self::cleanupChunk()}.
 */
class TicketImportService {

	/**
	 * Number of tickets handled per background job run.
	 * Zammad refuses more than 100 per page.
	 */
	public const CHUNK_SIZE = 50;

	/**
	 * Number of disappeared tickets verified per background job run.
	 * Each one costs a Zammad API request.
	 */
	public const CLEANUP_CHUNK_SIZE = 20;

	/**
	 * Maximum number of bytes of ticket content handed to ContextChat.
	 * ContextChat silently drops content that is too large for its backend, so the
	 * article history of a long running ticket is cut off instead.
	 */
	public const MAX_CONTENT_SIZE = 1024 * 1024;

	/**
	 * Seconds subtracted from the start of a sweep before it is used as the
	 * watermark, so that a clock running behind the Zammad one cannot make us
	 * consider a ticket in sync that was modified while the sweep was starting.
	 */
	private const CLOCK_SKEW_MARGIN = 300;

	/** Next page of the ticket list to fetch */
	private const CONFIG_PAGE = 'cc_sweep_page';
	/** Tickets modified at or before this timestamp are in sync (last completed sweep) */
	private const CONFIG_SINCE = 'cc_sweep_since';
	/** Time at which the sweep that is currently running started */
	private const CONFIG_STARTED = 'cc_sweep_started';
	/** Lowest ticket modification timestamp that failed to import during the current sweep */
	private const CONFIG_FAILED = 'cc_sweep_failed';
	/** Number of the sweep that is currently running, used to tell apart the tickets it has seen */
	private const CONFIG_GENERATION = 'cc_sweep_generation';
	/** Whether the sweep is done and the tickets it did not see still have to be looked at */
	private const CONFIG_CLEANUP = 'cc_cleanup';
	/** Highest ticket ID the cleanup of the current sweep has already looked at */
	private const CONFIG_CLEANUP_CURSOR = 'cc_cleanup_cursor';

	private const CONFIG_KEYS = [
		self::CONFIG_PAGE,
		self::CONFIG_SINCE,
		self::CONFIG_STARTED,
		self::CONFIG_FAILED,
		self::CONFIG_GENERATION,
		self::CONFIG_CLEANUP,
		self::CONFIG_CLEANUP_CURSOR,
	];

	public function __construct(
		private IUserConfig $userConfig,
		private IUserManager $userManager,
		private IJobList $jobList,
		private ZammadAPIService $zammadAPIService,
		private IContentManager $contentManager,
		private ImportedTicketMapper $importedTicketMapper,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Schedule the import job for every user that has connected a Zammad account.
	 *
	 * @return void
	 */
	public function scheduleForAllUsers(): void {
		$this->userManager->callForSeenUsers(function (IUser $user): void {
			if ($this->hasToken($user->getUID())) {
				$this->scheduleForUser($user->getUID());
			}
		});
	}

	/**
	 * @param string $userId
	 * @return void
	 */
	public function scheduleForUser(string $userId): void {
		$argument = self::jobArgument($userId);
		if ($this->jobList->has(ImportTicketsJob::class, $argument)) {
			return;
		}
		// a previous disconnect may have failed to take away what had been imported.
		// The sweep number starts over from scratch together with the rest of the
		// state, so those rows would sit above every sweep that follows and never be
		// looked at again. Starting above them instead makes the first sweep treat
		// them like any other ticket it does not come across.
		try {
			$this->userConfig->setValueInt(
				$userId, Application::APP_ID, self::CONFIG_GENERATION,
				$this->importedTicketMapper->findMaxLastSeen($userId) + 1, lazy: true
			);
		} catch (Throwable $e) {
			// not worth holding up the import for, the worst case is that leftover
			// rows of an earlier connection are never looked at again
			$this->logger->warning(
				'Could not determine the ContextChat sweep to start from: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'userId' => $userId, 'exception' => $e]
			);
		}
		$this->jobList->add(ImportTicketsJob::class, $argument);
	}

	/**
	 * Stop importing for a user and take away everything we imported for them,
	 * their tickets are not accessible to them through Nextcloud any more.
	 *
	 * @param string $userId
	 * @return void
	 */
	public function unscheduleForUser(string $userId): void {
		$this->jobList->remove(ImportTicketsJob::class, self::jobArgument($userId));
		foreach (self::CONFIG_KEYS as $key) {
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, $key);
		}
		$this->revokeAllAccess($userId);
	}

	/**
	 * @param string $userId
	 * @return array{user_id: string}
	 */
	public static function jobArgument(string $userId): array {
		return ['user_id' => $userId];
	}

	/**
	 * Whether context_chat is installed and can take content.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		return $this->contentManager->isContextChatAvailable();
	}

	/**
	 * @param string $userId
	 * @return bool
	 */
	public function hasToken(string $userId): bool {
		return $this->userConfig->getValueString($userId, Application::APP_ID, 'token', lazy: true) !== '';
	}

	/**
	 * Import the next chunk of the user's tickets, or, once the sweep has walked
	 * the whole ticket list, look at the next chunk of the tickets it did not see.
	 *
	 * Only tickets that were modified since the last completed sweep are sent to
	 * ContextChat, so repeated sweeps cost one ticket list request per chunk as
	 * long as nothing changed.
	 *
	 * @param string $userId
	 * @return void
	 * @throws Exception
	 */
	public function importChunk(string $userId): void {
		if ($this->userConfig->getValueBool($userId, Application::APP_ID, self::CONFIG_CLEANUP, false, lazy: true)) {
			$this->cleanupChunk($userId);
			return;
		}

		$page = max(1, $this->userConfig->getValueInt($userId, Application::APP_ID, self::CONFIG_PAGE, 1, lazy: true));
		// taken before anything is fetched, everything modified after it is looked at
		// again by the next sweep even if this one no longer comes across it
		$startedTs = $this->getStartTimestamp($userId);
		$tickets = $this->zammadAPIService->getTickets($userId, $page, self::CHUNK_SIZE);
		if (isset($tickets['error'])) {
			// leave the sweep state untouched, the same page is retried on the next run
			$this->logger->warning(
				'Zammad API error: could not list tickets for the ContextChat import. ' . $tickets['error'],
				['app' => Application::APP_ID, 'userId' => $userId]
			);
			return;
		}

		$generation = $this->getGeneration($userId);
		$sinceTs = $this->getTimestamp($userId, self::CONFIG_SINCE);
		$failedTs = $this->getTimestamp($userId, self::CONFIG_FAILED);

		// record the whole page as still accessible before importing any of it, a
		// ticket we fail to import must not end up looking like it has disappeared
		$seenIds = [];
		foreach ($tickets as $ticket) {
			if (is_array($ticket) && isset($ticket['id'])) {
				$seenIds[] = (int)$ticket['id'];
			}
		}
		// tickets we had no row for yet have never been handed to ContextChat, no
		// matter what their modification time says. A sweep can miss a ticket when
		// deletions shift the pages under it, and the watermark moves past it in the
		// meantime, so without this they would stay out of the index until someone
		// touches them again.
		$newIds = $this->importedTicketMapper->markSeen($userId, $seenIds, $generation);

		foreach ($tickets as $ticket) {
			if (!is_array($ticket) || !isset($ticket['id'], $ticket['title'], $ticket['updated_at'])) {
				continue;
			}
			$ticketTs = $this->parseTimestamp((string)$ticket['updated_at']);
			if ($ticketTs > 0 && $ticketTs <= $sinceTs && !in_array((int)$ticket['id'], $newIds, true)) {
				// unchanged since the last completed sweep, and already imported
				continue;
			}
			try {
				$this->importTicket($userId, $ticket);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Could not import Zammad ticket ' . $ticket['id'] . ' into ContextChat: ' . $e->getMessage(),
					['app' => Application::APP_ID, 'userId' => $userId, 'exception' => $e]
				);
				// a ticket whose modification time we could not parse carries no
				// information about how far back the sweep has to reach, and folding its
				// zero in here would drop the watermark and lose every other failure
				if ($ticketTs > 0) {
					$failedTs = $failedTs === 0 ? $ticketTs : min($failedTs, $ticketTs);
				}
			}
		}

		if (count($tickets) >= self::CHUNK_SIZE) {
			$this->setTimestamp($userId, self::CONFIG_FAILED, $failedTs);
			$this->userConfig->setValueInt($userId, Application::APP_ID, self::CONFIG_PAGE, $page + 1, lazy: true);
			return;
		}

		// the sweep has reached the end of the ticket list. Every ticket that was
		// modified before it started has been looked at in its final state, so that
		// is the point up to which we are in sync. The highest modification time the
		// sweep came across must not be used instead: the sweep walks the list by
		// ticket ID over many runs, so a ticket modified after the sweep passed its
		// page carries a lower modification time than tickets seen later on and would
		// never be picked up again.
		// Tickets that failed to import must be picked up again as well, so the
		// watermark never moves past the oldest failure of this sweep.
		$watermark = $failedTs > 0 ? min($startedTs, $failedTs - 1) : $startedTs;
		$this->setTimestamp($userId, self::CONFIG_SINCE, $watermark);
		$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::CONFIG_STARTED);
		$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::CONFIG_FAILED);
		$this->userConfig->setValueInt($userId, Application::APP_ID, self::CONFIG_PAGE, 1, lazy: true);
		// everything the sweep did not see is gone, the next runs take care of it
		// before the following sweep starts over from the first page
		$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::CONFIG_CLEANUP_CURSOR);
		$this->userConfig->setValueBool($userId, Application::APP_ID, self::CONFIG_CLEANUP, true, lazy: true);
	}

	/**
	 * Look at the next chunk of tickets that the completed sweep did not come
	 * across and revoke the ones that really are gone.
	 *
	 * @param string $userId
	 * @return void
	 * @throws Exception
	 */
	private function cleanupChunk(string $userId): void {
		$generation = $this->getGeneration($userId);
		$cursor = max(0, $this->userConfig->getValueInt($userId, Application::APP_ID, self::CONFIG_CLEANUP_CURSOR, 0, lazy: true));
		$candidates = $this->importedTicketMapper->findStale($userId, $generation, $cursor, self::CLEANUP_CHUNK_SIZE);

		foreach ($candidates as $ticketId) {
			$cursor = max($cursor, $ticketId);
			try {
				$this->reconcileTicket($userId, $ticketId, $generation);
			} catch (Throwable $e) {
				// we could not tell whether the ticket is gone, keep it and look at it
				// again after the next sweep. Keeping a ticket that is gone is a lot
				// less harmful than dropping one that is not.
				$this->logger->warning(
					'Could not check whether Zammad ticket ' . $ticketId . ' is still accessible: ' . $e->getMessage(),
					['app' => Application::APP_ID, 'userId' => $userId, 'exception' => $e]
				);
			}
		}

		if (count($candidates) < self::CLEANUP_CHUNK_SIZE) {
			// the cleanup has reached the end of the candidates, the next sweep starts
			$this->userConfig->setValueInt($userId, Application::APP_ID, self::CONFIG_GENERATION, $generation + 1, lazy: true);
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::CONFIG_CLEANUP);
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::CONFIG_CLEANUP_CURSOR);
			return;
		}
		$this->userConfig->setValueInt($userId, Application::APP_ID, self::CONFIG_CLEANUP_CURSOR, $cursor, lazy: true);
	}

	/**
	 * Ask Zammad about a single ticket that the sweep did not see and revoke it if
	 * it really is gone.
	 *
	 * The sweep pages through the ticket list over many runs, so a ticket that was
	 * deleted while the sweep was running can shift the pages enough for another
	 * ticket to be skipped. Asking Zammad about every candidate keeps those from
	 * being thrown out.
	 *
	 * @param string $userId
	 * @param int $ticketId
	 * @param int $generation
	 * @return void
	 * @throws Exception if it could not be determined whether the ticket is gone
	 */
	private function reconcileTicket(string $userId, int $ticketId, int $generation): void {
		$ticket = $this->zammadAPIService->getTicketInfo($userId, $ticketId);
		if (!isset($ticket['error'])) {
			// Still accessible, the sweep just missed it. Import it instead of only
			// recording it as seen: the watermark has already moved past this sweep, so
			// a modification the sweep skipped over would never be picked up again.
			if (isset($ticket['id'], $ticket['title'], $ticket['updated_at'])) {
				$this->importTicket($userId, $ticket);
			} else {
				$this->importedTicketMapper->markSeen($userId, [$ticketId], $generation);
			}
			return;
		}
		$errorCode = (int)($ticket['error-code'] ?? 0);
		if ($errorCode !== Http::STATUS_FORBIDDEN && $errorCode !== Http::STATUS_NOT_FOUND) {
			// a bad token, a network problem or a broken Zammad tells us nothing about
			// the ticket itself
			throw new RuntimeException('Zammad API error: ' . $ticket['error']);
		}
		$this->revokeAccess($userId, $ticketId);
	}

	/**
	 * Take a ticket away from a user, and drop its content once no user is left
	 * that can see it.
	 *
	 * Zammad answers with the same status for a deleted ticket and for one the user
	 * may not read any more, so we do not try to tell the two apart: the content is
	 * only deleted when the last user has lost access to it.
	 *
	 * @param string $userId
	 * @param int $ticketId
	 * @return void
	 * @throws Exception
	 */
	public function revokeAccess(string $userId, int $ticketId): void {
		$itemId = (string)$ticketId;
		$this->contentManager->updateAccess(
			Application::APP_ID, ContentProvider::ID, $itemId, UpdateAccessOp::DENY, [$userId]
		);
		$this->importedTicketMapper->delete($userId, $ticketId);
		if ($this->importedTicketMapper->filterUnreferenced([$ticketId]) !== []) {
			$this->contentManager->deleteContent(Application::APP_ID, ContentProvider::ID, [$itemId]);
		}
	}

	/**
	 * Take away every ticket that was imported for a user.
	 *
	 * @param string $userId
	 * @return void
	 */
	public function revokeAllAccess(string $userId): void {
		try {
			$ticketIds = $this->importedTicketMapper->findForUser($userId);
			// the rows are the only record of what this user had access to, so they are
			// dropped only once ContextChat has taken the access away. Failing the other
			// way around would leave the user reading their tickets forever, with nothing
			// left to tell us about it
			$this->contentManager->updateAccessProvider(
				Application::APP_ID, ContentProvider::ID, UpdateAccessOp::DENY, [$userId]
			);
			$this->importedTicketMapper->deleteForUser($userId);
			foreach (array_chunk($this->importedTicketMapper->filterUnreferenced($ticketIds), 500) as $orphans) {
				$this->contentManager->deleteContent(
					Application::APP_ID, ContentProvider::ID, array_map('strval', $orphans)
				);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Could not revoke the ContextChat access of ' . $userId . ' to the Zammad tickets: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'userId' => $userId, 'exception' => $e]
			);
		}
	}

	/**
	 * @param string $userId
	 * @param array $ticket a ticket as returned by the Zammad API
	 * @return void
	 * @throws Exception
	 */
	public function importTicket(string $userId, array $ticket): void {
		$ticketId = (int)$ticket['id'];
		$itemId = (string)$ticketId;
		// a ticket can be visible to several Nextcloud users and the item ID is the
		// same for all of them. Submitting content sets the access list of the item
		// to the users it carries, so the users the ticket was already imported for
		// have to be submitted along with the one we are importing it for.
		$users = $this->importedTicketMapper->findUsersForTicket($ticketId);
		if (!in_array($userId, $users, true)) {
			$users[] = $userId;
		}
		$item = new ContentItem(
			$itemId,
			ContentProvider::ID,
			(string)$ticket['title'],
			$this->getTicketContent($userId, $ticketId),
			'Ticket',
			$this->parseDateTime((string)$ticket['updated_at']),
			$users,
		);
		$this->contentManager->submitContent(Application::APP_ID, [$item]);
		// another user may have submitted the same item in the meantime without
		// knowing about this one yet, so grant access additively on top of it
		$this->contentManager->updateAccess(
			Application::APP_ID, ContentProvider::ID, $itemId, UpdateAccessOp::ALLOW, [$userId]
		);
		$this->importedTicketMapper->markSeen($userId, [$ticketId], $this->getGeneration($userId));
	}

	/**
	 * @param string $userId
	 * @param int $ticketId
	 * @return string
	 * @throws Exception
	 */
	public function getTicketContent(string $userId, int $ticketId): string {
		$articles = $this->zammadAPIService->getArticlesByTicket($userId, $ticketId);
		if (isset($articles['error'])) {
			throw new RuntimeException('Could not get ticket articles: ' . $articles['error']);
		}
		$content = '';
		foreach ($articles as $article) {
			if (!is_array($article)) {
				continue;
			}
			$body = (string)($article['body'] ?? '');
			if (($article['content_type'] ?? '') === 'text/html') {
				$body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
			$from = trim((string)($article['from'] ?? ''));
			$content .= ($from === '' ? '' : $from . ":\n\n") . $body . "\n\n";
			// ContextChat drops content that is too large for its backend without
			// telling us, which would leave the ticket recorded as imported but
			// missing from the index. Cut the article history off instead.
			if (mb_strlen($content, '8bit') >= self::MAX_CONTENT_SIZE) {
				$this->logger->info(
					'Zammad ticket ' . $ticketId . ' is too large for ContextChat, only part of it is imported.',
					['app' => Application::APP_ID, 'userId' => $userId]
				);
				return mb_strcut($content, 0, self::MAX_CONTENT_SIZE, 'UTF-8');
			}
		}
		return $content;
	}

	/**
	 * @param string $date
	 * @return DateTime
	 * @throws Exception
	 */
	private function parseDateTime(string $date): DateTime {
		try {
			return new DateTime($date);
		} catch (Exception $e) {
			return new DateTime('@0');
		}
	}

	/**
	 * @param string $date
	 * @return int the unix timestamp or 0 if the date could not be parsed
	 */
	private function parseTimestamp(string $date): int {
		try {
			return (new DateTime($date))->getTimestamp();
		} catch (Exception $e) {
			return 0;
		}
	}

	/**
	 * The number of the sweep that is currently running.
	 *
	 * @param string $userId
	 * @return int
	 */
	private function getGeneration(string $userId): int {
		return max(1, $this->userConfig->getValueInt($userId, Application::APP_ID, self::CONFIG_GENERATION, 1, lazy: true));
	}

	/**
	 * The time at which the sweep that is currently running started, recorded on
	 * its first run.
	 *
	 * @param string $userId
	 * @return int
	 */
	private function getStartTimestamp(string $userId): int {
		$startedTs = $this->getTimestamp($userId, self::CONFIG_STARTED);
		if ($startedTs === 0) {
			$startedTs = max(0, $this->timeFactory->getTime() - self::CLOCK_SKEW_MARGIN);
			$this->setTimestamp($userId, self::CONFIG_STARTED, $startedTs);
		}
		return $startedTs;
	}

	/**
	 * @param string $userId
	 * @param string $key
	 * @return int
	 */
	private function getTimestamp(string $userId, string $key): int {
		return max(0, $this->userConfig->getValueInt($userId, Application::APP_ID, $key, 0, lazy: true));
	}

	/**
	 * @param string $userId
	 * @param string $key
	 * @param int $timestamp
	 * @return void
	 */
	private function setTimestamp(string $userId, string $key, int $timestamp): void {
		$this->userConfig->setValueInt($userId, Application::APP_ID, $key, $timestamp, lazy: true);
	}
}
