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
use OCA\Zammad\ContextChat\TicketImportService;
use OCA\Zammad\Service\ZammadAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use OCP\PreConditionNotMetException;
use Psr\Log\LoggerInterface;
use Throwable;

class ZammadAPIController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserConfig $userConfig,
		private ZammadAPIService $zammadAPIService,
		private TicketImportService $importService,
		private LoggerInterface $logger,
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
		$zammadUrl = $this->zammadAPIService->getZammadUrl($this->userId);
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
		$hasAccessToken = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'token', lazy: true) !== '';
		$zammadUrl = $this->zammadAPIService->getZammadUrl($this->userId);
		if (!$hasAccessToken || !preg_match('/^(https?:\/\/)?[^.]+\.[^.].*/', $zammadUrl)) {
			return new DataResponse('connection_impossible', Http::STATUS_BAD_REQUEST);
		}
		$result = $this->zammadAPIService->getNotifications($this->userId, $since, 7);
		if (!isset($result['error'])) {
			$this->importTicketsToContextChat($result);
			$response = new DataResponse($result);
		} else {
			$response = new DataResponse($result, Http::STATUS_UNAUTHORIZED);
		}
		return $response;
	}

	/**
	 * Push the tickets the user was just notified about to ContextChat.
	 * The background job imports them as well, this only makes them available sooner.
	 *
	 * @param array $notifications a successful result of ZammadAPIService::getNotifications()
	 * @return void
	 */
	private function importTicketsToContextChat(array $notifications): void {
		if ($this->userId === null) {
			return;
		}
		if (!$this->importService->isAvailable()) {
			return;
		}
		foreach ($notifications as $notification) {
			if (!isset($notification['o_id'])) {
				continue;
			}
			try {
				$this->importService->importTicketById($this->userId, (int)$notification['o_id']);
			} catch (Throwable $e) {
				// never let the ContextChat import break the dashboard widget
				$this->logger->warning('Could not import Zammad ticket ' . $notification['o_id'] . ' into ContextChat: ' . $e->getMessage(), [
					'app' => Application::APP_ID,
					'exception' => $e,
				]);
			}
		}
	}

}
