<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zammad\Service;

use DOMDocument;

/**
 * Text handling for ticket content, shared by the Context Chat import, the link
 * previews and the ticket API.
 */
class TicketText {

	/** Bodies larger than this are only converted to text, as parsing them costs too much */
	public const MAX_CLEANUP_BYTES = 1024 * 1024;

	/**
	 * Zammad's own mark in the HTML body of an incoming mail: everything after it is
	 * the sender's signature and the quoted earlier messages.
	 */
	private const SIGNATURE_MARKER = '<span class="js-signatureMarker">';

	/**
	 * Turn the HTML body of an article into plain text.
	 *
	 * strip_tags() alone keeps the content of style and script elements and leaves
	 * no whitespace between block elements, so the last word of a paragraph and the
	 * first word of the next one would be glued into one.
	 */
	public static function htmlToText(string $html): string {
		// these carry no text of the article, tags and content alike
		$text = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;
		// the line structure the markup carries, before the markup itself is gone
		$text = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6]|/table|/blockquote)\b[^>]*>#i', "\n", $text) ?? $text;
		// neighbouring cells of a row belong on one line, but not in one word
		$text = preg_replace('#</(td|th)\b[^>]*>#i', ' ', $text) ?? $text;
		$text = strip_tags($text);
		// decoded only once the tags are gone, so that an escaped tag in the article
		// body stays the text the author wrote instead of turning into markup that
		// strip_tags() would have taken out
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		// whatever whitespace the markup was laid out with, collapsed, while the line
		// breaks that stand for a block of their own are kept
		$text = preg_replace('#[^\S\n]+#u', ' ', $text) ?? $text;
		$text = preg_replace('#[^\S\n]*\n[^\S\n]*#u', "\n", $text) ?? $text;
		$text = preg_replace('#\n{3,}#', "\n\n", $text) ?? $text;
		return trim($text);
	}

	/**
	 * The ticket ID and the rest of a ticket link of the given Zammad server.
	 *
	 * @return array{0: string, 1: string}|null [ticket ID, what follows it], or null if the URL is no ticket link of that server
	 */
	public static function getLinkParts(string $zammadUrl, string $url): ?array {
		preg_match('/^' . preg_quote($zammadUrl, '/') . '\/#ticket\/zoom\/([0-9]+)(.*$)/', $url, $matches);
		return count($matches) > 2 ? [$matches[1], $matches[2]] : null;
	}

	/**
	 * The ticket ID a user gave, as a numeric ID or as a ticket link.
	 *
	 * A link has to point to the user's own Zammad server. A link to another server
	 * is refused rather than guessed at, as its ticket ID means nothing on ours.
	 *
	 * @return array{id: int}|array{error: 'wrong_host'|'invalid'}
	 */
	public static function parseTicketReference(string $zammadUrl, string $ticket): array {
		$ticket = trim($ticket);
		if (preg_match('/^[0-9]+$/', $ticket) === 1 && (int)$ticket > 0) {
			return ['id' => (int)$ticket];
		}
		$zammadUrl = rtrim(trim($zammadUrl), '/');
		if ($zammadUrl !== '') {
			$parts = self::getLinkParts($zammadUrl, $ticket);
			if ($parts !== null) {
				return ['id' => (int)$parts[0]];
			}
		}
		if (preg_match('/^https?:\/\/[^\s]+\/#ticket\/zoom\/[0-9]+/i', $ticket) === 1) {
			return ['error' => 'wrong_host'];
		}
		return ['error' => 'invalid'];
	}

	/**
	 * What an email says itself: plain text without the signature and the quoted
	 * earlier messages. For email articles only; other articles are kept whole.
	 *
	 * The HTML body is cut at Zammad's signature marker, then blockquote elements are
	 * removed from what is left: mail clients put the quoted thread into them, and a
	 * quote can start before the marker. Plain-text mails carry no marker and are
	 * kept as they are. A message that would come out empty, such as a forward, keeps
	 * all its text.
	 *
	 * Known limit: in some Outlook-style replies Zammad puts the marker well after the
	 * quote header, so part of the quoted mail stays in the text. The quote header
	 * line itself ("On ... wrote:") before the marker always stays.
	 */
	public static function articleText(string $body, string $contentType): string {
		if (stripos($contentType, 'html') === false || strlen($body) > self::MAX_CLEANUP_BYTES) {
			return self::articleBodyToText($body, $contentType);
		}
		$marker = strpos($body, self::SIGNATURE_MARKER);
		$html = self::removeBlockquotes($marker === false ? $body : substr($body, 0, $marker));
		$text = self::htmlToText($html);
		return $text !== '' ? $text : self::htmlToText($body);
	}

	/**
	 * The HTML without its blockquote elements, nested ones included.
	 */
	private static function removeBlockquotes(string $html): string {
		if (stripos($html, '<blockquote') === false) {
			return $html;
		}
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		// the encoding declaration keeps libxml from reading UTF-8 as Latin-1
		$loaded = $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;
		if ($body === null) {
			return $html;
		}
		$quotes = iterator_to_array($document->getElementsByTagName('blockquote'));
		foreach ($quotes as $quote) {
			$quote->parentNode?->removeChild($quote);
		}
		$result = '';
		foreach ($body->childNodes as $child) {
			$result .= $document->saveHTML($child);
		}
		return $result;
	}

	/**
	 * The plain text of an article body, whatever its content type.
	 */
	public static function articleBodyToText(string $body, string $contentType): string {
		if (stripos($contentType, 'html') !== false) {
			return self::htmlToText($body);
		}
		return trim($body);
	}
}
