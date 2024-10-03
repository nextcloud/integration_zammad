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
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use OCA\Zammad\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\PreConditionNotMetException;

use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class ZammadAPIService {
	private ICache $cache;
	private IClient $client;

	/**
	 * Service to make requests to Zammad v3 (JSON) API
	 */
	public function __construct(
		private IUserManager $userManager,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IConfig $config,
		private INotificationManager $notificationManager,
		private ICrypto $crypto,
		ICacheFactory $cacheFactory,
		IClientService $clientService,
	) {
		$this->client = $clientService->newClient();
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '_global_info');
	}

	private function getZammadUrl(string $userId): string {
		$adminZammadOauthUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
		return $this->config->getUserValue($userId, Application::APP_ID, 'url') ?: $adminZammadOauthUrl;
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
			$zammadUrl = $this->getZammadUrl($userId);
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
		$notifications = $this->request($userId, 'online_notifications', $params);
		if (isset($notifications['error'])) {
			return $notifications;
		}
		// filter seen ones
		$notifications = array_filter($notifications, static function ($elem) {
			return !$elem['seen'];
		});
		// filter results by date
		if (!is_null($since)) {
			$sinceDate = new DateTime($since);
			$sinceTimestamp = $sinceDate->getTimestamp();
			$notifications = array_filter($notifications, static function ($elem) use ($sinceTimestamp) {
				$date = new Datetime($elem['updated_at']);
				$ts = $date->getTimestamp();
				return $ts > $sinceTimestamp;
			});
		}
		if ($limit) {
			$notifications = array_slice($notifications, 0, $limit);
		}
		$notifications = array_values($notifications);
		// get details
		foreach ($notifications as $i => $n) {
			$details = $this->request($userId, 'tickets/' . $n['o_id']);
			if (isset($details['error'])) {
				$this->logger->debug('Zammad API error: Impossible to get Zammad ticket information. ' . $details['error'], ['app' => Application::APP_ID]);
			} else {
				$notifications[$i]['title'] = $details['title'];
				$notifications[$i]['note'] = $details['note'];
				$notifications[$i]['state_id'] = $details['state_id'];
				$notifications[$i]['owner_id'] = $details['owner_id'];
				$notifications[$i]['type'] = $details['type'];
			}
		}
		// get user details
		$userIds = [];
		foreach ($notifications as $k => $v) {
			if (!in_array($v['updated_by_id'], $userIds)) {
				$userIds[] = $v['updated_by_id'];
			}
		}
		$userDetails = [];
		foreach ($userIds as $uid) {
			$user = $this->request($userId, 'users/' . urlencode($uid));
			if (isset($user['error'])) {
				$this->logger->debug('Zammad API error: Impossible to get Zammad user information. ' . $user['error'], ['app' => Application::APP_ID]);
			} else {
				$userDetails[$uid] = [
					'firstname' => $user['firstname'] ?? '??',
					'lastname' => $user['lastname'] ?? '??',
					'image' => $user['image'] ?? null,
				];
			}
		}
		foreach ($notifications as $i => $n) {
			$zammadUpdaterId = $n['updated_by_id'];
			if (isset($userDetails[$zammadUpdaterId])) {
				$user = $userDetails[$zammadUpdaterId];
				$notifications[$i]['firstname'] = $user['firstname'] ?? '??';
				$notifications[$i]['lastname'] = $user['lastname'] ?? '??';
				$notifications[$i]['image'] = $user['image'] ?? null;
			} else {
				$notifications[$i]['firstname'] = '??';
				$notifications[$i]['lastname'] = '??';
				$notifications[$i]['image'] = null;
			}
		}

		return $notifications;
	}

	/**
	 * @param string $userId
	 * @return array
	 * @throws Exception
	 */
	public function getTicketStateNames(string $userId): array {
		$zammadUrl = $this->getZammadUrl($userId);
		$cacheKey = md5($zammadUrl . '_states_by_id');
		$hit = $this->cache->get($cacheKey);
		if ($hit !== null) {
			return $hit;
		}

		$states = $this->request($userId, 'ticket_states');
		$statesById = [];
		if (!isset($states['error'])) {
			foreach ($states as $state) {
				$id = (int)$state['id'];
				$name = $state['name'];
				if ($id && $name) {
					$statesById[$id] = $name;
				}
			}
		}

		$this->cache->set($cacheKey, $statesById, 60 * 60 * 24);
		return $statesById;
	}

	/**
	 * @param string $userId
	 * @return array
	 * @throws Exception
	 */
	public function getPriorityNames(string $userId): array {
		$zammadUrl = $this->getZammadUrl($userId);
		$cacheKey = md5($zammadUrl . '_priorities_by_id');
		$hit = $this->cache->get($cacheKey);
		if ($hit !== null) {
			return $hit;
		}

		$prios = $this->request($userId, 'ticket_priorities');
		$priosById = [];
		if (!isset($prios['error'])) {
			foreach ($prios as $prio) {
				$id = (int)$prio['id'];
				$name = $prio['name'];
				if ($id && $name) {
					$priosById[$id] = $name;
				}
			}
		}

		$this->cache->set($cacheKey, $priosById, 60 * 60 * 24);
		return $priosById;
	}

	/**
	 * @param int $offset
	 * @param int $limit
	 * @return array [perPage, page, leftPadding]
	 */
	public static function getZammadPaginationValues(int $offset = 0, int $limit = 5): array {
		// compute pagination values
		// indexes offset => offset + limit
		if (($offset % $limit) === 0) {
			$perPage = $limit;
			// page number starts at 1
			$page = ($offset / $limit) + 1;
			return [$perPage, $page, 0];
		} else {
			$firstIndex = $offset;
			$lastIndex = $offset + $limit - 1;
			$perPage = $limit;
			// while there is no page that contains them'all
			while (intdiv($firstIndex, $perPage) !== intdiv($lastIndex, $perPage)) {
				$perPage++;
			}
			$page = intdiv($offset, $perPage) + 1;
			$leftPadding = $firstIndex % $perPage;

			return [$perPage, $page, $leftPadding];
		}
	}

	/**
	 * @param string $userId
	 * @param string $query
	 * @param int $offset
	 * @param int $limit
	 * @return array
	 * @throws Exception
	 */
	public function search(string $userId, string $query, int $offset = 0, int $limit = 5): array {
		[$perPage, $page, $leftPadding] = self::getZammadPaginationValues($offset, $limit);
		$params = [
			'query' => $query,
			//'limit' => $limit,
			'per_page' => $perPage,
			'page' => $page,
		];
		$searchResult = $this->request($userId, 'tickets/search', $params);

		$tickets = $searchResult['assets']['Ticket'] ?? [];
		$tickets = array_slice($tickets, $leftPadding, $limit);
		$users = $searchResult['assets']['User'] ?? [];
		$orgs = $searchResult['assets']['Organization'] ?? [];

		$statesById = $this->getTicketStateNames($userId);
		foreach ($tickets as $k => $ticket) {
			$stateId = (int)$ticket['state_id'];
			if (array_key_exists($stateId, $statesById)) {
				$tickets[$k]['state_name'] = $statesById[$stateId];
			}
		}
		// get ticket priority names
		$prioritiesById = $this->getPriorityNames($userId);
		foreach ($tickets as $k => $ticket) {
			$priorityId = (int)$ticket['priority_id'];
			if (array_key_exists($priorityId, $prioritiesById)) {
				$tickets[$k]['priority_name'] = $prioritiesById[$priorityId];
			}
		}
		// add owner/org information
		foreach ($tickets as $k => $ticket) {
			$customerId = (string)$ticket['customer_id'];
			if (isset($users[$customerId])) {
				$user = $users[$customerId];
				$tickets[$k]['u_firstname'] = $user['firstname'];
				$tickets[$k]['u_lastname'] = $user['lastname'];
				$tickets[$k]['u_organization_id'] = $user['organization_id'];
				$tickets[$k]['u_image'] = $user['image'];
			}
			$orgId = (string)$ticket['organization_id'];
			if (isset($orgs[$orgId])) {
				$org = $orgs[$orgId];
				$tickets[$k]['org_name'] = $org['name'];
			}
		}
		return $tickets;
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
	public function getTicketStates(string $userId): array {
		return $this->request($userId, 'ticket_states');
	}

	/**
	 * @param string $userId
	 * @param int $ticketId
	 * @return array
	 * @throws Exception
	 */
	public function getTickettags(string $userId, int $ticketId): array {
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
	public function request(
		string $userId, string $endPoint, array $params = [], string $method = 'GET', bool $jsonResponse = true,
	): array {
		$zammadUrl = $this->getZammadUrl($userId);
		$this->checkTokenExpiration($userId);
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$accessToken = $this->crypto->decrypt($accessToken);
		$authType = $this->config->getUserValue($userId, Application::APP_ID, 'token_type');
		try {
			$url = $zammadUrl . '/api/v1/' . $endPoint;
			$authHeader = ($authType === 'access') ? 'Token token=' : 'Bearer ';
			$options = [
				'headers' => [
					'Authorization' => $authHeader . $accessToken,
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
			} elseif ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} elseif ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} elseif ($method === 'DELETE') {
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
		} catch (ServerException|ClientException $e) {
			$this->logger->warning('Zammad API error : ' . $e->getMessage(), ['app' => Application::APP_ID]);
			$response = $e->getResponse();
			$statusCode = $response->getStatusCode();
			if ($statusCode === Http::STATUS_FORBIDDEN) {
				return ['error' => 'Forbidden'];
			} elseif ($statusCode === Http::STATUS_NOT_FOUND) {
				return ['error' => 'Not found'];
			}
			return ['error' => $e->getMessage()];
		} catch (ConnectException $e) {
			return ['error' => $e->getMessage()];
		}
	}

	private function checkTokenExpiration(string $userId): void {
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		$expireAt = $this->config->getUserValue($userId, Application::APP_ID, 'token_expires_at');
		if ($refreshToken !== '' && $expireAt !== '') {
			$nowTs = (new Datetime())->getTimestamp();
			$expireAt = (int)$expireAt;
			// if token expires in less than a minute or is already expired
			if ($nowTs > $expireAt - 60) {
				$this->refreshToken($userId);
			}
		}
	}

	private function refreshToken(string $userId): bool {
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientID = $this->crypto->decrypt($clientID);
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$clientSecret = $this->crypto->decrypt($clientSecret);
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		$refreshToken = $this->crypto->decrypt($refreshToken);
		$adminZammadOauthUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
		if (!$refreshToken) {
			$this->logger->error('No Zammad refresh token found', ['app' => Application::APP_ID]);
			return false;
		}
		$result = $this->requestOAuthAccessToken($adminZammadOauthUrl, [
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'grant_type' => 'refresh_token',
			'refresh_token' => $refreshToken,
		], 'POST');
		if (isset($result['access_token'])) {
			$accessToken = $result['access_token'];
			$encryptedAccessToken = $this->crypto->encrypt($accessToken);
			$this->config->setUserValue($userId, Application::APP_ID, 'token', $encryptedAccessToken);
			// TODO check if we need to store the refresh token here
			// 			$refreshToken = $result['refresh_token'];
			//			$encryptedRefreshToken = $this->crypto->encrypt($refreshToken);
			//			$this->config->setUserValue($userId, Application::APP_ID, 'refresh_token', $encryptedRefreshToken);
			if (isset($result['expires_in'])) {
				$nowTs = (new Datetime())->getTimestamp();
				$expiresAt = $nowTs + (int)$result['expires_in'];
				$this->config->setUserValue($userId, Application::APP_ID, 'token_expires_at', (string)$expiresAt);
			}
			return true;
		} else {
			// impossible to refresh the token
			$this->logger->error(
				'Token is not valid anymore. Impossible to refresh it. '
					. $result['error'] . ' '
					. ($result['error_description'] ?? '[no error description]'),
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
					'User-Agent' => 'Nextcloud Zammad integration',
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
			} elseif ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} elseif ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} elseif ($method === 'DELETE') {
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
			$this->logger->warning('Zammad OAuth error : ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}
}
