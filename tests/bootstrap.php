<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

// The app has to sit in the apps/ folder of a server checkout, as in
// .github/workflows/phpunit-sqlite.yml
require_once __DIR__ . '/../../../tests/bootstrap.php';

\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('integration_zammad');
