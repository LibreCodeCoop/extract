<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The app has no production Composer packages. The bin plugin is development-only;
// a publisher running composer install --no-dev must not install test tools.
if (!is_dir(__DIR__ . '/../vendor/bamarni/composer-bin-plugin')) {
	exit(0);
}

passthru('composer bin all install --ansi', $status);
exit($status);
