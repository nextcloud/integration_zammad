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
 * @psalm-import-type ZammadNote from ResponseDefinitions
 * @psalm-import-type ZammadTicket from ResponseDefinitions
 * @psalm-import-type ZammadTicketList from ResponseDefinitions
 * @psalm-import-type ZammadTicketListEntry from ResponseDefinitions
 */
class TicketApiController extends OCSController {

	public const LIST_MAX_LIMIT = 20;
	public const ARTICLES_MAX_LIMIT = 50;

	public const NOTE_MAX_CHARS = 20000;

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
	 * e.g. `state.name:(new OR open) AND priority.name:"3 high"`. Field syntax needs Elasticsearch on the Zammad
	 * server: without it, Zammad matches the query as plain text against ticket title, number and articles.
	 * A query Zammad cannot parse finds no tickets rather than giving an error.
	 *
	 * @param string $query Query in Zammad's search syntax. Empty lists all tickets the user can see
	 * @param bool $mine Only tickets owned by the user
	 * @param int<1, 20> $limit Number of tickets
	 * @param string|null $sort_by Ticket attribute to sort by, e.g. created_at
	 * @param string|null $order_by Sort direction, asc or desc
	 * @return DataResponse<Http::STATUS_OK, ZammadTicketList, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_PRECONDITION_FAILED|Http::STATUS_BAD_GATEWAY, ZammadError, array{}>
	 *
	 * 200: Tickets returned
	 * 400: Invalid parameters, or Zammad could not run the query
	 * 403: The user's Zammad account is not allowed to search tickets
	 * 412: The user is not connected to Zammad
	 * 502: Zammad could not be reached or answered with another error
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
		if ($mine) {
			// a condition, not query syntax: Zammad applies conditions with and without Elasticsearch
			$params['condition[ticket.owner_id][operator]'] = 'is';
			$params['condition[ticket.owner_id][value]'] = $zammadUserId;
		}
		if ($sort_by !== null && $sort_by !== '') {
			$params['sort_by'] = $sort_by;
		}
		if ($order_by !== null && $order_by !== '') {
			$params['order_by'] = strtolower($order_by);
		}

		try {
			$result = $this->zammadAPIService->request((string)$this->userId, 'tickets/search', $params);
			if (isset($result['error'])) {
				return $this->searchError($result);
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
	 * The articles come as plain text, emails without quoted earlier messages: the newest ones, newest last.
	 * `older_articles` counts the articles before them; to read those, call again with `before` set to the ID of the
	 * first article returned. Attachments are listed, not downloaded.
	 *
	 * @param string $ticket Ticket ID, or a ticket link of the user's Zammad server
	 * @param int<1, 50> $limit Number of articles
	 * @param int|null $before Only articles before the one with this ID
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
	public function getTicket(string $ticket, int $limit = 10, ?int $before = null): DataResponse {
		$zammadUrl = $this->getZammadUrl();
		if ($zammadUrl === null) {
			return $this->notConnected();
		}
		$parsed = TicketText::parseTicketReference($zammadUrl, $ticket);
		$ticketId = $parsed['id'] ?? null;
		if ($ticketId === null) {
			return $this->invalidTicket($zammadUrl, $ticket);
		}

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
				return $this->zammadError($response, $ticketId);
			}
		}

		[$articleList, $olderArticles] = self::articles($articles, max(1, min($limit, self::ARTICLES_MAX_LIMIT)), $before);
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
			'last_contact_customer_at' => $entry['last_contact_customer_at'],
			'last_contact_agent_at' => $entry['last_contact_agent_at'],
			'escalation_at' => $entry['escalation_at'],
			'url' => $zammadUrl . '/#ticket/zoom/' . $ticketId,
			'article_count' => count(array_filter($articles, 'is_array')),
			'older_articles' => $olderArticles,
			'articles' => $articleList,
		]);
	}

	/**
	 * Add an internal note to a ticket
	 *
	 * The note is written with the user's own Zammad token, so it shows the user as its author, and Zammad
	 * decides whether the user may add notes to the ticket. The note is plain text and is stored as given.
	 *
	 * @param string $ticket Ticket ID or ticket link
	 * @param string $text Text of the note, plain text
	 * @param string|null $ticket_number Number of the ticket the note is meant for. The note is refused if the ticket has another number
	 * @return DataResponse<Http::STATUS_CREATED, ZammadNote, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_PRECONDITION_FAILED|Http::STATUS_BAD_GATEWAY, ZammadError, array{}>
	 *
	 * 201: Note added
	 * 400: The text is empty or too long, the ticket is not an ID or a link, or the ticket number does not match
	 * 404: The ticket does not exist, or the user may not add notes to it
	 * 412: The user is not connected to Zammad
	 * 502: Zammad could not be reached or answered with another error
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/ticket/notes')]
	public function addNote(string $ticket, string $text, ?string $ticket_number = null): DataResponse {
		$zammadUrl = $this->getZammadUrl();
		if ($zammadUrl === null) {
			return $this->notConnected();
		}
		$ticketId = TicketText::parseTicketReference($zammadUrl, $ticket)['id'] ?? null;
		if ($ticketId === null) {
			return $this->invalidTicket($zammadUrl, $ticket);
		}
		$text = trim($text);
		if ($text === '' || mb_strlen($text) > self::NOTE_MAX_CHARS) {
			return self::error(Http::STATUS_BAD_REQUEST, 'invalid_parameter',
				$this->l10n->t('The note has to have between 1 and %s characters.', [(string)self::NOTE_MAX_CHARS]));
		}

		try {
			$info = $this->zammadAPIService->request((string)$this->userId, 'tickets/' . $ticketId);
			if (isset($info['error'])) {
				return $this->zammadError($info, $ticketId);
			}
			$number = (string)($info['number'] ?? '');
			$title = (string)($info['title'] ?? '');
			$expected = ltrim(trim((string)$ticket_number), '#');
			if ($expected !== '' && $expected !== $number) {
				return self::error(Http::STATUS_BAD_REQUEST, 'ticket_mismatch',
					$this->l10n->t('Ticket %1$s is number %2$s ("%3$s"), not number %4$s. Check that the note goes to the right ticket.',
						[(string)$ticketId, $number, $title, $expected]));
			}
			$article = $this->zammadAPIService->request((string)$this->userId, 'ticket_articles', [
				'ticket_id' => $ticketId,
				'body' => $text,
				'content_type' => 'text/plain',
				'type' => 'note',
				'internal' => true,
			], 'POST');
		} catch (Exception $e) {
			return $this->unreachable($e);
		}
		if (isset($article['error'])) {
			return $this->zammadError($article, $ticketId);
		}
		$articleId = (int)($article['id'] ?? 0);
		return new DataResponse([
			'id' => $articleId,
			'ticket_id' => $ticketId,
			'number' => $number,
			'title' => $title,
			'created_at' => self::stringOrNull($article['created_at'] ?? null),
			'url' => $zammadUrl . '/#ticket/zoom/' . $ticketId . ($articleId > 0 ? '/' . $articleId : ''),
		], Http::STATUS_CREATED);
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
	 * The newest $limit articles, of those before the article with ID $before if given, newest last
	 *
	 * @return array{0: list<ZammadArticle>, 1: int} the articles and the number of older ones
	 */
	private static function articles(array $articles, int $limit, ?int $before): array {
		$articles = array_values(array_filter($articles,
			static fn (mixed $article): bool => is_array($article) && ($before === null || (int)($article['id'] ?? 0) < $before)));
		$page = array_slice($articles, -$limit);
		$kept = [];
		foreach ($page as $article) {
			$body = (string)($article['body'] ?? '');
			$contentType = (string)($article['content_type'] ?? '');
			// only mails carry quoted history; a note, phone or chat article is kept whole
			$text = ($article['type'] ?? null) === 'email'
				? TicketText::articleText($body, $contentType)
				: TicketText::articleBodyToText($body, $contentType);
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
		return [$kept, count($articles) - count($page)];
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
	 * @return DataResponse<Http::STATUS_BAD_REQUEST, ZammadError, array{}>
	 */
	private function invalidTicket(string $zammadUrl, string $ticket): DataResponse {
		return TicketText::parseTicketReference($zammadUrl, $ticket)['error'] === 'wrong_host'
			? self::error(Http::STATUS_BAD_REQUEST, 'wrong_host',
				$this->l10n->t('This ticket link points to a different Zammad server than the one your account is connected to (%s).', [$zammadUrl]))
			: self::error(Http::STATUS_BAD_REQUEST, 'invalid_ticket',
				$this->l10n->t('Give a ticket ID or a ticket link like %s/#ticket/zoom/123.', [$zammadUrl]));
	}

	/**
	 * The answer to an error of Zammad for a request about one ticket
	 *
	 * @param array $response a Zammad response with an 'error' key
	 * @return DataResponse<Http::STATUS_NOT_FOUND|Http::STATUS_PRECONDITION_FAILED|Http::STATUS_BAD_GATEWAY, ZammadError, array{}>
	 */
	private function zammadError(array $response, int $ticketId): DataResponse {
		return match ($response['error-code'] ?? null) {
			Http::STATUS_UNAUTHORIZED => $this->badCredentials(),
			// Zammad answers 403 for tickets the user cannot see, which is the same as not existing for them
			Http::STATUS_FORBIDDEN, Http::STATUS_NOT_FOUND => self::error(Http::STATUS_NOT_FOUND, 'not_found',
				$this->l10n->t('Ticket %s does not exist, or your Zammad account has no access to it.', [(string)$ticketId])),
			default => $this->otherError($response),
		};
	}

	/**
	 * The answer to an error of Zammad for a ticket search
	 *
	 * @param array $response a Zammad response with an 'error' key
	 * @return DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_PRECONDITION_FAILED|Http::STATUS_BAD_GATEWAY, ZammadError, array{}>
	 */
	private function searchError(array $response): DataResponse {
		return match ($response['error-code'] ?? null) {
			Http::STATUS_UNAUTHORIZED => $this->badCredentials(),
			Http::STATUS_FORBIDDEN => self::error(Http::STATUS_FORBIDDEN, 'forbidden',
				$this->l10n->t('Your Zammad account is not allowed to search tickets.')),
			// Zammad refuses a search it cannot run with 422, e.g. for an unknown sort_by attribute
			Http::STATUS_BAD_REQUEST, Http::STATUS_UNPROCESSABLE_ENTITY => self::error(Http::STATUS_BAD_REQUEST, 'search_failed',
				$this->l10n->t('Zammad could not run this search. Check the query and sort_by.')),
			default => $this->otherError($response),
		};
	}

	/**
	 * @param array $response a Zammad response with an 'error' key
	 * @return DataResponse<Http::STATUS_BAD_GATEWAY, ZammadError, array{}>
	 */
	private function otherError(array $response): DataResponse {
		$code = $response['error-code'] ?? null;
		return self::error(Http::STATUS_BAD_GATEWAY, 'zammad_error', is_int($code)
			? $this->l10n->t('Zammad answered with error %s.', [(string)$code])
			: $this->l10n->t('Zammad could not be reached.'));
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
