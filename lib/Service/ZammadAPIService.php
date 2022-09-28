<?php
/**
 * Nextcloud - zammad
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Zammad\Service;

use DateTime;
use Exception;
use OCP\Http\Client\IClient;
use OCP\IL10N;
use OCP\PreConditionNotMetException;
use Psr\Log\LoggerInterface;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUser;
use OCP\Http\Client\IClientService;
use OCP\Notification\IManager as INotificationManager;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ConnectException;

use OCA\Zammad\AppInfo\Application;

class ZammadAPIService {
	private IUserManager $userManager;
	private LoggerInterface $logger;
	private IL10N $l10n;
	private IConfig $config;
	private INotificationManager $notificationManager;
	private IClient $client;

	/**
	 * Service to make requests to Zammad v3 (JSON) API
	 */
	public function __construct (string $appName,
								IUserManager $userManager,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								INotificationManager $notificationManager,
								IClientService $clientService) {
		$this->userManager = $userManager;
		$this->logger = $logger;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->notificationManager = $notificationManager;
		$this->client = $clientService->newClient();
	}

	/**
	 * triggered by a cron job
	 * notifies user of their number of new tickets
	 *
	 * @return void
	 */
	public function checkOpenTickets(): void {
		$this->userManager->callForAllUsers(function (IUser $user) {
			$this->checkOpenTicketsForUser($user->getUID());
		});
	}

	/**
	 * @param string $userId
	 * @return void
	 * @throws PreConditionNotMetException
	 */
	private function checkOpenTicketsForUser(string $userId): void {
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$notificationEnabled = ($this->config->getUserValue($userId, Application::APP_ID, 'notification_enabled', '0') === '1');
		if ($accessToken && $notificationEnabled) {
			$token = $this->config->getUserValue($userId, Application::APP_ID, 'token');
			$zammadUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url');
			if ($token && $zammadUrl) {
				$lastNotificationCheck = $this->config->getUserValue($userId, Application::APP_ID, 'last_open_check');
				$lastNotificationCheck = $lastNotificationCheck === '' ? null : $lastNotificationCheck;
				// get the zammad user ID
				$me = $this->request($userId, 'users/me');
				if (isset($me['id'])) {
					$my_user_id = $me['id'];

					$notifications = $this->getNotifications($userId, $lastNotificationCheck);
					if (!isset($notifications['error']) && count($notifications) > 0) {
						$lastNotificationCheck = $notifications[0]['updated_at'];
						$this->config->setUserValue($userId, Application::APP_ID, 'last_open_check', $lastNotificationCheck);
						$nbOpen = 0;
						foreach ($notifications as $n) {
//							$user_id = $n['user_id'];
							$state_id = $n['state_id'];
							$owner_id = $n['owner_id'];
							// if ($state_id === 1) {
							if ($owner_id === $my_user_id && $state_id === 1) {
								$nbOpen++;
							}
						}
						if ($nbOpen > 0) {
							$this->sendNCNotification($userId, 'new_open_tickets', [
								'nbOpen' => $nbOpen,
								'link' => $zammadUrl
							]);
						}
					}
				}
			}
		}
	}

	/**
	 * @param string $userId
	 * @param string $subject
	 * @param array $params
	 * @return void
	 */
	private function sendNCNotification(string $userId, string $subject, array $params): void {
		$manager = $this->notificationManager;
		$notification = $manager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject('dum', 'dum')
			->setSubject($subject, $params);

		$manager->notify($notification);
	}

	/**
	 * @param string $userId
	 * @param ?string $since
	 * @param ?int $limit
	 * @return array
	 * @throws PreConditionNotMetException|Exception
	 */
	public function getNotifications(string $userId, ?string $since = null, ?int $limit = null): array {
		$params = [
			'state' => 'pending',
		];
		$result = $this->request($userId, 'online_notifications', $params);
		if (isset($result['error'])) {
			return $result;
		}
		// filter seen ones
		$result = array_filter($result, function($elem) {
			return !$elem['seen'];
		});
		// filter results by date
		if (!is_null($since)) {
			$sinceDate = new DateTime($since);
			$sinceTimestamp = $sinceDate->getTimestamp();
			$result = array_filter($result, function($elem) use ($sinceTimestamp) {
				$date = new Datetime($elem['updated_at']);
				$ts = $date->getTimestamp();
				return $ts > $sinceTimestamp;
			});
		}
		if ($limit) {
			$result = array_slice($result, 0, $limit);
		}
		$result = array_values($result);
		// get details
		foreach ($result as $k => $v) {
			$details = $this->request($userId, 'tickets/' . $v['o_id']);
			if (!isset($details['error'])) {
				$result[$k]['title'] = $details['title'];
				$result[$k]['note'] = $details['note'];
				$result[$k]['state_id'] = $details['state_id'];
				$result[$k]['owner_id'] = $details['owner_id'];
				$result[$k]['type'] = $details['type'];
			}
		}
		// get user details
		$userIds = [];
		foreach ($result as $k => $v) {
			if (!in_array($v['updated_by_id'], $userIds)) {
				$userIds[] = $v['updated_by_id'];
			}
		}
		$userDetails = [];
		foreach ($userIds as $uid) {
			$user = $this->request($userId, 'users/' . $uid);
			$userDetails[$uid] = [
				'firstname' => $user['firstname'],
				'lastname' => $user['lastname'],
				'organization_id' => $user['organization_id'],
				'image' => $user['image'],
			];
		}
		foreach ($result as $k => $v) {
			$user = $userDetails[$v['updated_by_id']];
			$result[$k]['firstname'] = $user['firstname'];
			$result[$k]['lastname'] = $user['lastname'];
			$result[$k]['organization_id'] = $user['organization_id'];
			$result[$k]['image'] = $user['image'];
		}

		return $result;
	}

	/**
	 * @param string $userId
	 * @param string $query
	 * @return array
	 * @throws PreConditionNotMetException
	 */
	public function search(string $userId, string $query): array {
		$params = [
			'query' => $query,
			'limit' => 20,
		];
		$searchResult = $this->request($userId, 'tickets/search', $params);

		$result = [];
		if (isset($searchResult['assets']) && isset($searchResult['assets']['Ticket'])) {
			foreach ($searchResult['assets']['Ticket'] as $id => $t) {
				$result[] = $t;
			}
		}
		// get ticket state names
		$states = $this->request($userId, 'ticket_states');
		$statesById = [];
		if (!isset($states['error'])) {
			foreach ($states as $state) {
				$id = $state['id'];
				$name = $state['name'];
				if ($id && $name) {
					$statesById[$id] = $name;
				}
			}
		}
		foreach ($result as $k => $v) {
			if (array_key_exists($v['state_id'], $statesById)) {
				$result[$k]['state_name'] = $statesById[$v['state_id']];
			}
		}
		// get ticket priority names
		$prios = $this->request($userId, 'ticket_priorities');
		$priosById = [];
		if (!isset($prios['error'])) {
			foreach ($prios as $prio) {
				$id = $prio['id'];
				$name = $prio['name'];
				if ($id && $name) {
					$priosById[$id] = $name;
				}
			}
		}
		foreach ($result as $k => $v) {
			if (array_key_exists($v['priority_id'], $priosById)) {
				$result[$k]['priority_name'] = $priosById[$v['priority_id']];
			}
		}
		// add owner information
		$userIds = [];
		$field = 'customer_id';
		foreach ($result as $k => $v) {
			if (!in_array($v[$field], $userIds)) {
				$userIds[] = $v[$field];
			}
		}
		$userDetails = [];
		foreach ($userIds as $uid) {
			$user = $this->request($userId, 'users/' . $uid);
			if (!isset($user['error'])) {
				$userDetails[$uid] = [
					'firstname' => $user['firstname'],
					'lastname' => $user['lastname'],
					'organization_id' => $user['organization_id'],
					'image' => $user['image'],
				];
			}
		}
		foreach ($result as $k => $v) {
			if (array_key_exists($v[$field], $userDetails)) {
				$user = $userDetails[$v[$field]];
				$result[$k]['u_firstname'] = $user['firstname'];
				$result[$k]['u_lastname'] = $user['lastname'];
				$result[$k]['u_organization_id'] = $user['organization_id'];
				$result[$k]['u_image'] = $user['image'];
			}
		}
		return $result;
	}

	/**
	 * authenticated request to get an image from zammad
	 *
	 * @param string $userId
	 * @param string $imageId
	 * @return array
	 * @throws Exception
	 */
	public function getZammadAvatar(string $userId, string $imageId): array {
		return $this->request($userId, 'users/image/' . $imageId, [], 'GET', false);
	}

	/**
	 * @param string $userId
	 * @param int $ticketId
	 * @return array
	 * @throws PreConditionNotMetException|Exception
	 */
	public function getTicketInfo(string $userId, int $ticketId): array {
		return $this->request($userId, 'tickets/' . $ticketId);
	}

	/**
	 * @param string $userId
	 * @return array
	 * @throws Exception
	 */
	public function getTicketStates(string $userId): array	{
		return $this->request($userId, 'ticket_states');
	}

	/**
	 * @param string $userId
	 * @param int $ticketId
	 * @return array
	 * @throws Exception
	 */
	public function getTickettags(string $userId, int $ticketId): array	{
		$params = [
			'object' => 'Ticket',
			'o_id' => $ticketId,
		];
		return $this->request($userId, 'tags', $params);
	}

	/**
	 * @param string $userId
	 * @param int $commentId
	 * @return array
	 * @throws Exception
	 */
	public function getCommentInfo(string $userId, int $commentId): array {
		return $this->request($userId, 'ticket_articles/' . $commentId);
	}

	/**
	 * @param string $userId
	 * @param int $zammadUserId
	 * @return array
	 * @throws Exception
	 */
	public function getUserInfo(string $userId, int $zammadUserId): array {
		return $this->request($userId, 'users/' . $zammadUserId);
	}

	/**
	 * @param string $userId
	 * @param int $zammadOrgId
	 * @return array
	 * @throws Exception
	 */
	public function getOrganizationInfo(string $userId, int $zammadOrgId): array {
		return $this->request($userId, 'organizations/' . $zammadOrgId);
	}

	/**
	 * @param string $userId
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @param bool $jsonResponse
	 * @return array
	 * @throws Exception
	 */
	public function request(string $userId, string $endPoint, array $params = [], string $method = 'GET',
							bool $jsonResponse = true): array {
		$zammadUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url');
		$this->checkTokenExpiration($userId, $zammadUrl);
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$authType = $this->config->getUserValue($userId, Application::APP_ID, 'token_type');
		try {
			$url = $zammadUrl . '/api/v1/' . $endPoint;
			$authHeader = ($authType === 'access') ? 'Token token=' : 'Bearer ';
			$options = [
				'headers' => [
					'Authorization'  => $authHeader . $accessToken,
					'User-Agent' => 'Nextcloud Zammad integration',
				]
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					// manage array parameters
					$paramsContent = '';
					foreach ($params as $key => $value) {
						if (is_array($value)) {
							foreach ($value as $oneArrayValue) {
								$paramsContent .= $key . '[]=' . urlencode($oneArrayValue) . '&';
							}
							unset($params[$key]);
						}
					}
					$paramsContent .= http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				if ($jsonResponse) {
					return json_decode($body, true);
				} else {
					return [
						'body' => $body,
						'headers' => $response->getHeaders(),
					];
				}
			}
		} catch (ServerException | ClientException $e) {
			$this->logger->warning('Zammad API error : '.$e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		} catch (ConnectException $e) {
			return ['error' => $e->getMessage()];
		}
	}

	private function checkTokenExpiration(string $userId, string $url): void {
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		$expireAt = $this->config->getUserValue($userId, Application::APP_ID, 'token_expires_at');
		if ($refreshToken !== '' && $expireAt !== '') {
			$nowTs = (new Datetime())->getTimestamp();
			$expireAt = (int) $expireAt;
			// if token expires in less than a minute or is already expired
			if ($nowTs > $expireAt - 60) {
				$this->refreshToken($userId, $url);
			}
		}
	}

	private function refreshToken(string $userId, string $url): bool {
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		if (!$refreshToken) {
			$this->logger->error('No Zammad refresh token found', ['app' => Application::APP_ID]);
			return false;
		}
		$result = $this->requestOAuthAccessToken($url, [
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'grant_type' => 'refresh_token',
			'refresh_token' => $refreshToken,
		], 'POST');
		if (isset($result['access_token'])) {
			$accessToken = $result['access_token'];
			$this->config->setUserValue($userId, Application::APP_ID, 'token', $accessToken);
			// TODO check if we need to store the refresh token here
//			$refreshToken = $result['refresh_token'];
//			$this->config->setUserValue($userId, Application::APP_ID, 'refresh_token', $refreshToken);
			if (isset($result['expires_in'])) {
				$nowTs = (new Datetime())->getTimestamp();
				$expiresAt = $nowTs + (int) $result['expires_in'];
				$this->config->setUserValue($userId, Application::APP_ID, 'token_expires_at', $expiresAt);
			}
			return true;
		} else {
			// impossible to refresh the token
			$this->logger->error(
				'Token is not valid anymore. Impossible to refresh it. '
					. $result['error'] . ' '
					. $result['error_description'] ?? '[no error description]',
				['app' => Application::APP_ID]
			);
			return false;
		}
	}

	/**
	 * @param string $url
	 * @param array $params
	 * @param string $method
	 * @return array
	 */
	public function requestOAuthAccessToken(string $url, array $params = [], string $method = 'GET'): array {
		try {
			$url = $url . '/oauth/token';
			$options = [
				'headers' => [
					'User-Agent'  => 'Nextcloud Zammad integration',
				]
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('OAuth access token refused')];
			} else {
				return json_decode($body, true);
			}
		} catch (Exception $e) {
			$this->logger->warning('Zammad OAuth error : '.$e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}
}
