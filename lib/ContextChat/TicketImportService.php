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
use OCA\Zammad\Service\ZammadAPIService;
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
 */
class TicketImportService {

	/**
	 * Number of tickets handled per background job run.
	 * Zammad refuses more than 100 per page.
	 */
	public const CHUNK_SIZE = 50;

	/** Next page of the ticket list to fetch */
	private const CONFIG_PAGE = 'cc_sweep_page';
	/** Tickets modified at or before this timestamp are in sync (last completed sweep) */
	private const CONFIG_SINCE = 'cc_sweep_since';
	/** Highest ticket modification timestamp seen during the sweep that is currently running */
	private const CONFIG_MAX = 'cc_sweep_max';
	/** Lowest ticket modification timestamp that failed to import during the current sweep */
	private const CONFIG_FAILED = 'cc_sweep_failed';

	public function __construct(
		private IUserConfig $userConfig,
		private IUserManager $userManager,
		private IJobList $jobList,
		private ZammadAPIService $zammadAPIService,
		private IContentManager $contentManager,
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
		if (!$this->jobList->has(ImportTicketsJob::class, $argument)) {
			$this->jobList->add(ImportTicketsJob::class, $argument);
		}
	}

	/**
	 * @param string $userId
	 * @return void
	 */
	public function unscheduleForUser(string $userId): void {
		$this->jobList->remove(ImportTicketsJob::class, self::jobArgument($userId));
		foreach ([self::CONFIG_PAGE, self::CONFIG_SINCE, self::CONFIG_MAX, self::CONFIG_FAILED] as $key) {
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, $key);
		}
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
	 * Import the next chunk of the user's tickets.
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
		$page = max(1, $this->userConfig->getValueInt($userId, Application::APP_ID, self::CONFIG_PAGE, 1, lazy: true));
		$tickets = $this->zammadAPIService->getTickets($userId, $page, self::CHUNK_SIZE);
		if (isset($tickets['error'])) {
			// leave the sweep state untouched, the same page is retried on the next run
			$this->logger->warning(
				'Zammad API error: could not list tickets for the ContextChat import. ' . $tickets['error'],
				['app' => Application::APP_ID, 'userId' => $userId]
			);
			return;
		}

		$sinceTs = $this->getTimestamp($userId, self::CONFIG_SINCE);
		$maxTs = $this->getTimestamp($userId, self::CONFIG_MAX);
		$failedTs = $this->getTimestamp($userId, self::CONFIG_FAILED);

		foreach ($tickets as $ticket) {
			if (!is_array($ticket) || !isset($ticket['id'], $ticket['title'], $ticket['updated_at'])) {
				continue;
			}
			$ticketTs = $this->parseTimestamp((string)$ticket['updated_at']);
			$maxTs = max($maxTs, $ticketTs);
			if ($ticketTs > 0 && $ticketTs <= $sinceTs) {
				// unchanged since the last completed sweep
				continue;
			}
			try {
				$this->importTicket($userId, $ticket);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Could not import Zammad ticket ' . $ticket['id'] . ' into ContextChat: ' . $e->getMessage(),
					['app' => Application::APP_ID, 'userId' => $userId, 'exception' => $e]
				);
				$failedTs = $failedTs === 0 ? $ticketTs : min($failedTs, $ticketTs);
			}
		}

		if (count($tickets) >= self::CHUNK_SIZE) {
			$this->setTimestamp($userId, self::CONFIG_MAX, $maxTs);
			$this->setTimestamp($userId, self::CONFIG_FAILED, $failedTs);
			$this->userConfig->setValueInt($userId, Application::APP_ID, self::CONFIG_PAGE, $page + 1, lazy: true);
			return;
		}

		// the sweep is done, start over from the first page on the next run.
		// tickets that failed to import must be picked up again, so the watermark
		// never moves past the oldest failure of this sweep.
		$watermark = $failedTs > 0 ? min($maxTs, $failedTs - 1) : $maxTs;
		$this->setTimestamp($userId, self::CONFIG_SINCE, $watermark);
		$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::CONFIG_MAX);
		$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::CONFIG_FAILED);
		$this->userConfig->setValueInt($userId, Application::APP_ID, self::CONFIG_PAGE, 1, lazy: true);
	}

	/**
	 * @param string $userId
	 * @param int $ticketId
	 * @return void
	 * @throws Exception
	 */
	public function importTicketById(string $userId, int $ticketId): void {
		$ticket = $this->zammadAPIService->getTicketInfo($userId, $ticketId);
		if (isset($ticket['error'])) {
			throw new RuntimeException('Could not get ticket information: ' . $ticket['error']);
		}
		if (!isset($ticket['id'], $ticket['title'], $ticket['updated_at'])) {
			throw new RuntimeException('Unexpected ticket information for ticket ' . $ticketId);
		}
		$this->importTicket($userId, $ticket);
	}

	/**
	 * @param string $userId
	 * @param array $ticket a ticket as returned by the Zammad API
	 * @return void
	 * @throws Exception
	 */
	public function importTicket(string $userId, array $ticket): void {
		$itemId = (string)$ticket['id'];
		$item = new ContentItem(
			$itemId,
			ContentProvider::ID,
			(string)$ticket['title'],
			$this->getTicketContent($userId, (int)$ticket['id']),
			'Ticket',
			$this->parseDateTime((string)$ticket['updated_at']),
			[$userId],
		);
		$this->contentManager->submitContent(Application::APP_ID, [$item]);
		// a ticket can be visible to several Nextcloud users and the item ID is the
		// same for all of them, so grant access additively instead of replacing it
		$this->contentManager->updateAccess(
			Application::APP_ID, ContentProvider::ID, $itemId, UpdateAccessOp::ALLOW, [$userId]
		);
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
