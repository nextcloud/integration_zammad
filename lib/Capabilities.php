<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zammad;

use OCA\Zammad\AppInfo\Application;
use OCP\Capabilities\ICapability;

/**
 * Lets API clients such as the Assistant find out that the ticket API exists.
 */
class Capabilities implements ICapability {

	/** Raised when the ticket API changes in a way clients have to know about */
	public const TICKET_API_VERSION = 1;

	/**
	 * @return array{integration_zammad: array{ticket_api: int}}
	 */
	public function getCapabilities(): array {
		return [
			Application::APP_ID => [
				'ticket_api' => self::TICKET_API_VERSION,
			],
		];
	}
}
