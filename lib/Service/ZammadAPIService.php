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
use OCP\Http\Client\IClientService;

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
        IClientService $clientService
    ) {
        $this->appName = $appName;
        $this->l10n = $l10n;
        $this->logger = $logger;
        $this->clientService = $clientService;
        $this->client = $clientService->newClient();
    }

    public function getNotifications($url, $accessToken, $authType, $since = null) {
        $params = [
            'state' => 'pending',
        ];
        $result = $this->request($url, $accessToken, $authType, 'online_notifications', $params);
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
            $details = $this->request($url, $accessToken, $authType, 'tickets/' . $v['o_id']);
            $result[$k]['title'] = $details['title'];
            $result[$k]['note'] = $details['note'];
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
            $user = $this->request($url, $accessToken, $authType, 'users/' . $uid);
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
    public function getZammadAvatar($image, $url, $accessToken, $authType) {
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

    public function request($url, $accessToken, $authType, $endPoint, $params = [], $method = 'GET') {
        try {
            $url = $url . '/api/v1/' . $endPoint;
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
        } catch (\Exception $e) {
            $this->logger->warning('Zammad API error : '.$e, array('app' => $this->appName));
            return $e;
        }
    }

}
