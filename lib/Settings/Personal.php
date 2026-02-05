<?php

namespace OCA\Zammad\Settings;

use OCA\Zammad\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;

use OCP\Security\ICrypto;
use OCP\Settings\ISettings;

class Personal implements ISettings {

	public function __construct(
		private IUserConfig $userConfig,
		private IAppConfig $appConfig,
		private IInitialState $initialStateService,
		private ICrypto $crypto,
		private ?string $userId,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		// for OAuth
		$clientID = $this->appConfig->getValueString(Application::APP_ID, 'client_id', lazy: true);
		$clientSecret = $this->appConfig->getValueString(Application::APP_ID, 'client_secret', lazy: true);
		$adminOauthUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');

		$token = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'token', lazy: true);
		$userName = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'user_name', lazy: true);
		$url = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'url') ?: $adminOauthUrl;
		$searchEnabled = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'search_enabled', '0', lazy: true) === '1';
		$notificationEnabled = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'notification_enabled', '0', lazy: true) === '1';
		$navigationEnabled = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'navigation_enabled', '0') === '1';
		$linkPreviewEnabled = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'link_preview_enabled', '1', lazy: true) === '1';

		$userConfig = [
			// don't expose the token to the user
			'token' => $token === '' ? '' : 'dummyToken',
			'url' => $url,
			'client_id' => $clientID,
			// don't expose the client secret to the user
			'client_secret' => $clientSecret !== '',
			'oauth_instance_url' => $adminOauthUrl,
			'user_name' => $userName,
			'search_enabled' => $searchEnabled,
			'notification_enabled' => $notificationEnabled,
			'navigation_enabled' => $navigationEnabled,
			'link_preview_enabled' => $linkPreviewEnabled,
		];
		$this->initialStateService->provideInitialState('user-config', $userConfig);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
