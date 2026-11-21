<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Composer autoloader. When the suite runs inside a full Nextcloud checkout,
// the server bootstrap is loaded first so the OC_* helpers exist.
$serverBootstrap = __DIR__ . '/../../../tests/bootstrap.php';
if (file_exists($serverBootstrap) === true) {
	require_once $serverBootstrap;
}

require_once __DIR__ . '/../vendor/autoload.php';

// The OCP interfaces reference OC\Hooks\Emitter, which only exists in a full
// Nextcloud checkout. Load the stub so tests can mock those interfaces.
require_once __DIR__ . '/stubs/oc_hooks_emitter.php';
