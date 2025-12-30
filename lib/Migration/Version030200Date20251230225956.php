<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Zammad\Migration;

use Closure;
use OCA\Zammad\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

class Version030200Date20251230225956 extends SimpleMigrationStep {

	public function __construct(
		private ICrypto $crypto,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		// store client_id and client_secret again as lazy and sensitive
		foreach (['client_id', 'client_secret'] as $key) {
			$value = $this->appConfig->getValueString(Application::APP_ID, $key);
			if ($value !== '') {
				$decryptedValue = $this->crypto->decrypt($value);
				$this->appConfig->setValueString(Application::APP_ID, $key, $decryptedValue, lazy: true, sensitive: true);
			}
		}

		foreach (['oauth_instance_url', 'link_preview_enabled'] as $key) {
			$value = $this->appConfig->getValueString(Application::APP_ID, $key);
			if ($value !== '') {
				$this->appConfig->setValueString(Application::APP_ID, $key, $value, lazy: true);
			}
		}
	}
}
