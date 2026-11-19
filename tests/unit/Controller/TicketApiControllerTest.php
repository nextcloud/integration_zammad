<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zammad\Tests\Controller;

use OCA\Zammad\Controller\TicketApiController;
use OCA\Zammad\Service\ZammadAPIService;
use OCP\Config\IUserConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TicketApiControllerTest extends TestCase {

	private const ZAMMAD = 'https://zammad.example.com';

	private ZammadAPIService&MockObject $api;
	private IUserConfig&MockObject $userConfig;
	private TicketApiController $controller;
	private string $writtenBody = '';

	protected function setUp(): void {
		$this->api = $this->createMock(ZammadAPIService::class);
		$this->api->method('getZammadUrl')->willReturn(self::ZAMMAD . '/');
		$this->api->method('getTicketStateNames')->willReturn([2 => 'open']);
		$this->api->method('getPriorityNames')->willReturn([3 => '3 high']);
		$this->userConfig = $this->createMock(IUserConfig::class);
		$this->userConfig->method('getValueString')->willReturnMap([
			['alice', 'integration_zammad', 'token', '', true, 'secret'],
			['alice', 'integration_zammad', 'user_id', '', true, '7'],
			['alice', 'integration_zammad', 'user_name', '', true, 'Bob Agent'],
		]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params = []) => vsprintf($text, $params));
		$this->controller = new TicketApiController('integration_zammad', $this->createMock(IRequest::class), $this->api,
			$this->userConfig, $this->createMock(IUserManager::class), $l10n, $this->createMock(LoggerInterface::class), 'alice');
	}

	public function testListPassesTheQueryAndMapsNames(): void {
		$this->api->expects($this->once())->method('request')
			->with('alice', 'tickets/search', $this->callback(fn (array $p) => $p['query'] === '(state.name:open) AND owner_id:7' && $p['limit'] === 20))
			->willReturn([
				'tickets' => [5],
				'assets' => [
					'Ticket' => [5 => ['id' => 5, 'number' => '31005', 'title' => 'Sync', 'state_id' => 2, 'priority_id' => 3,
						'group_id' => 1, 'owner_id' => 1, 'customer_id' => 9, 'escalation_at' => '2026-06-01T10:00:00Z']],
					'User' => [9 => ['firstname' => 'Eve', 'lastname' => 'Customer']],
				],
			]);

		$response = $this->controller->listTickets('state.name:open', true, 50);

		$this->assertSame(200, $response->getStatus());
		$ticket = $response->getData()['tickets'][0];
		$this->assertSame(['open', '3 high', null, 'Eve Customer', '2026-06-01T10:00:00Z', self::ZAMMAD . '/#ticket/zoom/5'],
			[$ticket['state'], $ticket['priority'], $ticket['owner'], $ticket['customer'], $ticket['escalation_at'], $ticket['url']]);
	}

	public function testSearchErrors(): void {
		$this->api->method('request')->willReturnOnConsecutiveCalls(
			['error' => 'Unable to process query', 'error-code' => 422],
			['error' => 'Server error', 'error-code' => 500],
			['error' => 'cURL error 28: timeout'],
		);
		$this->assertSame([400, 'search_failed'], [($r = $this->controller->listTickets('state.name:(open'))->getStatus(), $r->getData()['error']]);
		$this->assertSame([502, 'zammad_error'], [($r = $this->controller->listTickets('state.name:open'))->getStatus(), $r->getData()['error']]);
		$this->assertSame(502, $this->controller->listTickets('state.name:open')->getStatus());
	}

	public function testListRefusesBadSortParameters(): void {
		$this->assertSame(400, $this->controller->listTickets('', false, 10, 'created_at; drop')->getStatus());
		$this->assertSame(400, $this->controller->listTickets('', false, 10, 'created_at', 'sideways')->getStatus());
	}

	public function testTicketKeepsInternalNotesAndDropsTheOldestArticles(): void {
		$this->api->method('request')->with('alice', 'tickets/42', ['full' => 'true'])->willReturn(['id' => 42, 'assets' => [
			'Ticket' => [42 => ['id' => 42, 'number' => '31042', 'title' => 'Sync', 'state_id' => 2, 'owner_id' => 1, 'customer_id' => 9, 'organization_id' => 3]],
			'User' => [9 => ['firstname' => 'Eve', 'lastname' => 'Customer', 'email' => 'eve@example.com']],
			'Organization' => [3 => ['name' => 'Acme']],
		]]);
		$this->api->method('getTickettags')->willReturn(['tags' => ['sync']]);
		$this->api->method('getArticlesByTicket')->willReturn([
			['id' => 1, 'sender' => 'Customer', 'content_type' => 'text/plain', 'body' => str_repeat('old ', 2000)],
			['id' => 2, 'sender' => 'Agent', 'internal' => true, 'content_type' => 'text/html', 'body' => '<p>Proxy?</p>'],
			['id' => 3, 'type' => 'email', 'sender' => 'Customer', 'content_type' => 'text/html', 'body' => '<p>Still broken.</p><span class="js-signatureMarker"></span><blockquote><p>quoted</p></blockquote>', 'attachments' => [['filename' => 'log.txt', 'size' => 12]]],
			// a note is kept whole, quote-like lines included
			['id' => 4, 'type' => 'note', 'sender' => 'Agent', 'internal' => true, 'content_type' => 'text/plain', 'body' => "Config:\n> proxy on\n--\nend"],
		]);

		$data = $this->controller->getTicket(self::ZAMMAD . '/#ticket/zoom/42', 2000)->getData();

		$this->assertSame([42, 4, true], [$data['id'], $data['article_count'], $data['truncated']]);
		$this->assertSame([1, 2, 3, 4], array_column($data['articles'], 'id'));
		$this->assertStringEndsWith('[…]', $data['articles'][0]['text']);
		$this->assertSame(['Proxy?', true], [$data['articles'][1]['text'], $data['articles'][1]['internal']]);
		$this->assertSame(['Still broken.', [['filename' => 'log.txt', 'size' => 12]]], [$data['articles'][2]['text'], $data['articles'][2]['attachments']]);
		$this->assertSame("Config:\n> proxy on\n--\nend", $data['articles'][3]['text']);
		$this->assertSame(['sync'], $data['tags']);
		$this->assertSame([false, false, false, false], array_column($data['articles'], 'assistant_note'));
		// names the same way a list gives them
		$this->assertSame(['31042', 'open', null, 'Eve Customer', 'Acme'], [$data['number'], $data['state'], $data['owner'], $data['customer'], $data['organization']]);
	}

	public function testTicketErrors(): void {
		$this->api->method('request')->willReturn(['error' => 'Not found', 'error-code' => 404]);
		$this->assertSame(404, $this->controller->getTicket('42')->getStatus());
		$this->assertSame('wrong_host', $this->controller->getTicket('https://other.example.com/#ticket/zoom/42')->getData()['error']);
		$this->assertSame(400, $this->controller->getTicket('hello')->getStatus());
	}

	public function testNotConnected(): void {
		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('getValueString')->willReturn('');
		$controller = new TicketApiController('integration_zammad', $this->createMock(IRequest::class), $this->api,
			$userConfig, $this->createMock(IUserManager::class), $this->createMock(IL10N::class), $this->createMock(LoggerInterface::class), 'alice');
		$this->assertSame(412, $controller->listTickets()->getStatus());
		$this->assertSame(412, $controller->getTicket('42')->getStatus());
		$this->assertSame(412, $controller->addNote('42', 'Text')->getStatus());
	}

	/**
	 * @param list<array> $articles
	 */
	private function ticketForNote(array $articles, ?string $lastCustomerAt = '2026-06-01T10:00:00Z'): void {
		$this->api->method('request')->willReturnCallback(function (string $userId, string $endPoint, array $params = [], string $method = 'GET') use ($lastCustomerAt) {
			if ($method === 'POST') {
				$this->assertSame(['ticket_articles', 42, 'note', true, 'text/plain'], [$endPoint, $params['ticket_id'], $params['type'], $params['internal'], $params['content_type']]);
				$this->writtenBody = $params['body'];
				return ['id' => 7, 'created_at' => '2026-06-02T10:00:00Z'];
			}
			return ['id' => 42, 'number' => '31042', 'title' => 'Sync', 'last_contact_customer_at' => $lastCustomerAt];
		});
		$this->api->method('getArticlesByTicket')->willReturn($articles);
	}

	private static function assistantNote(string $createdAt): array {
		return ['internal' => true, 'type' => 'note', 'sender' => 'Agent', 'content_type' => 'text/plain', 'created_at' => $createdAt,
			'body' => "Summary\n\n---\nNextcloud Assistant for Bob Agent"];
	}

	public function testNoteGetsExactlyOneFooter(): void {
		$this->ticketForNote([]);
		$response = $this->controller->addNote('42', "Proxy blocks sync.\n- check the settings\n\n---\nNextcloud Assistant for Somebody", '#31042');
		$this->assertSame(201, $response->getStatus());
		$this->assertSame(self::ZAMMAD . '/#ticket/zoom/42/7', $response->getData()['url']);
		$body = $this->writtenBody;
		$this->assertSame(1, substr_count($body, 'Nextcloud Assistant for'));
		// the text as given, then the footer block
		$this->assertSame("Proxy blocks sync.\n- check the settings\n\n---\nNextcloud Assistant for Bob Agent", $body);
	}

	public function testNoteForAnotherTicketNumberIsRefused(): void {
		$this->ticketForNote([]);
		$response = $this->controller->addNote('42', 'Text', '31043');
		$this->assertSame([400, 'ticket_mismatch'], [$response->getStatus(), $response->getData()['error']]);
	}

	public function testSecondNoteWithoutNewCustomerMessageIsRefused(): void {
		$this->ticketForNote([self::assistantNote('2026-06-01T11:00:00Z')]);
		$response = $this->controller->addNote('42', 'Text');
		$this->assertSame([409, 'already_noted'], [$response->getStatus(), $response->getData()['error']]);
		$this->assertSame(201, $this->controller->addNote('42', 'Text', null, true)->getStatus());
	}

	public function testTicketMarksEarlierAssistantNotes(): void {
		$this->api->method('request')->willReturn(['id' => 42, 'assets' => ['Ticket' => [42 => ['id' => 42]]]]);
		$note = self::assistantNote('2026-06-01T09:00:00Z');
		$this->api->method('getArticlesByTicket')->willReturn([
			$note,
			// the phrase in the middle of a note
			['internal' => true, 'type' => 'note', 'sender' => 'Agent', 'content_type' => 'text/plain', 'body' => "Mentions Nextcloud Assistant for Bob Agent\nin the middle."],
			// a customer quoting the footer in a public email
			['internal' => false, 'type' => 'email', 'sender' => 'Customer', 'content_type' => 'text/plain', 'body' => "Customer quoting\n---\nNextcloud Assistant for Bob Agent"],
			// a public article by an agent with the whole footer block
			['internal' => false] + $note,
			// the footer line without the --- line
			['body' => "Summary\n\nNextcloud Assistant for Bob Agent"] + $note,
			// an internal note with the footer, but written by the system
			['sender' => 'System'] + $note,
		]);
		$data = $this->controller->getTicket('42')->getData();
		$this->assertSame([true, false, false, false, false, false], array_column($data['articles'], 'assistant_note'));
	}

	public function testNoteAfterANewCustomerMessage(): void {
		$this->ticketForNote([self::assistantNote('2026-06-01T09:00:00Z')]);
		$this->assertSame(201, $this->controller->addNote('42', 'Text')->getStatus());
	}

	public function testEmptyOrTooLongNotesAreRefused(): void {
		$this->assertSame(400, $this->controller->addNote('42', "---\nNextcloud Assistant for Bob")->getStatus());
		$this->assertSame(400, $this->controller->addNote('42', str_repeat('a', TicketApiController::NOTE_MAX_CHARS + 1))->getStatus());
	}
}
