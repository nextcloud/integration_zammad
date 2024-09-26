<?php
/**
 * Nextcloud - Zammad
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

return [
	'routes' => [
		['name' => 'config#oauthRedirect', 'url' => '/oauth-redirect', 'verb' => 'GET'],
		['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setSensitiveConfig', 'url' => '/sensitive-config', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
		['name' => 'config#setSensitiveAdminConfig', 'url' => '/sensitive-admin-config', 'verb' => 'PUT'],
		['name' => 'zammadAPI#getNotifications', 'url' => '/notifications', 'verb' => 'GET'],
		['name' => 'zammadAPI#getZammadUrl', 'url' => '/url', 'verb' => 'GET'],
		['name' => 'zammadAPI#getZammadAvatar', 'url' => '/avatar/{imageId}', 'verb' => 'GET'],
	]
];
