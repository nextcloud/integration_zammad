<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zammad\Controller;

use Exception;
use OCA\Zammad\AppInfo\Application;
use OCA\Zammad\ResponseDefinitions;
use OCA\Zammad\Service\TicketText;
use OCA\Zammad\Service\ZammadAPIService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Config\IUserConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Zammad tickets for API clients such as the Assistant.
 *
 * All calls are made with the user's own Zammad token, so Zammad decides which
 * tickets and articles, internal notes included, a user may see.
 *
 * @psalm-import-type ZammadArticle from ResponseDefinitions
 * @psalm-import-type ZammadError from ResponseDefinitions
 * @psalm-import-type ZammadTicket from ResponseDefinitions
 * @psalm-import-type ZammadTicketList from ResponseDefinitions
 * @psalm-import-type ZammadTicketListEntry from ResponseDefinitions
 */
class TicketApiController extends OCSController {

	public const LIST_MAX_LIMIT = 20;
	public const DEFAULT_MAX_CHARS = 40000;
	private const MIN_MAX_CHARS = 2000;
	private const MAX_MAX_CHARS = 200000;
	/** The rest of max_chars is left for the ticket's fields and the JSON around the text */
	private const TEXT_SHARE_OF_MAX_CHARS = 0.75;

	public function __construct(
		string $appName,
		IRequest $request,
		private ZammadAPIService $zammadAPIService,
		private IUserConfig $userConfig,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List tickets found with a Zammad search query
	 *
	 * The query is passed to Zammad's ticket search unchanged, with the same syntax as Zammad's search bar,
	 * e.g. `state.name:(new OR open) AND priority.name:"3 high"`.
	 *
	 * @param string $query Query in Zammad's search syntax. Empty lists all tickets the user can see
	 * @param bool $mine Only tickets owned by the user
	 * @param int<1, 20> $limit Number of tickets
	 * @param string|null $sort_by Ticket attribute to sort by, e.g. created_at
	 * @param string|null $order_by Sort direction, asc or desc
	 * @return DataResponse<Http::STATUS_OK, ZammadTicketList, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_PRECONDITION_FAILED|Http::STATUS_BAD_GATEWAY, ZammadError, array{}>
	 *
	 * 200: Tickets returned
	 * 400: Invalid parameters, or Zammad could not run the query
	 * 412: The user is not connected to Zammad
	 * 502: Zammad could not be reached
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/tickets')]
	public function listTickets(string $query = '', bool $mine = false, int $limit = 10,
		?string $sort_by = null, ?string $order_by = null): DataResponse {
		$zammadUrl = $this->getZammadUrl();
		if ($zammadUrl === null) {
			return $this->notConnected();
		}
		if ($sort_by !== null && $sort_by !== '' && preg_match('/^[a-z0-9_.]+$/i', $sort_by) !== 1) {
			return self::error(Http::STATUS_BAD_REQUEST, 'invalid_parameter', $this->l10n->t('sort_by has to be the name of a ticket attribute.'));
		}
		if ($order_by !== null && $order_by !== '' && !in_array(strtolower($order_by), ['asc', 'desc'], true)) {
			return self::error(Http::STATUS_BAD_REQUEST, 'invalid_parameter', $this->l10n->t('order_by has to be asc or desc.'));
		}

		$query = trim($query);
		if ($mine) {
			$zammadUserId = $this->userConfig->getValueString((string)$this->userId, Application::APP_ID, 'user_id', lazy: true);
			if (preg_match('/^[0-9]+$/', $zammadUserId) !== 1) {
				return self::error(Http::STATUS_PRECONDITION_FAILED, 'not_connected',
					$this->l10n->t('Your Zammad account is not known yet. Please disconnect and connect it again in the Connected accounts settings.'));
			}
			$query = $query === '' ? 'owner_id:' . $zammadUserId : '(' . $query . ') AND owner_id:' . $zammadUserId;
		}
		$limit = max(1, min($limit, self::LIST_MAX_LIMIT));
		$params = [
			'query' => $query === '' ? '*' : $query,
			'limit' => $limit,
			'per_page' => $limit,
			'page' => 1,
			// required for Zammad 6.5+ compatibility, see ZammadAPIService::search()
			'full' => 'true',
		];
		if ($sort_by !== null && $sort_by !== '') {
			$params['sort_by'] = $sort_by;
		}
		if ($order_by !== null && $order_by !== '') {
			$params['order_by'] = strtolower($order_by);
		}

		try {
			$result = $this->zammadAPIService->request((string)$this->userId, 'tickets/search', $params);
			if (isset($result['error'])) {
				$code = $result['error-code'] ?? null;
				if ($code === Http::STATUS_UNAUTHORIZED) {
					return $this->badCredentials();
				}
				// only a refused query is the caller's to fix; server errors and timeouts are not
				if (!is_int($code) || $code >= 500) {
					return self::error(Http::STATUS_BAD_GATEWAY, 'zammad_error', $this->l10n->t('Zammad answered with an error.'));
				}
				return self::error(Http::STATUS_BAD_REQUEST, 'search_failed', $this->l10n->t('Zammad could not run this search. Check the query syntax.'));
			}
			$states = $this->zammadAPIService->getTicketStateNames((string)$this->userId);
			$priorities = $this->zammadAPIService->getPriorityNames((string)$this->userId);
		} catch (Exception $e) {
			return $this->unreachable($e);
		}

		$assets = $result['assets'] ?? [];
		$ticketsById = $assets['Ticket'] ?? [];
		$ids = $result['tickets'] ?? $result['record_ids'] ?? array_keys($ticketsById);
		$tickets = [];
		foreach (is_array($ids) ? $ids : [] as $id) {
			$ticket = $ticketsById[$id] ?? null;
			if (is_array($ticket) && count($tickets) < $limit) {
				$tickets[] = self::listEntry($ticket, $assets, $states, $priorities, $zammadUrl);
			}
		}
		return new DataResponse(['tickets' => $tickets, 'count' => count($tickets)]);
	}

	/**
	 * Get a ticket with its conversation
	 *
	 * The articles come as plain text, emails without quoted earlier messages, newest last. When they do not fit into
	 * max_chars, the oldest ones are left out and `truncated` is true. Attachments are listed, not downloaded.
	 *
	 * @param string $ticket Ticket ID, or a ticket link of the user's Zammad server
	 * @param int|null $max_chars How large the answer may get, e.g. what a language model client has room for
	 * @return DataResponse<Http::STATUS_OK, ZammadTicket, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_PRECONDITION_FAILED|Http::STATUS_BAD_GATEWAY, ZammadError, array{}>
	 *
	 * 200: Ticket returned
	 * 400: Not a ticket ID, or a link to another Zammad server
	 * 404: The ticket does not exist, or the user cannot see it
	 * 412: The user is not connected to Zammad
	 * 502: Zammad could not be reached
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/ticket')]
	public function getTicket(string $ticket, ?int $max_chars = null): DataResponse {
		$zammadUrl = $this->getZammadUrl();
		if ($zammadUrl === null) {
			return $this->notConnected();
		}
		$parsed = TicketText::parseTicketReference($zammadUrl, $ticket);
		if (!isset($parsed['id'])) {
			return $parsed['error'] === 'wrong_host'
				? self::error(Http::STATUS_BAD_REQUEST, 'wrong_host',
					$this->l10n->t('This ticket link points to a different Zammad server than the one your account is connected to (%s).', [$zammadUrl]))
				: self::error(Http::STATUS_BAD_REQUEST, 'invalid_ticket',
					$this->l10n->t('Give a ticket ID or a ticket link like %s/#ticket/zoom/123.', [$zammadUrl]));
		}
		$ticketId = $parsed['id'];

		try {
			// full: the ticket with its users and organization, the same assets a search returns
			$info = $this->zammadAPIService->request((string)$this->userId, 'tickets/' . $ticketId, ['full' => 'true']);
			$articles = isset($info['error']) ? [] : $this->zammadAPIService->getArticlesByTicket((string)$this->userId, $ticketId);
			$tags = isset($info['error']) ? [] : $this->zammadAPIService->getTickettags((string)$this->userId, $ticketId);
			$states = $this->zammadAPIService->getTicketStateNames((string)$this->userId);
			$priorities = $this->zammadAPIService->getPriorityNames((string)$this->userId);
		} catch (Exception $e) {
			return $this->unreachable($e);
		}
		foreach ([$info, $articles] as $response) {
			if (isset($response['error'])) {
				return match ($response['error-code'] ?? null) {
					Http::STATUS_UNAUTHORIZED => $this->badCredentials(),
					Http::STATUS_FORBIDDEN, Http::STATUS_NOT_FOUND => self::error(Http::STATUS_NOT_FOUND, 'not_found',
						$this->l10n->t('Ticket %s does not exist, or your Zammad account has no access to it.', [(string)$ticketId])),
					default => self::error(Http::STATUS_BAD_GATEWAY, 'zammad_error', $this->l10n->t('Zammad answered with an error.')),
				};
			}
		}

		$maxChars = max(self::MIN_MAX_CHARS, min($max_chars ?? self::DEFAULT_MAX_CHARS, self::MAX_MAX_CHARS));
		[$articleList, $truncated] = self::articles($articles, (int)($maxChars * self::TEXT_SHARE_OF_MAX_CHARS));
		$assets = is_array($info['assets'] ?? null) ? $info['assets'] : [];
		$ticketData = $assets['Ticket'][$ticketId] ?? [];
		$entry = self::listEntry(is_array($ticketData) ? $ticketData : [], $assets, $states, $priorities, $zammadUrl);
		return new DataResponse([
			'id' => $ticketId,
			'number' => $entry['number'],
			'title' => $entry['title'],
			'state' => $entry['state'],
			'priority' => $entry['priority'],
			'owner' => $entry['owner'],
			'customer' => $entry['customer'],
			'organization' => self::stringOrNull($assets['Organization'][(int)($ticketData['organization_id'] ?? 0)]['name'] ?? null),
			'tags' => array_values(array_map('strval', $tags['tags'] ?? [])),
			'created_at' => $entry['created_at'],
			'updated_at' => $entry['updated_at'],
			'url' => $zammadUrl . '/#ticket/zoom/' . $ticketId,
			'article_count' => count(array_filter($articles, 'is_array')),
			'truncated' => $truncated,
			'articles' => $articleList,
		]);
	}

	/**
	 * The ticket's fields with names instead of IDs, the same for lists and single tickets.
	 *
	 * @return ZammadTicketListEntry
	 */
	private static function listEntry(array $ticket, array $assets, array $states, array $priorities, string $zammadUrl): array {
		$users = $assets['User'] ?? [];
		$userName = static function (int $id) use ($users): ?string {
			// user 1 is Zammad's placeholder for "nobody"
			if ($id <= 1 || !isset($users[$id])) {
				return null;
			}
			$user = $users[$id];
			return trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')) ?: self::stringOrNull($user['email'] ?: $user['login'] ?: null);
		};
		$id = (int)($ticket['id'] ?? 0);
		return [
			'id' => $id,
			'number' => (string)($ticket['number'] ?? ''),
			'title' => (string)($ticket['title'] ?? ''),
			'state' => self::stringOrNull($states[(int)($ticket['state_id'] ?? 0)] ?? null),
			'priority' => self::stringOrNull($priorities[(int)($ticket['priority_id'] ?? 0)] ?? null),
			'owner' => $userName((int)($ticket['owner_id'] ?? 0)),
			'customer' => $userName((int)($ticket['customer_id'] ?? 0)),
			'created_at' => self::stringOrNull($ticket['created_at'] ?? null),
			'updated_at' => self::stringOrNull($ticket['updated_at'] ?? null),
			'last_contact_customer_at' => self::stringOrNull($ticket['last_contact_customer_at'] ?? null),
			'last_contact_agent_at' => self::stringOrNull($ticket['last_contact_agent_at'] ?? null),
			'escalation_at' => self::stringOrNull($ticket['escalation_at'] ?? null),
			'url' => $zammadUrl . '/#ticket/zoom/' . $id,
		];
	}

	/**
	 * The articles that fit into the text budget, newest last. A cut drops the oldest.
	 *
	 * @return array{0: list<ZammadArticle>, 1: bool}
	 */
	private static function articles(array $articles, int $budget): array {
		$kept = [];
		$used = 0;
		$truncated = false;
		foreach (array_reverse(array_values(array_filter($articles, 'is_array'))) as $article) {
			$remaining = $budget - $used;
			if ($remaining <= 0) {
				$truncated = true;
				break;
			}
			$body = (string)($article['body'] ?? '');
			$contentType = (string)($article['content_type'] ?? '');
			// only mails carry quoted history; a note, phone or chat article is kept whole
			$text = ($article['type'] ?? null) === 'email'
				? TicketText::articleText($body, $contentType)
				: TicketText::articleBodyToText($body, $contentType);
			if (mb_strlen($text) > $remaining) {
				$text = mb_substr($text, 0, $remaining) . ' […]';
				$truncated = true;
			}
			$used += mb_strlen($text);
			$attachments = [];
			foreach (is_array($article['attachments'] ?? null) ? $article['attachments'] : [] as $attachment) {
				if (is_array($attachment)) {
					$attachments[] = ['filename' => (string)($attachment['filename'] ?? ''), 'size' => (int)($attachment['size'] ?? 0)];
				}
			}
			$kept[] = [
				'id' => (int)($article['id'] ?? 0),
				'from' => self::stringOrNull($article['from'] ?? null),
				'sender' => self::stringOrNull($article['sender'] ?? null),
				'type' => self::stringOrNull($article['type'] ?? null),
				'internal' => !empty($article['internal']),
				'created_at' => self::stringOrNull($article['created_at'] ?? null),
				'text' => $text,
				'attachments' => $attachments,
			];
		}
		return [array_reverse($kept), $truncated];
	}

	private static function stringOrNull(mixed $value): ?string {
		return is_scalar($value) && $value !== '' ? (string)$value : null;
	}

	/**
	 * @return string|null the user's Zammad URL without a trailing slash, null if the user is not connected
	 */
	private function getZammadUrl(): ?string {
		$token = $this->userConfig->getValueString((string)$this->userId, Application::APP_ID, 'token', lazy: true);
		$zammadUrl = rtrim(trim($this->zammadAPIService->getZammadUrl((string)$this->userId)), '/');
		return $token === '' || $zammadUrl === '' ? null : $zammadUrl;
	}

	/**
	 * @return DataResponse<Http::STATUS_PRECONDITION_FAILED, ZammadError, array{}>
	 */
	private function notConnected(): DataResponse {
		return self::error(Http::STATUS_PRECONDITION_FAILED, 'not_connected',
			$this->l10n->t('Your Nextcloud account is not connected to Zammad. Connect it in the Connected accounts settings first.'));
	}

	/**
	 * @return DataResponse<Http::STATUS_PRECONDITION_FAILED, ZammadError, array{}>
	 */
	private function badCredentials(): DataResponse {
		return self::error(Http::STATUS_PRECONDITION_FAILED, 'bad_credentials',
			$this->l10n->t('Zammad did not accept your access token. Connect your account again in the Connected accounts settings.'));
	}

	/**
	 * @return DataResponse<Http::STATUS_BAD_GATEWAY, ZammadError, array{}>
	 */
	private function unreachable(Exception $e): DataResponse {
		$this->logger->warning('Could not reach Zammad: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		return self::error(Http::STATUS_BAD_GATEWAY, 'zammad_error', $this->l10n->t('Zammad could not be reached.'));
	}

	/**
	 * @template S of Http::STATUS_*
	 * @param S $status
	 * @return DataResponse<S, ZammadError, array{}>
	 */
	private static function error(int $status, string $code, string $message): DataResponse {
		return new DataResponse(['error' => $code, 'message' => $message], $status);
	}
}
