<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020, Julien Veyssier
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\Zammad\Search;

use OCA\Zammad\Service\ZammadAPIService;
use OCA\Zammad\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;

class ZammadSearchProvider implements IProvider {

	/** @var IAppManager */
	private $appManager;

	/** @var IL10N */
	private $l10n;

	/** @var IURLGenerator */
	private $urlGenerator;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var ZammadAPIService
	 */
	private $service;

	/**
	 * CospendSearchProvider constructor.
	 *
	 * @param IAppManager $appManager
	 * @param IL10N $l10n
	 * @param IConfig $config
	 * @param IURLGenerator $urlGenerator
	 * @param ZammadAPIService $service
	 */
	public function __construct(IAppManager $appManager,
								IL10N $l10n,
								IConfig $config,
								IURLGenerator $urlGenerator,
								ZammadAPIService $service) {
		$this->appManager = $appManager;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->service = $service;
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'zammad-search';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->l10n->t('Zammad');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(string $route, array $routeParameters): int {
		if (strpos($route, Application::APP_ID . '.') === 0) {
			// Active app, prefer Zammad results
			return -1;
		}

		return 20;
	}

	/**
	 * @inheritDoc
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		if (!$this->appManager->isEnabledForUser(Application::APP_ID, $user)) {
			return SearchResult::complete($this->getName(), []);
		}

		$limit = $query->getLimit();
		$term = $query->getTerm();
		$offset = $query->getCursor();
		$offset = $offset ? intval($offset) : 0;

		$theme = $this->config->getUserValue($user->getUID(), 'accessibility', 'theme');
		$thumbnailUrl = ($theme === 'dark')
			? $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
			: $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');

		$zammadUrl = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'url');
		$accessToken = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'token');
		$tokenType = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'token_type');
		$refreshToken = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'refresh_token');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');

		$searchEnabled = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'search_enabled', '0') === '1';
		if ($accessToken === '' || !$searchEnabled) {
			return SearchResult::paginated($this->getName(), [], 0);
		}

		$searchResults = $this->service->search($zammadUrl, $accessToken, $tokenType, $refreshToken, $clientID, $clientSecret, $user->getUID(), $term);
		$searchResults = array_slice($searchResults, $offset, $limit);

		if (isset($searchResults['error'])) {
			return SearchResult::paginated($this->getName(), [], 0);
		}

		$formattedResults = array_map(function (array $entry) use ($thumbnailUrl, $zammadUrl): ZammadSearchResultEntry {
			return new ZammadSearchResultEntry(
				$this->getThumbnailUrl($entry, $thumbnailUrl),
				$this->getMainText($entry),
				$this->getSubline($entry),
				$this->getLinkToZammad($entry, $zammadUrl),
				'',
				true
			);
		}, $searchResults);

		return SearchResult::paginated(
			$this->getName(),
			$formattedResults,
			$offset + $limit
		);
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getMainText(array $entry): string {
		return $entry['title'];
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getSubline(array $entry): string {
		$priorityName = $entry['priority_name'] ?: $entry['priority_id'];
		$prefix = $entry['state_name']
			? '[' . $this->truncate($entry['state_name'], 10) . '/' . $priorityName . '] '
			: '[' . $priorityName . '] ';
		return $prefix . $entry['u_firstname'] . ' ' . $entry['u_lastname'];
	}

	/**
	 * @param string $s
	 * @param int $len
	 * @return string
	 */
	private function truncate(string $s, int $len): string {
		return strlen($s) > $len
			? substr($s, 0, $len) . '…'
			: $s;
	}

	/**
	 * @param array $entry
	 * @param string $url
	 * @return string
	 */
	protected function getLinkToZammad(array $entry, string $url): string {
		return $url . '/#ticket/zoom/' . $entry['id'];
	}

	/**
	 * @param array $entry
	 * @param string $thumbnailUrl
	 * @return string
	 */
	protected function getThumbnailUrl(array $entry, string $thumbnailUrl): string {
		$initials = null;
		if ($entry['u_firstname'] && $entry['u_lastname']) {
			$initials = $entry['u_firstname'][0] . $entry['u_lastname'][0];
		}
		return isset($entry['u_image'])
			? $this->urlGenerator->linkToRoute('integration_zammad.zammadAPI.getZammadAvatar', []) . '?imageId=' . urlencode($entry['u_image'])
			: ($initials
				? $this->urlGenerator->linkToRouteAbsolute('core.GuestAvatar.getAvatar', ['guestName' => $initials, 'size' => 64])
				: $thumbnailUrl);
	}
}
