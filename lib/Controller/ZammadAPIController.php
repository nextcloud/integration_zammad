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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;

use OCP\AppFramework\Http\ContentSecurityPolicy;

use OCP\ILogger;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Zammad\Service\ZammadAPIService;
use OCA\Zammad\AppInfo\Application;

class ZammadAPIController extends Controller {


	private $userId;
	private $config;
	private $dbconnection;
	private $dbtype;

	public function __construct($AppName,
								IRequest $request,
								IServerContainer $serverContainer,
								IConfig $config,
								IL10N $l10n,
								IAppManager $appManager,
								IAppData $appData,
								ILogger $logger,
								ZammadAPIService $zammadAPIService,
								$userId) {
		parent::__construct($AppName, $request);
		$this->userId = $userId;
		$this->l10n = $l10n;
		$this->appData = $appData;
		$this->serverContainer = $serverContainer;
		$this->config = $config;
		$this->logger = $logger;
		$this->zammadAPIService = $zammadAPIService;
		$this->accessToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'token', '');
		$this->tokenType = $this->config->getUserValue($this->userId, Application::APP_ID, 'token_type', '');
		$this->refreshToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'refresh_token', '');
		$this->clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
		$this->clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');
		$this->zammadUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', '');
	}

	/**
	 * get zammad instance URL
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getZammadUrl(): DataResponse {
		return new DataResponse($this->zammadUrl);
	}

	/**
	 * get zammad user avatar
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $image
	 * @return DataDisplayResponse
	 */
	public function getZammadAvatar(string $image = ''): DataDisplayResponse {
		$response = new DataDisplayResponse(
			$this->zammadAPIService->getZammadAvatar(
				$this->zammadUrl, $this->accessToken, $this->tokenType, $this->refreshToken, $this->clientID, $this->clientSecret, $image
			)
		);
		$response->cacheFor(60*60*24);
		return $response;
	}

	/**
	 * get notifications list
	 * @NoAdminRequired
	 *
	 * @param ?string $since
	 * @return DataResponse
	 */
	public function getNotifications(?string $since = null): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse('', 400);
		}
		$result = $this->zammadAPIService->getNotifications(
			$this->zammadUrl, $this->accessToken, $this->tokenType, $this->refreshToken, $this->clientID, $this->clientSecret, $this->userId, $since, 7
		);
		if (!isset($result['error'])) {
			$response = new DataResponse($result);
		} else {
			$response = new DataResponse($result, 401);
		}
		return $response;
	}

}
