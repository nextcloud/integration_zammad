<?php

namespace OCA\Zammad\ContextChat;

use OCA\ContextChat\Event\ContentProviderRegisterEvent;
use OCA\ContextChat\Public\ContentItem;
use OCA\ContextChat\Public\ContentManager;
use OCA\ContextChat\Public\IContentProvider;
use OCA\ContextChat\Public\UpdateAccessOp;
use OCA\Zammad\AppInfo\Application;
use OCA\Zammad\Service\ZammadAPIService;
use OCP\EventDispatcher\Event;
use OCP\IConfig;

class ContentProvider implements IContentProvider {

	public function __construct(
		private IConfig $config,
		private ZammadAPIService $zammadAPIService,
		private ?string $userId,
		private ContentManager $contentManager,
	) {

	}

	public const ID = 'integration_zammad:tickets';

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
	 * @since 1.1.0
	 */
	public function getId(): string {
		return self::ID;
	}

	/**
	 * The ID of the app making the provider avaialble
	 *
	 * @return string
	 * @since 1.1.0
	 */
	public function getAppId(): string {
		return Application::APP_ID;
	}

	/**
	 * The absolute URL to the content item
	 *
	 * @param string $id
	 * @return string
	 * @since 1.1.0
	 */
	public function getItemUrl(string $id): string {
		$adminZammadOauthUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
		$zammadUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url') ?: $adminZammadOauthUrl;
		return $zammadUrl . '/#ticket/zoom/' . $id;
	}

	/**
	 * Starts the initial import of content items into content chat
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function triggerInitialImport(): void {
	}

	public function importTicket($id) {
		$ticketInfo = $this->zammadAPIService->getTicketInfo($this->userId, (int)$id);
		$item = new ContentItem(
			(string)$id,
			$this->getId(),
			$ticketInfo['title'],
			$this->getContentOfTicket($id),
			'Ticket',
			new \DateTime($ticketInfo['updated_at']),
			[$this->userId]
		);
		$this->contentManager->updateAccess(Application::APP_ID, self::ID, $id, UpdateAccessOp::ALLOW, [$this->userId]);
		$this->contentManager->updateAccessProvider(Application::APP_ID, self::ID, UpdateAccessOp::ALLOW, [$this->userId]);
		$this->contentManager->submitContent(Application::APP_ID, [$item]);
	}

	public function getContentOfTicket($id): string {
		return array_reduce($this->zammadAPIService->getArticlesByTicket($this->userId, (int)$id), fn ($agg, array $article) => $agg . $article['from'] . ":\n\n" . $article['body'] . "\n\n", '');
	}

}
