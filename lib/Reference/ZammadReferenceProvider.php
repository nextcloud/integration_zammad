<?php
/**
 * @copyright Copyright (c) 2022 Julien Veyssier <eneiluj@posteo.net>
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Zammad\Reference;

use Exception;
use OCP\Collaboration\Reference\Reference;
use OC\Collaboration\Reference\ReferenceManager;
use OCA\Zammad\AppInfo\Application;
use OCA\Zammad\Service\ZammadAPIService;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\IReferenceProvider;
use OCP\IConfig;
use OCP\PreConditionNotMetException;

class ZammadReferenceProvider implements IReferenceProvider {
	private ZammadAPIService $zammadAPIService;
	private IConfig $config;
	private ReferenceManager $referenceManager;
	private ?string $userId;

	public function __construct(ZammadAPIService $zammadAPIService,
								IConfig $config,
								ReferenceManager $referenceManager,
								?string $userId) {
		$this->zammadAPIService = $zammadAPIService;
		$this->config = $config;
		$this->referenceManager = $referenceManager;
		$this->userId = $userId;
	}

	private function isMatching(string $referenceText, string $url): bool {
		return preg_match('/^' . preg_quote($url, '/') . '\/#ticket\/zoom\/[0-9]+/', $referenceText) === 1;
	}

	/**
	 * @inheritDoc
	 */
	public function matchReference(string $referenceText): bool {
		if ($this->userId !== null) {
			$linkPreviewEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'link_preview_enabled', '1') === '1';
			if (!$linkPreviewEnabled) {
				return false;
			}
		}
		$adminLinkPreviewEnabled = $this->config->getAppValue(Application::APP_ID, 'link_preview_enabled', '1') === '1';
		if (!$adminLinkPreviewEnabled) {
			return false;
		}

		$zammadUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url');

		return $this->isMatching($referenceText, $zammadUrl);
	}

	/**
	 * @inheritDoc
	 */
	public function resolveReference(string $referenceText): ?IReference {
		$zammadUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url');
		if ($zammadUrl !== null) {
			$parts = $this->getLinkParts($zammadUrl, $referenceText);
			if ($parts !== null) {
				[$ticketId, $end] = $parts;
//				$projectLabels = $this->zammadAPIService->getProjectLabels($this->userId, $projectInfo['id']);
				$commentInfo = $this->getCommentInfo($end);
				$commentAuthorInfo = null;
				$commentAuthorOrgInfo = null;
				$ticketStates = null;
				$ticketTags = null;
				$authorInfo = null;
				$authorOrgInfo = null;
				$ticketInfo = $this->zammadAPIService->getTicketInfo($this->userId, (int)$ticketId);
				if (!isset($ticketInfo['error']) && isset($ticketInfo['customer_id'])) {
					$authorInfo = $this->zammadAPIService->getUserInfo($this->userId, $ticketInfo['customer_id']);
					if (isset($ticketInfo['organization_id']) && $ticketInfo['organization_id'] !== null) {
						$authorOrgInfo = $this->zammadAPIService->getOrganizationInfo($this->userId, $ticketInfo['organization_id']);
					}
					$ticketStates = $this->zammadAPIService->getTicketStates($this->userId);
					$ticketTags = $this->zammadAPIService->getTicketTags($this->userId, (int)$ticketId);
					if ($commentInfo !== null) {
						if ($commentInfo['created_by_id'] === $ticketInfo['customer_id']) {
							$commentAuthorInfo = $authorInfo;
							$commentAuthorOrgInfo = $authorOrgInfo;
						} else {
							$commentAuthorInfo = $this->zammadAPIService->getUserInfo($this->userId, $commentInfo['created_by_id']);
							if (isset($commentAuthorInfo['organization_id']) && $commentAuthorInfo['organization_id'] !== null) {
								$commentAuthorOrgInfo = $this->zammadAPIService->getOrganizationInfo($this->userId, $commentAuthorInfo['organization_id']);
							}
						}
					}
				}
				$reference = new Reference($referenceText);
				$reference->setRichObject(
					Application::APP_ID,
					array_merge([
						'zammad_url' => $zammadUrl,
						'zammad_ticket_id' => (int)$ticketId,
						'zammad_ticket_states' => $ticketStates,
						'zammad_ticket_tags' => $ticketTags,
						'zammad_ticket_author' => $authorInfo,
						'zammad_ticket_author_organization' => $authorOrgInfo,
						'zammad_comment' => $commentInfo,
						'zammad_comment_author' => $commentAuthorInfo,
						'zammad_comment_author_organization' => $commentAuthorOrgInfo,
					], $ticketInfo)
				);
				return $reference;
			}
		}

		return null;
	}

	/**
	 * @param string $zammadUrl
	 * @param string $url
	 * @return array|null
	 */
	private function getLinkParts(string $zammadUrl, string $url): ?array {
		preg_match('/^' . preg_quote($zammadUrl, '/') . '\/#ticket\/zoom\/([0-9]+)(.*$)/', $url, $matches);
		return count($matches) > 2 ? [$matches[1], $matches[2]] : null;
	}

	/**
	 * @param string $urlEnd
	 * @return int|null
	 */
	private function getCommentId(string $urlEnd): ?int {
		preg_match('/^\/([0-9]+)$/', $urlEnd, $matches);
		return (is_array($matches) && count($matches) > 1) ? ((int) $matches[1]) : null;
	}

	/**
	 * @param string $end
	 * @return array|null
	 * @throws PreConditionNotMetException|Exception
	 */
	private function getCommentInfo(string $end): ?array {
		$commentId = $this->getCommentId($end);
		return $commentId !== null ? $this->zammadAPIService->getCommentInfo($this->userId, $commentId) : null;
	}

	/**
	 * We use the userId here because when connecting/disconnecting from the GitHub account,
	 * we want to invalidate all the user cache and this is only possible with the cache prefix
	 * @inheritDoc
	 */
	public function getCachePrefix(string $referenceId): string {
		return $this->userId ?? '';
	}

	/**
	 * We don't use the userId here but rather a reference unique id
	 * @inheritDoc
	 */
	public function getCacheKey(string $referenceId): ?string {
		return $referenceId;
	}

	/**
	 * @param string $userId
	 * @return void
	 */
	public function invalidateUserCache(string $userId): void {
		$this->referenceManager->invalidateCache($userId);
	}
}
