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

use OCA\Zammad\AppInfo\Application;
use OCA\Zammad\Service\ZammadAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;

use OCP\IConfig;
use OCP\IRequest;
use OCP\PreConditionNotMetException;

class ZammadAPIController extends Controller {

	/**
	 * @var ZammadAPIService
	 */
	private $zammadAPIService;
	/**
	 * @var string|null
	 */
	private $userId;
	/**
	 * @var string
	 */
	private $accessToken;
	/**
	 * @var string
	 */
	private $tokenType;
	/**
	 * @var string
	 */
	private $refreshToken;
	/**
	 * @var string
	 */
	private $clientID;
	/**
	 * @var string
	 */
	private $clientSecret;
	/**
	 * @var string
	 */
	private $zammadUrl;

	public function __construct(string $appName,
		IRequest $request,
		IConfig $config,
		ZammadAPIService $zammadAPIService,
		?string $userId) {
		parent::__construct($appName, $request);
		$this->zammadAPIService = $zammadAPIService;
		$this->userId = $userId;
		$this->accessToken = $config->getUserValue($userId, Application::APP_ID, 'token');
		$this->tokenType = $config->getUserValue($userId, Application::APP_ID, 'token_type');
		$this->refreshToken = $config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		$this->clientID = $config->getAppValue(Application::APP_ID, 'client_id');
		$this->clientSecret = $config->getAppValue(Application::APP_ID, 'client_secret');
		$this->zammadUrl = $config->getUserValue($userId, Application::APP_ID, 'url');
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
	 * @param string $imageId
	 * @return DataDisplayResponse
	 * @throws \Exception
	 */
	public function getZammadAvatar(string $imageId = ''): DataDisplayResponse {
		$avatarResponse = $this->zammadAPIService->getZammadAvatar($this->userId, $imageId);
		if (isset($avatarResponse['error'])) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
		$response = new DataDisplayResponse(
			$avatarResponse['body'],
			Http::STATUS_OK,
			['Content-Type' => $avatarResponse['headers']['Content-Type'][0] ?? 'image/jpeg']
		);
		$response->cacheFor(60 * 60 * 24);
		return $response;
	}

	/**
	 * get notifications list
	 * @NoAdminRequired
	 *
	 * @param ?string $since
	 * @return DataResponse
	 * @throws PreConditionNotMetException
	 */
	public function getNotifications(?string $since = null): DataResponse {
		if ($this->accessToken === '' || !preg_match('/^(https?:\/\/)?[^.]+\.[^.].*/', $this->zammadUrl)) {
			return new DataResponse('', 400);
		}
		$result = $this->zammadAPIService->getNotifications($this->userId, $since, 7);
		if (!isset($result['error'])) {
			$response = new DataResponse($result);
		} else {
			$response = new DataResponse($result, 401);
		}
		return $response;
	}

}
