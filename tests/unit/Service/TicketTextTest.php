<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zammad\Tests\Service;

use OCA\Zammad\Service\TicketText;
use PHPUnit\Framework\TestCase;

class TicketTextTest extends TestCase {

	public function testHtmlToTextKeepsLineStructure(): void {
		$html = '<html><head><style>p { color: red; }</style></head><body><p>Hello&nbsp;there</p><p>Second &amp; last</p></body></html>';
		$this->assertSame("Hello there\nSecond & last", TicketText::htmlToText($html));
	}

	public function testParseTicketReferenceAcceptsIdsAndLinks(): void {
		$url = 'https://zammad.example.com';
		$this->assertSame(['id' => 42], TicketText::parseTicketReference($url, '42'));
		$this->assertSame(['id' => 42], TicketText::parseTicketReference($url, ' 42 '));
		$this->assertSame(['id' => 42], TicketText::parseTicketReference($url, 'https://zammad.example.com/#ticket/zoom/42'));
		$this->assertSame(['id' => 42], TicketText::parseTicketReference($url . '/', 'https://zammad.example.com/#ticket/zoom/42/1337'));
	}

	public function testParseTicketReferenceRefusesOtherServers(): void {
		$result = TicketText::parseTicketReference('https://zammad.example.com', 'https://other.example.org/#ticket/zoom/42');
		$this->assertSame(['error' => 'wrong_host'], $result);
	}

	public function testParseTicketReferenceRefusesAnythingElse(): void {
		$url = 'https://zammad.example.com';
		$this->assertSame(['error' => 'invalid'], TicketText::parseTicketReference($url, 'ticket 42'));
		$this->assertSame(['error' => 'invalid'], TicketText::parseTicketReference($url, '0'));
		$this->assertSame(['error' => 'invalid'], TicketText::parseTicketReference($url, ''));
		$this->assertSame(['error' => 'invalid'], TicketText::parseTicketReference($url, 'https://zammad.example.com/#dashboard'));
	}

	public function testGetLinkPartsKeepsTheRest(): void {
		$this->assertSame(['42', '/1337'], TicketText::getLinkParts('https://zammad.example.com', 'https://zammad.example.com/#ticket/zoom/42/1337'));
		$this->assertNull(TicketText::getLinkParts('https://zammad.example.com', 'https://zammad.example.com/#ticket/view'));
	}

	public function testArticleTextCutsAtZammadsSignatureMarker(): void {
		// Outlook style: no blockquote, the marker sits before the sender's signature and the quoted mail
		$html = '<p>Sync works again, thanks.</p><span class="js-signatureMarker"></span><p>Jane Doe</p><hr><div dir="ltr"><b>From:</b> Support</div><p>Did you restart?</p>';
		$this->assertSame('Sync works again, thanks.', TicketText::articleText($html, 'text/html'));
	}

	public function testArticleTextRemovesAQuoteThatStartsBeforeTheMarker(): void {
		$html = '<p>Still failing.</p><p>Jane wrote:</p><blockquote type="cite"><p>Please restart.</p><span class="js-signatureMarker"></span><p>Support team</p></blockquote>';
		$this->assertSame("Still failing.\nJane wrote:", TicketText::articleText($html, 'text/html'));
	}

	public function testArticleTextRemovesNestedQuotesWithoutMarker(): void {
		$html = '<p>Neue Antwort mit Umlauten: äöü</p><blockquote><p>Old</p><blockquote><p>Older</p></blockquote></blockquote><p>After</p>';
		$this->assertSame("Neue Antwort mit Umlauten: äöü\nAfter", TicketText::articleText($html, 'text/html'));
	}

	public function testArticleTextKeepsPlainTextAndQuoteOnlyMails(): void {
		// no marker in plain text, and no line patterns any more
		$plain = "Thanks!\n\nOn Tue, 3 Jun 2025 at 10:12, Jane wrote:\n> Restart?";
		$this->assertSame($plain, TicketText::articleText($plain, 'text/plain'));
		// a forward that is only a quote keeps its text
		$this->assertSame('Please restart.', TicketText::articleText('<blockquote><p>Please restart.</p></blockquote>', 'text/html'));
	}

	public function testArticleTextLeavesHugeBodiesUncleaned(): void {
		$body = '<p>' . str_repeat('a', TicketText::MAX_CLEANUP_BYTES) . '</p><blockquote><p>quoted</p></blockquote>';
		$this->assertStringEndsWith('quoted', TicketText::articleText($body, 'text/html'));
	}

	public function testArticleTextOnAMegabyteIsFast(): void {
		// nested quotes and many lines, close to the size cap
		$html = str_repeat('<p>' . str_repeat('word ', 30) . '</p><blockquote><p>quote</p><blockquote><p>deeper</p></blockquote></blockquote>', 4000);
		$this->assertLessThan(TicketText::MAX_CLEANUP_BYTES, strlen($html));
		$start = microtime(true);
		$text = TicketText::articleText($html, 'text/html');
		// generous: catches quadratic work, not a slow runner
		$this->assertLessThan(5.0, microtime(true) - $start);
		$this->assertStringNotContainsString('deeper', $text);
	}
}
