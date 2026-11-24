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
		]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params = []) => vsprintf($text, $params));
		$this->controller = new TicketApiController('integration_zammad', $this->createMock(IRequest::class), $this->api,
			$this->userConfig, $l10n, $this->createMock(LoggerInterface::class), 'alice');
	}

	public function testListPassesTheQueryAndMapsNames(): void {
		$this->api->expects($this->once())->method('request')
			->with('alice', 'tickets/search', $this->callback(fn (array $p) => $p['query'] === 'state.name:open' && $p['limit'] === 20
				&& $p['condition[ticket.owner_id][operator]'] === 'is' && $p['condition[ticket.owner_id][value]'] === '7'))
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
			['error' => 'Bad request', 'error-code' => 400],
			['error' => 'Forbidden', 'error-code' => 403],
			['error' => 'Bad credentials', 'error-code' => 401],
			['error' => 'Not found', 'error-code' => 404],
			['error' => 'Server error', 'error-code' => 500],
			['error' => 'cURL error 28: timeout'],
		);
		$results = [];
		for ($i = 0; $i < 7; $i++) {
			$response = $this->controller->listTickets('state.name:open', sort_by: 'created_at');
			$results[] = [$response->getStatus(), $response->getData()['error']];
		}
		$this->assertSame([
			[400, 'search_failed'],
			[400, 'search_failed'],
			[403, 'forbidden'],
			[412, 'bad_credentials'],
			[502, 'zammad_error'],
			[502, 'zammad_error'],
			[502, 'zammad_error'],
		], $results);
	}

	public function testListRefusesBadSortParameters(): void {
		$this->assertSame(400, $this->controller->listTickets('', false, 10, 'created_at; drop')->getStatus());
		$this->assertSame(400, $this->controller->listTickets('', false, 10, 'created_at', 'sideways')->getStatus());
	}

	public function testTicketKeepsInternalNotesAndReturnsTheNewestArticles(): void {
		$this->api->method('request')->with('alice', 'tickets/42', ['full' => 'true'])->willReturn(['id' => 42, 'assets' => [
			'Ticket' => [42 => ['id' => 42, 'number' => '31042', 'title' => 'Sync', 'state_id' => 2, 'owner_id' => 1, 'customer_id' => 9, 'organization_id' => 3,
				'last_contact_customer_at' => '2026-06-01T10:00:00.000Z']],
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

		$data = $this->controller->getTicket(self::ZAMMAD . '/#ticket/zoom/42', 3)->getData();

		$this->assertSame([42, 4, 1], [$data['id'], $data['article_count'], $data['older_articles']]);
		$this->assertSame([2, 3, 4], array_column($data['articles'], 'id'));
		$this->assertSame(['Proxy?', true], [$data['articles'][0]['text'], $data['articles'][0]['internal']]);
		$this->assertSame(['Still broken.', [['filename' => 'log.txt', 'size' => 12]]], [$data['articles'][1]['text'], $data['articles'][1]['attachments']]);
		$this->assertSame("Config:\n> proxy on\n--\nend", $data['articles'][2]['text']);
		// the older ones are read with before, and long articles come whole
		$older = $this->controller->getTicket('42', 3, 2)->getData();
		$this->assertSame([[1], 0], [array_column($older['articles'], 'id'), $older['older_articles']]);
		$this->assertSame(trim(str_repeat('old ', 2000)), $older['articles'][0]['text']);
		$this->assertSame(['sync'], $data['tags']);
		// names the same way a list gives them
		$this->assertSame(['31042', 'open', null, 'Eve Customer', 'Acme'], [$data['number'], $data['state'], $data['owner'], $data['customer'], $data['organization']]);
		// the Assistant's check for an earlier note compares with the customer's last message
		$this->assertSame(['2026-06-01T10:00:00.000Z', null, null], [$data['last_contact_customer_at'], $data['last_contact_agent_at'], $data['escalation_at']]);
	}

	public function testTicketArticleLimit(): void {
		$this->api->method('request')->willReturn(['id' => 42, 'assets' => []]);
		$this->api->method('getTickettags')->willReturn(['tags' => []]);
		$this->api->method('getArticlesByTicket')->willReturn(array_map(fn (int $id) => ['id' => $id, 'body' => 'a'], range(1, 60)));

		$pages = array_map(function (array $args) {
			$data = $this->controller->getTicket('42', ...$args)->getData();
			return [count($data['articles']), $data['articles'][0]['id'], $data['older_articles']];
		}, [[], [500], [0], [10, 20]]);

		$this->assertSame([[10, 51, 50], [50, 11, 10], [1, 60, 59], [10, 10, 9]], $pages);
	}

	public function testTicketErrors(): void {
		$this->api->method('request')->willReturnOnConsecutiveCalls(
			['error' => 'Not found', 'error-code' => 404],
			['error' => 'Forbidden', 'error-code' => 403],
			['error' => 'Server error', 'error-code' => 500],
		);
		$this->assertSame(404, $this->controller->getTicket('42')->getStatus());
		$this->assertSame(404, $this->controller->getTicket('42')->getStatus());
		$this->assertSame([502, 'zammad_error'], [($r = $this->controller->getTicket('42'))->getStatus(), $r->getData()['error']]);
		$this->assertSame('wrong_host', $this->controller->getTicket('https://other.example.com/#ticket/zoom/42')->getData()['error']);
		$this->assertSame(400, $this->controller->getTicket('hello')->getStatus());
	}

	public function testNotConnected(): void {
		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('getValueString')->willReturn('');
		$controller = new TicketApiController('integration_zammad', $this->createMock(IRequest::class), $this->api,
			$userConfig, $this->createMock(IL10N::class), $this->createMock(LoggerInterface::class), 'alice');
		$this->assertSame(412, $controller->listTickets()->getStatus());
		$this->assertSame(412, $controller->getTicket('42')->getStatus());
		$this->assertSame(412, $controller->addNote('42', 'Text')->getStatus());
	}

	private function ticketForNote(): void {
		$this->api->method('request')->willReturnCallback(function (string $userId, string $endPoint, array $params = [], string $method = 'GET') {
			if ($method === 'POST') {
				$this->assertSame(['ticket_articles', 42, 'note', true, 'text/plain'], [$endPoint, $params['ticket_id'], $params['type'], $params['internal'], $params['content_type']]);
				$this->writtenBody = $params['body'];
				return ['id' => 7, 'created_at' => '2026-06-02T10:00:00Z'];
			}
			return ['id' => 42, 'number' => '31042', 'title' => 'Sync'];
		});
	}

	public function testNoteIsStoredAsGiven(): void {
		$this->ticketForNote();
		$response = $this->controller->addNote('42', "  Proxy blocks sync.\n- check the settings\n\n---\nSigned by someone\n", '#31042');
		$this->assertSame(201, $response->getStatus());
		$this->assertSame(self::ZAMMAD . '/#ticket/zoom/42/7', $response->getData()['url']);
		// no footer added or removed, only the surrounding whitespace trimmed
		$this->assertSame("Proxy blocks sync.\n- check the settings\n\n---\nSigned by someone", $this->writtenBody);
	}

	public function testNoteForAnotherTicketNumberIsRefused(): void {
		$this->ticketForNote();
		$response = $this->controller->addNote('42', 'Text', '31043');
		$this->assertSame([400, 'ticket_mismatch'], [$response->getStatus(), $response->getData()['error']]);
		$this->assertSame('', $this->writtenBody);
	}

	public function testNoteErrors(): void {
		$this->api->method('request')->willReturnOnConsecutiveCalls(
			['error' => 'Forbidden', 'error-code' => 403],
			['id' => 42, 'number' => '31042', 'title' => 'Sync'],
			['error' => 'Unprocessable', 'error-code' => 422],
		);
		$this->assertSame(404, $this->controller->addNote('42', 'Text')->getStatus());
		$this->assertSame([502, 'zammad_error'], [($r = $this->controller->addNote('42', 'Text'))->getStatus(), $r->getData()['error']]);
	}

	public function testEmptyOrTooLongNotesAreRefused(): void {
		$this->assertSame(400, $this->controller->addNote('42', "  \n ")->getStatus());
		$this->assertSame(400, $this->controller->addNote('42', str_repeat('a', TicketApiController::NOTE_MAX_CHARS + 1))->getStatus());
	}
}
