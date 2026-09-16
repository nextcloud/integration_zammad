<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Zammad\ContextChat;

use OCA\Zammad\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\ContextChat\Events\ContentProviderRegisterEvent;
use OCP\ContextChat\IContentProvider;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;

/**
 * @template-implements IEventListener<Event>
 */
class ContentProvider implements IContentProvider, IEventListener {

	/**
	 * Provider IDs must not contain colons, double underscores or spaces
	 */
	public const ID = 'tickets';

	public function __construct(
		private IAppConfig $appConfig,
		private IUserConfig $userConfig,
		private TicketImportService $importService,
		private ?string $userId,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof ContentProviderRegisterEvent) {
			return;
		}
		$event->registerContentProvider(Application::APP_ID, self::ID, self::class);
	}

	/**
	 * The ID of the provider
	 *
	 * @return string
	 * @since 32.0.0
	 */
	public function getId(): string {
		return self::ID;
	}

	/**
	 * The ID of the app making the provider avaialble
	 *
	 * @return string
	 * @since 32.0.0
	 */
	public function getAppId(): string {
		return Application::APP_ID;
	}

	/**
	 * The absolute URL to the content item
	 *
	 * @param string $id
	 * @return string
	 * @since 32.0.0
	 */
	public function getItemUrl(string $id): string {
		// this is called outside of a user session as well,
		// the admin configured instance is the only thing available then
		$zammadUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
		if ($this->userId !== null) {
			$zammadUrl = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'url') ?: $zammadUrl;
		}
		// item IDs carry the Zammad instance the ticket was imported from, see
		// TicketImportService::getItemId()
		return $zammadUrl . '/#ticket/zoom/' . TicketImportService::getTicketIdFromItemId($id);
	}

	/**
	 * Starts the initial import of content items into content chat
	 *
	 * @return void
	 * @since 32.0.0
	 */
	public function triggerInitialImport(): void {
		$this->importService->scheduleForAllUsers();
	}
}
