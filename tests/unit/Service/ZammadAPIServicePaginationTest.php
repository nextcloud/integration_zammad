<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zammad\Tests\Service;

use OCA\Zammad\Service\ZammadAPIService;
use PHPUnit\Framework\TestCase;

/**
 * How offsets map onto the pages of Zammad's API.
 */
class ZammadAPIServicePaginationTest extends TestCase {

	public function testOffsetOnPageBoundary(): void {
		$this->assertSame([5, 1, 0], ZammadAPIService::getZammadPaginationValues(0, 5));
		$this->assertSame([5, 3, 0], ZammadAPIService::getZammadPaginationValues(10, 5));
	}

	public function testOffsetBetweenPages(): void {
		// items 3 to 7 fit on the first page of 8, after 3 items of padding
		$this->assertSame([8, 1, 3], ZammadAPIService::getZammadPaginationValues(3, 5));
	}
}
