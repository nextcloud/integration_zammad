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
use OCP\IAppConfig;
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
	 *
	 * This is a budget of our own, well below the limit ContextChat applies: it
	 * refuses an item larger than its `indexing_max_size`, which defaults to 100MB
	 * and is also the size of a whole indexing batch, and says so in the log. The
	 * article history of a long running ticket is cut off here rather than handing
	 * over something that would push everything else out of a batch.
	 */
	public const MAX_CONTENT_SIZE = 10 * 1024 * 1024;

	/**
	 * Number of times importing a single ticket may fail before the sweep stops
	 * holding the watermark back for it. Until then every ticket modified after it
	 * is looked at again on every sweep, which a ticket that can never be imported
	 * would otherwise keep up forever.
	 */
	public const MAX_IMPORT_FAILURES = 5;

	/**
	 * Number of times a page of the ticket list may fail to be fetched before the
	 * sweep moves past it. Retrying is the right answer to a Zammad that is down or
	 * a request that timed out, but a page it cannot serve at all would otherwise
	 * stall that user's import for good, see {@see self::notePageFailure()}.
	 */
	public const MAX_PAGE_FAILURES = 5;

	/**
	 * Seconds a token that went missing on its own has to stay missing before what
	 * was imported for that user is taken away. A single request Zammad answers
	 * with a 401 is enough to wipe the token, and rebuilding the index of a large
	 * mailbox takes days.
	 */
	public const TOKEN_GRACE_PERIOD = 7 * 24 * 60 * 60;

	/**
	 * Seconds subtracted from the start of a sweep before it is used as the
	 * watermark, so that a clock running behind the Zammad one cannot make us
	 * consider a ticket in sync that was modified while the sweep was starting.
	 */
	private const CLOCK_SKEW_MARGIN = 300;

	/** Next page of the ticket list to fetch */
	private const CONFIG_PAGE = 'cc_sweep_page';
	/** Consecutive times the page the sweep is on could not be fetched or used */
	private const CONFIG_PAGE_FAILURES = 'cc_sweep_page_failures';
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
	/** The Zammad instance the tickets imported so far were taken from */
	private const CONFIG_INSTANCE = 'cc_instance';
	/** Time at which the user's token was first found missing */
	private const CONFIG_TOKEN_LOST = 'cc_token_lost';

	/**
	 * App config key prefix the URL of a Zammad instance is recorded under, one key
	 * per instance so that no two users writing at once can lose each other's entry
	 */
	private const CONFIG_INSTANCE_URL_PREFIX = 'cc_instance_url_';

	/**
	 * Every key the sweep state is kept under, so that it can be dropped as a whole
	 */
	private const CONFIG_KEYS = [
		self::CONFIG_PAGE,
		self::CONFIG_PAGE_FAILURES,
		self::CONFIG_SINCE,
		self::CONFIG_STARTED,
		self::CONFIG_FAILED,
		self::CONFIG_GENERATION,
		self::CONFIG_CLEANUP,
		self::CONFIG_CLEANUP_CURSOR,
		self::CONFIG_INSTANCE,
		self::CONFIG_TOKEN_LOST,
	];

	/**
	 * Whether we are still importing for a user, memoised for the run of one job.
	 *
	 * @var array<string, bool>
	 */
	private array $scheduled = [];

	/**
	 * The URL recorded for an instance, memoised for the run of one job so that
	 * resolving the instance of a user does not write on every call.
	 *
	 * @var array<string, string>
	 */
	private array $instanceUrls = [];

	public function __construct(
		private IAppConfig $appConfig,
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
			$this->scheduled[$userId] = true;
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
				$this->importedTicketMapper->findMaxLastSeen($this->getInstanceId($userId), $userId) + 1, lazy: true
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
		// the memo outlives this call: one cron run executes many jobs in the same
		// process and the service is resolved once for all of them, see
		// self::isScheduled()
		$this->scheduled[$userId] = true;
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
		// an import later in the same process must not rebuild the access list of an
		// item from a memo taken before this, see self::isScheduled()
		$this->scheduled[$userId] = false;
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
	 * Whether a user still has an import job of their own, which is what tells the
	 * rows of a user we are still importing for apart from the ones a disconnect
	 * failed to clean up, see {@see self::unscheduleForUser()}.
	 *
	 * @param string $userId
	 * @return bool
	 */
	private function isScheduled(string $userId): bool {
		return $this->scheduled[$userId] ??= $this->jobList->has(ImportTicketsJob::class, self::jobArgument($userId));
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
		if ($this->getTimestamp($userId, self::CONFIG_TOKEN_LOST) !== 0) {
			// the token is back, see self::hasTokenStayedMissing()
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::CONFIG_TOKEN_LOST);
		}

		$instance = $this->getInstanceId($userId);
		$this->resetOnInstanceChange($userId, $instance);

		if ($this->userConfig->getValueBool($userId, Application::APP_ID, self::CONFIG_CLEANUP, false, lazy: true)) {
			$this->cleanupChunk($userId, $instance);
			return;
		}

		$page = max(1, $this->userConfig->getValueInt($userId, Application::APP_ID, self::CONFIG_PAGE, 1, lazy: true));
		// taken before anything is fetched, everything modified after it is looked at
		// again by the next sweep even if this one no longer comes across it
		$startedTs = $this->getStartTimestamp($userId);
		$tickets = $this->zammadAPIService->getTickets($userId, $page, self::CHUNK_SIZE);
		if (isset($tickets['error'])) {
			$this->notePageFailure(
				$userId, $page,
				'Zammad API error: could not list tickets for the ContextChat import. ' . $tickets['error']
			);
			return;
		}
		// A 200 whose body is not a list of tickets at all: an error shape we do not
		// know, or whatever a proxy in front of Zammad answered with. Left unchecked
		// it would go through both loops below without yielding a single ticket and
		// then, being shorter than a full page, be read as the end of the ticket
		// list, which hands every ticket of the pages that were never fetched to the
		// cleanup and has them fetched back one by one.
		if (!array_is_list($tickets)) {
			$this->notePageFailure(
				$userId, $page,
				'Zammad API error: the ticket list for the ContextChat import is not a list of tickets.'
			);
			return;
		}
		if ($this->userConfig->getValueInt($userId, Application::APP_ID, self::CONFIG_PAGE_FAILURES, 0, lazy: true) !== 0) {
			// the page came through, whatever was wrong with it before is over
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::CONFIG_PAGE_FAILURES);
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
		// tickets that have not been handed to ContextChat yet have to be imported no
		// matter what their modification time says. A sweep can miss a ticket when
		// deletions shift the pages under it, and the watermark moves past it in the
		// meantime, so without this they would stay out of the index until someone
		// touches them again. Being recorded above is not enough to count as
		// imported: a run that dies between the two would otherwise leave a row that
		// makes the ticket look done for good.
		$pendingIds = $this->importedTicketMapper->markSeen($instance, $userId, $seenIds, $generation);

		foreach ($tickets as $ticket) {
			if (!is_array($ticket) || !isset($ticket['id'], $ticket['title'], $ticket['updated_at'])) {
				continue;
			}
			$ticketTs = $this->parseTimestamp((string)$ticket['updated_at']);
			if ($ticketTs > 0 && $ticketTs <= $sinceTs && !in_array((int)$ticket['id'], $pendingIds, true)) {
				// unchanged since the last completed sweep, and already imported
				continue;
			}
			try {
				$this->importTicket($userId, $ticket, $instance);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Could not import Zammad ticket ' . $ticket['id'] . ' into ContextChat: ' . $e->getMessage(),
					['app' => Application::APP_ID, 'userId' => $userId, 'exception' => $e]
				);
				$failures = $this->noteImportFailure($instance, $userId, (int)$ticket['id']);
				// a ticket whose modification time we could not parse carries no
				// information about how far back the sweep has to reach, and folding its
				// zero in here would drop the watermark and lose every other failure
				if ($ticketTs > 0 && $failures <= self::MAX_IMPORT_FAILURES) {
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
		// watermark never moves past the oldest failure of this sweep. One that has
		// failed too often no longer counts, see self::noteImportFailure(): it is
		// still retried once per sweep, but it stops dragging every ticket modified
		// after it along with it.
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
	 * @param string $instance
	 * @return void
	 * @throws Exception
	 */
	private function cleanupChunk(string $userId, string $instance): void {
		$generation = $this->getGeneration($userId);
		$cursor = max(0, $this->userConfig->getValueInt($userId, Application::APP_ID, self::CONFIG_CLEANUP_CURSOR, 0, lazy: true));
		$candidates = $this->importedTicketMapper->findStale($instance, $userId, $generation, $cursor, self::CLEANUP_CHUNK_SIZE);

		foreach ($candidates as $ticketId) {
			$cursor = max($cursor, $ticketId);
			try {
				$this->reconcileTicket($userId, $ticketId, $generation, $instance);
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
	 * @param string $instance
	 * @return void
	 * @throws Exception if it could not be determined whether the ticket is gone
	 */
	private function reconcileTicket(string $userId, int $ticketId, int $generation, string $instance): void {
		$ticket = $this->zammadAPIService->getTicketInfo($userId, $ticketId);
		if (!isset($ticket['error'])) {
			// Still accessible, the sweep just missed it. Import it instead of only
			// recording it as seen: the watermark has already moved past this sweep, so
			// a modification the sweep skipped over would never be picked up again.
			if (isset($ticket['id'], $ticket['title'], $ticket['updated_at'])) {
				$this->importTicket($userId, $ticket, $instance);
			} else {
				$this->importedTicketMapper->markSeen($instance, $userId, [$ticketId], $generation);
			}
			return;
		}
		$errorCode = (int)($ticket['error-code'] ?? 0);
		if ($errorCode !== Http::STATUS_FORBIDDEN && $errorCode !== Http::STATUS_NOT_FOUND) {
			// a bad token, a network problem or a broken Zammad tells us nothing about
			// the ticket itself
			throw new RuntimeException('Zammad API error: ' . $ticket['error']);
		}
		$this->revokeAccess($userId, $ticketId, $instance);
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
	 * @param string $instance
	 * @return void
	 * @throws Exception
	 */
	public function revokeAccess(string $userId, int $ticketId, string $instance): void {
		$itemId = self::getItemId($instance, $ticketId);
		$this->contentManager->updateAccess(
			Application::APP_ID, ContentProvider::ID, $itemId, UpdateAccessOp::DENY, [$userId]
		);
		$this->importedTicketMapper->delete($instance, $userId, $ticketId);
		if ($this->importedTicketMapper->filterUnreferenced($instance, [$ticketId]) !== []) {
			$this->contentManager->deleteContent(Application::APP_ID, ContentProvider::ID, [$itemId]);
			return;
		}
		// the item stays, shared with the users that kept the ticket. Its access list
		// is whatever the last submit put there and may still hold this user, see
		// ImportedTicketMapper::markPending(), so those users hand it over again.
		$this->importedTicketMapper->markPending($instance, [$ticketId]);
	}

	/**
	 * Take away every ticket that was imported for a user.
	 *
	 * @param string $userId
	 * @return void
	 */
	public function revokeAllAccess(string $userId): void {
		try {
			// a user that has moved their account to another Zammad server still has
			// the rows of the one they came from, and a ticket ID only means something
			// within the instance it was imported from
			$ticketIdsByInstance = $this->importedTicketMapper->findForUser($userId);
			// the rows are the only record of what this user had access to, so the access
			// is taken away before they are dropped. Failing the other way around would
			// leave the user reading their tickets forever, with nothing left to tell us
			// about it. This is the better order rather than a guarantee: ContextChat
			// logs and swallows the errors of its own scheduling, so a call that did not
			// get through looks exactly like one that did
			$this->contentManager->updateAccessProvider(
				Application::APP_ID, ContentProvider::ID, UpdateAccessOp::DENY, [$userId]
			);
			$this->importedTicketMapper->deleteForUser($userId);
			foreach ($ticketIdsByInstance as $instance => $ticketIds) {
				// PHP turns an array key that looks like a number into an int
				$instance = (string)$instance;
				$unreferenced = $this->importedTicketMapper->filterUnreferenced($instance, $ticketIds);
				foreach (array_chunk($unreferenced, 500) as $orphans) {
					$this->contentManager->deleteContent(
						Application::APP_ID, ContentProvider::ID,
						array_map(fn (int $ticketId): string => self::getItemId($instance, $ticketId), $orphans)
					);
				}
				// the items of the tickets the others kept may still hold this user in their
				// access list, see ImportedTicketMapper::markPending()
				$this->importedTicketMapper->markPending($instance, array_values(array_diff($ticketIds, $unreferenced)));
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
	 * @param string|null $instance the Zammad instance the ticket was taken from,
	 *                              resolved from the user's configuration if omitted
	 * @return void
	 * @throws Exception
	 */
	public function importTicket(string $userId, array $ticket, ?string $instance = null): void {
		$ticketId = (int)$ticket['id'];
		$instance ??= $this->getInstanceId($userId);
		$itemId = self::getItemId($instance, $ticketId);
		// a ticket can be visible to several Nextcloud users of the same Zammad
		// instance and the item ID is the same for all of them. Submitting content
		// sets the access list of the item to the users it carries, so the users the
		// ticket was already imported for have to be submitted along with the one we
		// are importing it for. One item for all of them means it may only hold what
		// all of them may read, see self::getTicketContent().
		// a disconnect whose cleanup failed leaves rows behind, see
		// self::revokeAllAccess(), and submitting one of those users would hand them
		// the ticket back. A missing token is not the signal for that: it comes back
		// on its own often enough that the import sits it out, see
		// self::hasTokenStayedMissing(), and a user dropped from the list here would
		// not be put back into it by their own sweep, which skips the tickets it has
		// already imported. Having a job of their own is what says we still import
		// for a user, and it is taken away together with their rows.
		$users = array_values(array_filter(
			$this->importedTicketMapper->findUsersForTicket($instance, $ticketId),
			fn (string $otherUserId): bool => $otherUserId === $userId || $this->isScheduled($otherUserId),
		));
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
		$this->importedTicketMapper->markImported($instance, $userId, $ticketId, $this->getGeneration($userId));
	}

	/**
	 * The article history of a ticket, as far as every user the item is shared
	 * with may read it.
	 *
	 * Zammad answers this endpoint with what the token it was asked with is allowed
	 * to see: an agent gets the internal articles of a ticket, a customer does not.
	 * The item that carries them is shared by every user of the ticket though, see
	 * {@see self::importTicket()}, so whichever of them imported the ticket last
	 * would decide what all of the others get to read. Internal articles are left
	 * out for that reason, which makes the result the same no matter who asked for
	 * it. It also keeps them out of the index entirely.
	 *
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
			// anything but a plain false counts as internal here, an article we cannot
			// tell about must not end up in front of a customer
			if (!empty($article['internal'])) {
				continue;
			}
			$remaining = self::MAX_CONTENT_SIZE - strlen($content);
			$body = (string)($article['body'] ?? '');
			// The budget is applied to the raw body first, before anything is built
			// out of it: htmlToText() walks a body several times over and keeps the
			// result of every step, so a single huge article would cost a multiple of
			// its own size in memory on the way to being cut down here. Running out of
			// memory is a fatal error that takes the whole cron run with it, not
			// something the caller could catch and count as a failed import.
			$overBudget = strlen($body) > $remaining;
			if ($overBudget) {
				$body = mb_strcut($body, 0, $remaining, 'UTF-8');
			}
			if (($article['content_type'] ?? '') === 'text/html') {
				$body = self::htmlToText($body);
			}
			$from = trim((string)($article['from'] ?? ''));
			$next = ($from === '' ? '' : $from . ":\n\n") . $body . "\n\n";
			// ContextChat drops content that is too large for its backend without
			// telling us, which would leave the ticket recorded as imported but
			// missing from the index. Cut the article history off instead.
			if ($overBudget || strlen($next) >= $remaining) {
				$this->logger->info(
					'Zammad ticket ' . $ticketId . ' is too large for ContextChat, only part of it is imported.',
					['app' => Application::APP_ID, 'userId' => $userId]
				);
				return $content . mb_strcut($next, 0, $remaining, 'UTF-8');
			}
			$content .= $next;
		}
		return $content;
	}

	/**
	 * The text of an HTML article body, as it goes into the index.
	 *
	 * strip_tags() on its own is not enough for the HTML mails that make up most of
	 * the article history of a ticket: it takes out the tags but keeps what is
	 * between them, so the stylesheet of a mail ends up in the index, and it leaves
	 * no whitespace behind, so the last word of a paragraph and the first word of
	 * the next one are glued into one.
	 *
	 * @param string $html
	 * @return string
	 */
	private static function htmlToText(string $html): string {
		// these carry no text of the article, tags and content alike
		$text = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;
		// the line structure the markup carries, before the markup itself is gone
		$text = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6]|/table|/blockquote)\b[^>]*>#i', "\n", $text) ?? $text;
		// neighbouring cells of a row belong on one line, but not in one word
		$text = preg_replace('#</(td|th)\b[^>]*>#i', ' ', $text) ?? $text;
		$text = strip_tags($text);
		// decoded only once the tags are gone, so that an escaped tag in the article
		// body stays the text the author wrote instead of turning into markup that
		// strip_tags() would have taken out
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		// whatever whitespace the markup was laid out with, collapsed, while the line
		// breaks that stand for a block of their own are kept
		$text = preg_replace('#[^\S\n]+#u', ' ', $text) ?? $text;
		$text = preg_replace('#[^\S\n]*\n[^\S\n]*#u', "\n", $text) ?? $text;
		$text = preg_replace('#\n{3,}#', "\n\n", $text) ?? $text;
		return trim($text);
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
	 * Count an import attempt that failed, and say whether the ticket has run out
	 * of attempts.
	 *
	 * A ticket the sweep can never import would otherwise hold the watermark just
	 * below its modification time forever, which makes every sweep import every
	 * ticket modified after it again, for good.
	 *
	 * @param string $instance
	 * @param string $userId
	 * @param int $ticketId
	 * @return int how often importing this ticket has failed in a row
	 */
	private function noteImportFailure(string $instance, string $userId, int $ticketId): int {
		try {
			$failures = $this->importedTicketMapper->noteFailure($instance, $userId, $ticketId);
		} catch (Throwable $e) {
			// we cannot tell how often this has happened, so treat it as the first
			// time: holding the watermark back costs a sweep, moving it on costs the
			// ticket
			$this->logger->warning(
				'Could not count the failed import of Zammad ticket ' . $ticketId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID, 'userId' => $userId, 'exception' => $e]
			);
			return 1;
		}
		if ($failures === self::MAX_IMPORT_FAILURES + 1) {
			$this->logger->warning(
				'Zammad ticket ' . $ticketId . ' has failed to import ' . $failures . ' times, the sweep stops waiting for it.',
				['app' => Application::APP_ID, 'userId' => $userId]
			);
		}
		return $failures;
	}

	/**
	 * Count a page of the ticket list the sweep could not use, and move past it
	 * once it has failed often enough.
	 *
	 * Leaving the sweep state untouched is the right answer to a Zammad that is
	 * down or a request that timed out: the same page is fetched again on the next
	 * run. A page Zammad can never serve would stall that user's import for good
	 * though, one page short of ever completing a sweep, with no ticket behind it
	 * ever imported and the cleanup never reached.
	 *
	 * Nothing is lost by moving on. The tickets of the page that were imported
	 * before are not seen by this sweep, so the cleanup asks Zammad about each of
	 * them and imports them again, see {@see self::reconcileTicket()}; the ones
	 * that were never imported are picked up by the next sweep that gets the page,
	 * whatever the watermark says by then, see {@see self::importChunk()}.
	 *
	 * @param string $userId
	 * @param int $page the page that could not be used
	 * @param string $message what was wrong with it, for the log
	 * @return void
	 */
	private function notePageFailure(string $userId, int $page, string $message): void {
		$failures = max(0, $this->userConfig->getValueInt($userId, Application::APP_ID, self::CONFIG_PAGE_FAILURES, 0, lazy: true)) + 1;
		if ($failures <= self::MAX_PAGE_FAILURES) {
			$this->userConfig->setValueInt($userId, Application::APP_ID, self::CONFIG_PAGE_FAILURES, $failures, lazy: true);
			$this->logger->warning($message, ['app' => Application::APP_ID, 'userId' => $userId]);
			return;
		}
		$this->logger->error(
			$message . ' Page ' . $page . ' of the ticket list has failed ' . $failures
			. ' times in a row, the ContextChat import moves past it.',
			['app' => Application::APP_ID, 'userId' => $userId]
		);
		$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::CONFIG_PAGE_FAILURES);
		$this->userConfig->setValueInt($userId, Application::APP_ID, self::CONFIG_PAGE, $page + 1, lazy: true);
	}

	/**
	 * Whether a token that went missing on its own has stayed missing long enough
	 * to take away what was imported for that user.
	 *
	 * A disconnect the user asked for takes their tickets away right away, see
	 * {@see \OCA\Zammad\Controller\ConfigController::setSensitiveConfig()}. Getting
	 * here means the token disappeared on its own instead, and one request Zammad
	 * answers with a 401 is enough for that. Giving up on the import straight away
	 * would turn a Zammad restart into days of re-importing, so it is only given up
	 * once the user has had the chance to connect again.
	 *
	 * @param string $userId
	 * @return bool
	 */
	public function hasTokenStayedMissing(string $userId): bool {
		$lostTs = $this->getTimestamp($userId, self::CONFIG_TOKEN_LOST);
		if ($lostTs === 0) {
			$this->setTimestamp($userId, self::CONFIG_TOKEN_LOST, $this->timeFactory->getTime());
			return false;
		}
		return $this->timeFactory->getTime() - $lostTs >= self::TOKEN_GRACE_PERIOD;
	}

	/**
	 * The item ID a ticket is known to ContextChat under.
	 *
	 * ContextChat has a single namespace of item IDs per provider, while a Zammad
	 * ticket ID only means something within the server it lives on. Users can each
	 * point their account at their own Zammad, so without the instance in here
	 * ticket 42 of one server and ticket 42 of another would be one item, whose
	 * access list would end up holding the users of both and whose content would be
	 * whichever of the two was imported last.
	 *
	 * @param string $instance
	 * @param int $ticketId
	 * @return string
	 */
	public static function getItemId(string $instance, int $ticketId): string {
		return $instance . '-' . $ticketId;
	}

	/**
	 * The ticket ID an item ID was built from.
	 *
	 * @param string $itemId
	 * @return string
	 */
	public static function getTicketIdFromItemId(string $itemId): string {
		$separator = strrpos($itemId, '-');
		return $separator === false ? $itemId : substr($itemId, $separator + 1);
	}

	/**
	 * The Zammad instance an item ID was built from.
	 *
	 * @param string $itemId
	 * @return string the instance, or an empty string if the ID does not carry one
	 */
	public static function getInstanceFromItemId(string $itemId): string {
		$separator = strrpos($itemId, '-');
		return $separator === false ? '' : substr($itemId, 0, $separator);
	}

	/**
	 * A short, stable name for the Zammad server a user is connected to.
	 *
	 * Two spellings of the same URL give two instances, which only costs a ticket
	 * being imported as two items. Two servers sharing one instance would leak one
	 * organisation's tickets into the other, so erring towards more instances is
	 * the safe direction here.
	 *
	 * @param string $userId
	 * @return string
	 */
	public function getInstanceId(string $userId): string {
		$url = rtrim(trim($this->zammadAPIService->getZammadUrl($userId)), '/');
		$instance = substr(hash('sha256', $url), 0, 32);
		// A hash cannot be turned back into the URL it was built from, and the link
		// to an imported ticket is built from its item ID alone, outside of any user
		// session and without knowing whose ticket it is, see
		// {@see ContentProvider::getItemUrl()}. So the URL is recorded here, where
		// the instance is still known to belong to it.
		if ($url !== '' && ($this->instanceUrls[$instance] ?? null) !== $url) {
			$this->instanceUrls[$instance] = $url;
			$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_INSTANCE_URL_PREFIX . $instance, $url, lazy: true);
		}
		return $instance;
	}

	/**
	 * The URL of the Zammad server an instance stands for, as it was recorded by
	 * {@see self::getInstanceId()}.
	 *
	 * @param string $instance
	 * @return string the URL, or an empty string if nothing was ever imported from
	 *                that instance by this version
	 */
	public function getInstanceUrl(string $instance): string {
		if ($instance === '') {
			return '';
		}
		return $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_INSTANCE_URL_PREFIX . $instance, '', lazy: true);
	}

	/**
	 * Start over when a user points their account at a different Zammad server.
	 *
	 * The tickets imported from the old server keep neither their IDs nor their
	 * meaning on the new one, so the sweep can never come across them again and the
	 * cleanup would never find them either. They are taken away here instead.
	 *
	 * @param string $userId
	 * @param string $instance
	 * @return void
	 */
	private function resetOnInstanceChange(string $userId, string $instance): void {
		$known = $this->userConfig->getValueString($userId, Application::APP_ID, self::CONFIG_INSTANCE, '', lazy: true);
		if ($known === $instance) {
			return;
		}
		if ($known === '') {
			// nothing has been imported for this connection yet, but a disconnect that
			// failed to take away what an earlier one had imported leaves rows behind.
			// Those of this instance are picked up by the sweep, see
			// self::scheduleForUser(), the ones of another instance never are. This
			// costs one query per connection, the instance is recorded below.
			try {
				$stale = array_diff($this->importedTicketMapper->findInstances($userId), [$instance]);
			} catch (Throwable $e) {
				// recording the instance now would keep us from ever looking again
				$this->logger->warning(
					'Could not determine which Zammad instance was imported for ' . $userId . ': ' . $e->getMessage(),
					['app' => Application::APP_ID, 'userId' => $userId, 'exception' => $e]
				);
				return;
			}
			if ($stale === []) {
				$this->userConfig->setValueString($userId, Application::APP_ID, self::CONFIG_INSTANCE, $instance, lazy: true);
				return;
			}
		}
		$this->logger->info(
			'The Zammad instance of ' . $userId . ' has changed, starting the ContextChat import over.',
			['app' => Application::APP_ID, 'userId' => $userId]
		);
		$this->revokeAllAccess($userId);
		foreach (self::CONFIG_KEYS as $key) {
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, $key);
		}
		$this->userConfig->setValueString($userId, Application::APP_ID, self::CONFIG_INSTANCE, $instance, lazy: true);
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
