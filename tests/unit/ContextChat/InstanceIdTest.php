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

/**
 * The Context Agent's Zammad tools compute the same instance ID to find a
 * server's tickets in Context Chat results (_instance_id() in
 * context_agent/ex_app/lib/all_tools/zammad.py). Both test suites pin the same
 * value, so a change on one side fails a test.
 */
class InstanceIdTest extends TestCase {

	public function testInstanceIdIsPinned(): void {
		$api = $this->createMock(ZammadAPIService::class);
		$api->method('getZammadUrl')->willReturn(' https://zammad.example.com/ ');
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

		$this->assertSame('a24e71eb240131d42d05a36f8a0b32f5', $service->getInstanceId('alice'));
	}
}
