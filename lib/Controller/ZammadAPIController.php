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
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;

use OCP\IConfig;
use OCP\IRequest;
use OCP\PreConditionNotMetException;

class ZammadAPIController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private ZammadAPIService $zammadAPIService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get zammad instance URL
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getZammadUrl(): DataResponse {
		$zammadUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url');
		return new DataResponse($zammadUrl);
	}

	/**
	 * Get zammad user avatar
	 *
	 * @param string $imageId
	 * @return DataDisplayResponse
	 * @throws \Exception
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
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
	 * Get notifications list
	 *
	 * @param ?string $since
	 * @return DataResponse
	 * @throws PreConditionNotMetException
	 */
	#[NoAdminRequired]
	public function getNotifications(?string $since = null): DataResponse {
		$hasAccessToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'token') !== '';
		$zammadUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url');
		if (!$hasAccessToken || !preg_match('/^(https?:\/\/)?[^.]+\.[^.].*/', $zammadUrl)) {
			return new DataResponse('', Http::STATUS_BAD_REQUEST);
		}
		$result = $this->zammadAPIService->getNotifications($this->userId, $since, 7);
		$this->importTicketsToContextChat($result);
		if (!isset($result['error'])) {
			$response = new DataResponse($result);
		} else {
			$response = new DataResponse($result, Http::STATUS_UNAUTHORIZED);
		}
		return $response;
	}

	private function importTicketsToContextChat(array $notifications): void {
		if (!Application::$contextChatEnabled) {
			return;
		}
		$contentProvider = \OCP\Server::get('OCA\Zammad\ContextChat\ContentProvider');
		foreach($notifications as $notification) {
			$contentProvider->importTicket($notification['o_id']);
		}
	}

}
