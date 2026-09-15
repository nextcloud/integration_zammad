<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Zammad\ContextChat;

use OCA\Zammad\AppInfo\Application;
use OCP\ContextChat\Events\ContentProviderRegisterEvent;
use OCP\ContextChat\IContentProvider;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;

/**
 * @template-implements IEventListener<Event>
 */
class ContentProvider implements IContentProvider, IEventListener {

	/**
	 * Provider IDs must not contain colons, double underscores or spaces
	 */
	public const ID = 'tickets';

	public function __construct(
		private IConfig $config,
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
		$zammadUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
		if ($this->userId !== null) {
			$zammadUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url') ?: $zammadUrl;
		}
		return $zammadUrl . '/#ticket/zoom/' . $id;
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
