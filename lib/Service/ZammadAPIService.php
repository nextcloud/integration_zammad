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

class ZammadAPIService {

    private $l10n;
    private $logger;

    /**
     * Service to make requests to Zammad v3 (JSON) API
     */
    public function __construct (
        string $appName,
        ILogger $logger,
        IL10N $l10n
    ) {
        $this->appName = $appName;
        $this->l10n = $l10n;
        $this->logger = $logger;
    }

    public function getNotifications($url, $accessToken, $authType, $since = null) {
        $params = [
            'state' => 'pending',
        ];
        $result = $this->request($url, $accessToken, $authType, 'online_notifications', $params);
        if (!is_array($result)) {
            return $result;
        }

        return $result;
    }

    public function getZammadAvatar($url) {
        return file_get_contents($url);
    }

    public function request($url, $accessToken, $authType, $endPoint, $params = [], $method = 'GET') {
        try {
            $authHeader = ($authType === 'access') ? 'Token token=' : 'Bearer ';
            $options = [
                'http' => [
                    'header'  => 'Authorization: ' . $authHeader . $accessToken .
                        "\r\nUser-Agent: Nextcloud Zammad integration",
                    'method' => $method,
                ]
            ];

            $url = $url . '/api/v1/' . $endPoint;
            if (count($params) > 0) {
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
                if ($method === 'GET') {
                    $url .= '?' . $paramsContent;
                } else {
                    $options['http']['content'] = $paramsContent;
                }
            }

            $context = stream_context_create($options);
            $result = file_get_contents($url, false, $context);
            if (!$result) {
                return $this->l10n->t('Bad credentials');
            } else {
                return json_decode($result, true);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Zammad API error : '.$e, array('app' => $this->appName));
            return $e;
        }
    }

}
