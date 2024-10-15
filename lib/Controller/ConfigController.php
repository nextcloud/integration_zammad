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
use OCA\Zammad\Reference\ZammadReferenceProvider;
use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IL10N;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Zammad\Service\ZammadAPIService;
use OCA\Zammad\AppInfo\Application;
use OCP\PreConditionNotMetException;

class ConfigController extends Controller {
	private IConfig $config;
	private IURLGenerator $urlGenerator;
	private IL10N $l;
	private ZammadAPIService $zammadAPIService;
	private ZammadReferenceProvider $zammadReferenceProvider;
	private ?string $userId;

	public function __construct(string $appName,
								IRequest $request,
								IConfig $config,
								IURLGenerator $urlGenerator,
								IL10N $l,
								ZammadAPIService $zammadAPIService,
								ZammadReferenceProvider $zammadReferenceProvider,
								?string $userId) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->l = $l;
		$this->zammadAPIService = $zammadAPIService;
		$this->zammadReferenceProvider = $zammadReferenceProvider;
		$this->userId = $userId;
	}

	/**
	 * set config values
	 * @NoAdminRequired
	 *
	 * @param array $values
	 * @return DataResponse
	 * @throws PreConditionNotMetException
	 */
	public function setConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, trim($value));
		}
		$result = [];

		if (isset($values['token'])) {
			if ($values['token'] && $values['token'] !== '') {
				$result = $this->storeUserInfo();
			} else {
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_id');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_name');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'last_open_check');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token_type');
				$result = [
					'user_name' => '',
				];
			}
			$this->zammadReferenceProvider->invalidateUserCache($this->userId);
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'refresh_token');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token_expires_at');
		}
		if (isset($result['error'])) {
			return new DataResponse($result, 401);
		} else {
			return new DataResponse($result);
		}
	}

	/**
	 * set admin config values
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}
		return new DataResponse(1);
	}

	/**
	 * receive oauth code and get oauth access token
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $code
	 * @param string $state
	 * @return RedirectResponse
	 * @throws PreConditionNotMetException
	 */
	public function oauthRedirect(string $code = '', string $state = ''): RedirectResponse {
		$configState = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_state');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');

		// anyway, reset state
		$this->config->setUserValue($this->userId, Application::APP_ID, 'oauth_state', '');

		$adminZammadOauthUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');

		if ($clientID && $clientSecret && $configState !== '' && $configState === $state) {
			$redirect_uri = $this->config->getUserValue($this->userId, Application::APP_ID, 'redirect_uri');
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
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $accessToken);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token_type', 'oauth');
				$refreshToken = $result['refresh_token'];
				$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', $refreshToken);
				if (isset($result['expires_in'])) {
					$nowTs = (new Datetime())->getTimestamp();
					$expiresAt = $nowTs + (int) $result['expires_in'];
					$this->config->setUserValue($this->userId, Application::APP_ID, 'token_expires_at', $expiresAt);
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
		$adminZammadOauthUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
		$zammadUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url') ?: $adminZammadOauthUrl;

		if (!$zammadUrl || !preg_match('/^(https?:\/\/)?[^.]+\.[^.].*/', $zammadUrl)) {
			return ['error' => 'Zammad URL is invalid'];
		}

		$info = $this->zammadAPIService->request($this->userId, 'users/me');
		if (isset($info['lastname'], $info['firstname'], $info['id'])) {
			$fullName = $info['firstname'] . ' ' . $info['lastname'];
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $info['id']);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $fullName);
			return ['user_name' => $fullName];
		} else {
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_id');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_name');
			return $info;
		}
	}
}
