<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zammad\Tests\ContextChat;

use OCA\Zammad\ContextChat\TicketImportService;
use OCA\Zammad\Db\ImportedTicketMapper;
use OCA\Zammad\Service\ZammadAPIService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\ContextChat\IContentManager;
use OCP\IAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TicketImportServiceTest extends TestCase {

	public function testTicketContentLeavesOutMailQuotesAndInternalNotes(): void {
		$api = $this->createMock(ZammadAPIService::class);
		$api->method('getArticlesByTicket')->willReturn([
			['from' => 'Jane Doe', 'type' => 'web', 'internal' => false, 'content_type' => 'text/plain', 'body' => "Sync fails.\n> log line"],
			['from' => 'Bob Agent', 'type' => 'note', 'internal' => true, 'content_type' => 'text/plain', 'body' => 'Internal: proxy?'],
			['from' => 'Jane Doe', 'type' => 'email', 'internal' => false, 'content_type' => 'text/html',
				'body' => '<p>Still failing.</p><span class="js-signatureMarker"></span><p>Jane</p><blockquote><p>Sync fails.</p></blockquote>'],
		]);
		$service = new TicketImportService(
			$this->createMock(IAppConfig::class),
			$this->createMock(IUserConfig::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IJobList::class),
			$api,
			$this->createMock(IContentManager::class),
			$this->createMock(ImportedTicketMapper::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
		);

		// the web form article is kept whole, the mail loses its signature and quote, the internal note stays out
		$this->assertSame("Jane Doe:\n\nSync fails.\n> log line\n\nJane Doe:\n\nStill failing.\n\n", $service->getTicketContent('alice', 42));
	}
}
