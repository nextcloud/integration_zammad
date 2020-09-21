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

use OCP\App\IAppManager;
use OCP\Files\IAppData;
use OCP\AppFramework\Http\DataDisplayResponse;

use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IServerContainer;
use OCP\IL10N;
use OCP\ILogger;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;

use OCP\AppFramework\Http\ContentSecurityPolicy;

use OCP\IRequest;
use OCP\IDBConnection;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCP\Http\Client\IClientService;

use OCA\Zammad\Service\ZammadAPIService;
use OCA\Zammad\AppInfo\Application;

class ConfigController extends Controller {


	private $userId;
	private $config;
	private $dbconnection;
	private $dbtype;

	public function __construct($AppName,
								IRequest $request,
								IServerContainer $serverContainer,
								IConfig $config,
								IAppManager $appManager,
								IAppData $appData,
								IDBConnection $dbconnection,
								IURLGenerator $urlGenerator,
								IL10N $l,
								ILogger $logger,
								IClientService $clientService,
								ZammadAPIService $zammadAPIService,
								$userId) {
		parent::__construct($AppName, $request);
		$this->l = $l;
		$this->userId = $userId;
		$this->appData = $appData;
		$this->serverContainer = $serverContainer;
		$this->config = $config;
		$this->dbconnection = $dbconnection;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
		$this->clientService = $clientService;
		$this->zammadAPIService = $zammadAPIService;
	}

	/**
	 * set config values
	 * @NoAdminRequired
	 */
	public function setConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
		}
		$result = [];

		if (isset($values['token'])) {
			if ($values['token'] && $values['token'] !== '') {
				$userName = $this->storeUserInfo($values['token']);
				$result['user_name'] = $userName;
			} else {
				$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', '');
				$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', '');
				$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', '');
				$this->config->setUserValue($this->userId, Application::APP_ID, 'last_open_check', '');
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token_type', '');
				$result['user_name'] = '';
			}
		}
		return new DataResponse($result);
	}

	/**
	 * set admin config values
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}
		$response = new DataResponse(1);
		return $response;
	}

	/**
	 * receive oauth code and get oauth access token
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function oauthRedirect(string $code, string $state): RedirectResponse {
		$configState = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_state', '');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');

		// anyway, reset state
		$this->config->setUserValue($this->userId, Application::APP_ID, 'oauth_state', '');

		if ($clientID && $clientSecret && $configState !== '' && $configState === $state) {
			$redirect_uri = $this->urlGenerator->linkToRouteAbsolute('integration_zammad.config.oauthRedirect');
			$zammadUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', '');
			$result = $this->zammadAPIService->requestOAuthAccessToken($zammadUrl, [
				'client_id' => $clientID,
				'client_secret' => $clientSecret,
				'code' => $code,
				'redirect_uri' => $redirect_uri,
				'grant_type' => 'authorization_code'
			], 'POST');
			if (isset($result['access_token'])) {
				$accessToken = $result['access_token'];
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $accessToken);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token_type', 'oauth');
				$refreshToken = $result['refresh_token'];
				$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', $refreshToken);
				// get user info
				$this->storeUserInfo($accessToken);
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

	private function storeUserInfo(string $accessToken): string {
		$tokenType = $this->config->getUserValue($this->userId, Application::APP_ID, 'token_type', '');
		$refreshToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'refresh_token', '');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');
		$zammadUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', '');

		$info = $this->zammadAPIService->request($zammadUrl, $accessToken, $tokenType, $refreshToken, $clientID, $clientSecret, $this->userId, 'users/me');
		if (isset($info['lastname']) && isset($info['firstname']) && isset($info['id'])) {
			$fullName = $info['firstname'] . ' ' . $info['lastname'];
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $info['id']);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $fullName);
			return $fullName;
		} else {
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', '');
			return '';
		}
	}
}
