<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zammad;

/**
 * @psalm-type ZammadError = array{
 *     error: string,
 *     message: string,
 * }
 *
 * @psalm-type ZammadAttachment = array{
 *     filename: string,
 *     size: int,
 * }
 *
 * @psalm-type ZammadArticle = array{
 *     id: int,
 *     from: ?string,
 *     sender: ?string,
 *     type: ?string,
 *     internal: bool,
 *     created_at: ?string,
 *     text: string,
 *     attachments: list<ZammadAttachment>,
 * }
 *
 * @psalm-type ZammadTicket = array{
 *     id: int,
 *     number: string,
 *     title: string,
 *     state: ?string,
 *     priority: ?string,
 *     owner: ?string,
 *     customer: ?string,
 *     organization: ?string,
 *     tags: list<string>,
 *     created_at: ?string,
 *     updated_at: ?string,
 *     last_contact_customer_at: ?string,
 *     last_contact_agent_at: ?string,
 *     escalation_at: ?string,
 *     url: string,
 *     article_count: int,
 *     truncated: bool,
 *     articles: list<ZammadArticle>,
 * }
 *
 * @psalm-type ZammadTicketListEntry = array{
 *     id: int,
 *     number: string,
 *     title: string,
 *     state: ?string,
 *     priority: ?string,
 *     owner: ?string,
 *     customer: ?string,
 *     created_at: ?string,
 *     updated_at: ?string,
 *     last_contact_customer_at: ?string,
 *     last_contact_agent_at: ?string,
 *     escalation_at: ?string,
 *     url: string,
 * }
 *
 * @psalm-type ZammadTicketList = array{
 *     tickets: list<ZammadTicketListEntry>,
 *     count: int,
 * }
 */
class ResponseDefinitions {
}
