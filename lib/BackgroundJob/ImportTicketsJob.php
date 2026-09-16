<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Zammad\BackgroundJob;

use OCA\Zammad\AppInfo\Application;
use OCA\Zammad\ContextChat\TicketImportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports one chunk of a single user's Zammad tickets into ContextChat.
 *
 * One job is scheduled per connected user. It keeps running indefinitely: once it
 * has walked through all tickets of that user it starts over, so that ticket
 * changes keep being picked up. It only removes itself when the user is gone or
 * has disconnected their Zammad account.
 */
class ImportTicketsJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private IUserManager $userManager,
		private IJobList $jobList,
		private TicketImportService $importService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		// every 5 minutes
		$this->setInterval(60 * 5);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		if (!is_array($argument) || !isset($argument['user_id']) || !is_string($argument['user_id'])) {
			$this->logger->warning('Zammad ticket import job started without a user, removing it.', ['app' => Application::APP_ID]);
			$this->jobList->remove(self::class, $argument);
			return;
		}
		$userId = $argument['user_id'];

		if (!$this->importService->isAvailable()) {
			// context_chat is not installed, keep the job around for when it is
			return;
		}

		if ($this->userManager->get($userId) === null) {
			$this->logger->debug('Nextcloud user ' . $userId . ' is gone, unscheduling the Zammad ticket import.', ['app' => Application::APP_ID]);
			$this->importService->unscheduleForUser($userId);
			return;
		}

		if (!$this->importService->hasToken($userId)) {
			// not necessarily a disconnect, see TicketImportService::hasTokenStayedMissing()
			if ($this->importService->hasTokenStayedMissing($userId)) {
				$this->logger->info(
					'The Zammad token of ' . $userId . ' has stayed gone, unscheduling the ticket import.',
					['app' => Application::APP_ID]
				);
				$this->importService->unscheduleForUser($userId);
			}
			return;
		}

		try {
			$this->importService->importChunk($userId);
		} catch (Throwable $e) {
			// keep the job scheduled, the chunk is retried on the next run
			$this->logger->warning('Zammad ticket import failed: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'userId' => $userId,
				'exception' => $e,
			]);
		}
	}
}
