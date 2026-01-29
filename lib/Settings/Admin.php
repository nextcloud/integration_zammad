<?php

namespace OCA\Zammad\Settings;

use OCA\Zammad\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;

use OCP\Settings\ISettings;

class Admin implements ISettings {

	public function __construct(
		private IAppConfig $appConfig,
		private IInitialState $initialStateService,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$clientID = $this->appConfig->getValueString(Application::APP_ID, 'client_id', lazy: true);
		$clientSecret = $this->appConfig->getValueString(Application::APP_ID, 'client_secret', lazy: true);

		$oauthUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
		$adminLinkPreviewEnabled = $this->appConfig->getValueString(Application::APP_ID, 'link_preview_enabled', '1', lazy: true) === '1';

		$adminConfig = [
			'client_id' => $clientID,
			// don't expose the client secret to the user
			'client_secret' => $clientSecret === '' ? '' : 'dummySecret',
			'oauth_instance_url' => $oauthUrl,
			'link_preview_enabled' => $adminLinkPreviewEnabled,
		];
		$this->initialStateService->provideInitialState('admin-config', $adminConfig);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
