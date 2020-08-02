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

use OCP\IL10N;
use OCP\ILogger;
use OCP\IConfig;
use OCP\Http\Client\IClientService;
use GuzzleHttp\Exception\ClientException;

class ZammadAPIService {

    private $l10n;
    private $logger;

    /**
     * Service to make requests to Zammad v3 (JSON) API
     */
    public function __construct (
        string $appName,
        ILogger $logger,
        IL10N $l10n,
        IConfig $config,
        IClientService $clientService,
        $userId
    ) {
        $this->appName = $appName;
        $this->l10n = $l10n;
        $this->logger = $logger;
        $this->config = $config;
        $this->clientService = $clientService;
        $this->client = $clientService->newClient();
        $this->userId = $userId;
    }

    public function getNotifications($url, $accessToken, $authType, $refreshToken, $clientID, $clientSecret, $since = null) {
        $params = [
            'state' => 'pending',
        ];
        $result = $this->request(
            $url, $accessToken, $authType, $refreshToken, $clientID, $clientSecret, 'online_notifications', $params
        );
        if (!is_array($result)) {
            return $result;
        }
        // filter seen ones
        $result = array_filter($result, function($elem) {
            return !$elem['seen'];
        });
        // filter results by date
        if (!is_null($since)) {
            $sinceDate = new \DateTime($since);
            $sinceTimestamp = $sinceDate->getTimestamp();
            $result = array_filter($result, function($elem) use ($sinceTimestamp) {
                $date = new \Datetime($elem['updated_at']);
                $ts = $date->getTimestamp();
                return $ts > $sinceTimestamp;
            });
        } else {
            // take 20 most recent if no date filter
            $result = array_slice($result, 0, 20);
        }
        $result = array_values($result);
        // get details
        foreach ($result as $k => $v) {
            $details = $this->request(
                $url, $accessToken, $authType, $refreshToken, $clientID, $clientSecret, 'tickets/' . $v['o_id']
            );
            if (is_array($details)) {
                $result[$k]['title'] = $details['title'];
                $result[$k]['note'] = $details['note'];
            }
        }
        // get user details
        $userIds = [];
        foreach ($result as $k => $v) {
            if (!in_array($v['updated_by_id'], $userIds)) {
                array_push($userIds, $v['updated_by_id']);
            }
        }
        $userDetails = [];
        foreach ($userIds as $uid) {
            $user = $this->request(
                $url, $accessToken, $authType, $refreshToken, $clientID, $clientSecret, 'users/' . $uid
            );
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

    // authenticated request to get an image from zammad
    public function getZammadAvatar($url, $accessToken, $authType, $refreshToken, $clientID, $clientSecret, $image) {
        $url = $url . '/api/v1/users/image/' . $image;
        $authHeader = ($authType === 'access') ? 'Token token=' : 'Bearer ';
        $options = [
            'headers' => [
                'Authorization'  => $authHeader . $accessToken,
                'User-Agent' => 'Nextcloud Zammad integration',
            ]
        ];
        return $this->client->get($url, $options)->getBody();
    }

    public function request($zammadUrl, $accessToken, $authType, $refreshToken, $clientID, $clientSecret, $endPoint, $params = [], $method = 'GET') {
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
            }
            $body = $response->getBody();
            $respCode = $response->getStatusCode();

            if ($respCode >= 400) {
                return $this->l10n->t('Bad credentials');
            } else {
                return json_decode($body, true);
            }
        } catch (ClientException $e) {
            $this->logger->warning('Zammad API error : '.$e, array('app' => $this->appName));
            $response = $e->getResponse();
            $body = (string) $response->getBody();
            // refresh token if it's invalid and we are using oauth
            // response can be : 'OAuth2 token is expired!', 'Invalid token!' or 'Not authorized'
            if ($authType === 'oauth' and strpos($body, 'OAuth2 token is expired') !== false) {
                $this->logger->warning('Trying to REFRESH the access token', array('app' => $this->appName));
                // try to refresh the token
                $result = $this->requestOAuthAccessToken($zammadUrl, [
                    'client_id' => $clientID,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ], 'POST');
                if (is_array($result) and isset($result['access_token'])) {
                    $accessToken = $result['access_token'];
                    $this->config->setUserValue($this->userId, 'zammad', 'token', $accessToken);
                    // retry the request with new access token
                    return $this->request(
                        $zammadUrl, $accessToken, $authType, $refreshToken, $clientID, $clientSecret, $endPoint, $params, $method
                    );
                }
            }
            return $e;
        }
    }

    public function requestOAuthAccessToken($url, $params = [], $method = 'GET') {
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
            }
            $body = $response->getBody();
            $respCode = $response->getStatusCode();

            if ($respCode >= 400) {
                return $this->l10n->t('OAuth access token refused');
            } else {
                return json_decode($body, true);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Zammad OAuth error : '.$e, array('app' => $this->appName));
            return $e;
        }
    }

}
