<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Zammad\Migration;

use Closure;
use OCA\Zammad\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

class Version040000Date20260129225956 extends SimpleMigrationStep {

	public function __construct(
		private ICrypto $crypto,
		private IUserConfig $userConfig,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		foreach ($this->userConfig->getUserIds(Application::APP_ID) as $userId) {
			// store user config as lazy and sensitive
			foreach (['token', 'refresh_token'] as $key) {
				if ($this->userConfig->hasKey($userId, Application::APP_ID, $key)) {
					$value = $this->userConfig->getValueString($userId, Application::APP_ID, $key);
					$decryptedValue = $this->crypto->decrypt($value);
					$this->userConfig->setValueString($userId, Application::APP_ID, $key, $decryptedValue, lazy: true, flags: IUserConfig::FLAG_SENSITIVE);
				}
			}
			// store user config as lazy (except 'navigation_enabled' and 'url')
			foreach (['token_type', 'token_expires_at', 'oauth_state', 'redirect_uri', 'search_enabled', 'link_preview_enabled', 'notification_enabled', 'user_id', 'user_name', 'last_open_check'] as $key) {
				if ($this->userConfig->hasKey($userId, Application::APP_ID, $key)) {
					$value = $this->userConfig->getValueString($userId, Application::APP_ID, $key);
					$this->userConfig->setValueString($userId, Application::APP_ID, $key, $value, lazy: true);
				}
			}
		}

		// store oauth_instance_url as non-lazy
		foreach (['oauth_instance_url'] as $key) {
			if ($this->appConfig->hasKey(Application::APP_ID, $key, lazy: true)) {
				$this->appConfig->deleteKey(Application::APP_ID, $key);
				$this->appConfig->setValueString(Application::APP_ID, $key, $value, lazy: false);
			}
		}
	}
}
