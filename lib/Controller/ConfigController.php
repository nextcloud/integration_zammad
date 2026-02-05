<?php

/**
 * Nextcloud - zammad
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Zammad\Controller;

use DateTime;
use OCA\Zammad\AppInfo\Application;
use OCA\Zammad\Reference\ZammadReferenceProvider;
use OCA\Zammad\Service\ZammadAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;

use OCP\IURLGenerator;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;

class ConfigController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserConfig $userConfig,
		private IAppConfig $appConfig,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
		private ICrypto $crypto,
		private ZammadAPIService $zammadAPIService,
		private ZammadReferenceProvider $zammadReferenceProvider,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Set config values
	 *
	 * @param array $values
	 * @return DataResponse
	 * @throws PreConditionNotMetException
	 */
	#[NoAdminRequired]
	public function setConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if (in_array($key, ['token', 'token_type', 'url', 'oauth_state', 'redirect_uri'], true)) {
				return new DataResponse([], Http::STATUS_BAD_REQUEST);
			}
			$lazy = $key !== 'url';
			$this->userConfig->setValueString($this->userId, Application::APP_ID, $key, trim($value), lazy: $lazy);
		}

		return new DataResponse([]);
	}

	/**
	 * Set sensitive config values
	 *
	 * @param array $values
	 * @return DataResponse
	 * @throws PreConditionNotMetException
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	public function setSensitiveConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if ($key === 'token' && $value !== '') {
				$this->userConfig->setValueString($this->userId, Application::APP_ID, $key, $value, lazy: true, flags: IUserConfig::FLAG_SENSITIVE);
			} else {
				$this->userConfig->setValueString($this->userId, Application::APP_ID, $key, trim($value), lazy: true);
			}
		}
		$result = [];

		if (isset($values['token'])) {
			if ($values['token'] && $values['token'] !== '') {
				$result = $this->storeUserInfo();
			} else {
				$this->userConfig->deleteUserConfig($this->userId, Application::APP_ID, 'user_id');
				$this->userConfig->deleteUserConfig($this->userId, Application::APP_ID, 'user_name');
				$this->userConfig->deleteUserConfig($this->userId, Application::APP_ID, 'last_open_check');
				$this->userConfig->deleteUserConfig($this->userId, Application::APP_ID, 'token_type');
				$result = [
					'user_name' => '',
				];
			}
			$this->zammadReferenceProvider->invalidateUserCache($this->userId);
			$this->userConfig->deleteUserConfig($this->userId, Application::APP_ID, 'refresh_token');
			$this->userConfig->deleteUserConfig($this->userId, Application::APP_ID, 'token_expires_at');
		}
		if (isset($result['error'])) {
			return new DataResponse($result, Http::STATUS_UNAUTHORIZED);
		} else {
			return new DataResponse($result);
		}
	}

	/**
	 * Set admin config values
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if (in_array($key, ['client_id', 'client_secret', 'oauth_instance_url'], true)) {
				return new DataResponse([], Http::STATUS_BAD_REQUEST);
			}
			$lazy = $key !== 'oauth_instance_url';
			$this->appConfig->setValueString(Application::APP_ID, $key, $value, lazy: $lazy);
		}
		return new DataResponse([]);
	}

	/**
	 * Set sensitive admin config values
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	#[PasswordConfirmationRequired]
	public function setSensitiveAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if (in_array($key, ['client_id', 'client_secret'], true) && $value !== '') {
				$this->appConfig->setValueString(Application::APP_ID, $key, $value, lazy: true, sensitive: true);
			} else {
				$this->appConfig->setValueString(Application::APP_ID, $key, $value, lazy: true);
			}
		}
		return new DataResponse([]);
	}

	/**
	 * Receive oauth code and get oauth access token
	 *
	 * @param string $code
	 * @param string $state
	 * @return RedirectResponse
	 * @throws PreConditionNotMetException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function oauthRedirect(string $code = '', string $state = ''): RedirectResponse {
		$configState = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'oauth_state', lazy: true);
		$clientID = $this->appConfig->getValueString(Application::APP_ID, 'client_id', lazy: true);
		$clientSecret = $this->appConfig->getValueString(Application::APP_ID, 'client_secret', lazy: true);

		// anyway, reset state
		$this->userConfig->setValueString($this->userId, Application::APP_ID, 'oauth_state', '', lazy: true);

		$adminZammadOauthUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');

		if ($clientID && $clientSecret && $configState !== '' && $configState === $state) {
			$redirect_uri = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'redirect_uri', lazy: true);
			$result = $this->zammadAPIService->requestOAuthAccessToken($adminZammadOauthUrl, [
				'client_id' => $clientID,
				'client_secret' => $clientSecret,
				'code' => $code,
				'redirect_uri' => $redirect_uri,
				'grant_type' => 'authorization_code'
			], 'POST');
			if (isset($result['access_token'])) {
				$this->zammadReferenceProvider->invalidateUserCache($this->userId);
				$accessToken = $result['access_token'];
				$this->userConfig->setValueString($this->userId, Application::APP_ID, 'token', $accessToken, lazy: true, flags: IUserConfig::FLAG_SENSITIVE);
				$this->userConfig->setValueString($this->userId, Application::APP_ID, 'token_type', 'oauth', lazy: true);
				$refreshToken = $result['refresh_token'];
				$this->userConfig->setValueString($this->userId, Application::APP_ID, 'refresh_token', $refreshToken, lazy: true, flags: IUserConfig::FLAG_SENSITIVE);
				if (isset($result['expires_in'])) {
					$nowTs = (new Datetime())->getTimestamp();
					$expiresAt = $nowTs + (int)$result['expires_in'];
					$this->userConfig->setValueString($this->userId, Application::APP_ID, 'token_expires_at', (string)$expiresAt, lazy: true);
				}
				// get user info
				$this->storeUserInfo();
				return new RedirectResponse(
					$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']) .
					'?zammadToken=success'
				);
			}
			$result = $this->l->t('Error getting OAuth access token.') . ' ' . $result['error'];
		} else {
			$result = $this->l->t('Error during OAuth exchanges');
		}
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']) .
			'?zammadToken=error&message=' . urlencode($result)
		);
	}

	/**
	 * @return array
	 * @throws PreConditionNotMetException
	 */
	private function storeUserInfo(): array {
		$adminZammadOauthUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
		$zammadUrl = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'url') ?: $adminZammadOauthUrl;

		if (!$zammadUrl || !preg_match('/^(https?:\/\/)?[^.]+\.[^.].*/', $zammadUrl)) {
			return ['error' => 'Zammad URL is invalid'];
		}

		$info = $this->zammadAPIService->request($this->userId, 'users/me');
		if (isset($info['lastname'], $info['firstname'], $info['id'])) {
			$fullName = $info['firstname'] . ' ' . $info['lastname'];
			$this->userConfig->setValueString($this->userId, Application::APP_ID, 'user_id', $info['id'], lazy: true);
			$this->userConfig->setValueString($this->userId, Application::APP_ID, 'user_name', $fullName, lazy: true);
			return ['user_name' => $fullName];
		} else {
			$this->userConfig->deleteUserConfig($this->userId, Application::APP_ID, 'user_id');
			$this->userConfig->deleteUserConfig($this->userId, Application::APP_ID, 'user_name');
			return $info;
		}
	}
}
