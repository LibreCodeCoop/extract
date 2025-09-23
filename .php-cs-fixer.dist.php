<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once './vendor-bin/coding-standard/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$config = new Config();
$config
	->setParallelConfig(ParallelConfigFactory::detect())
	->getFinder()
	->ignoreVCSIgnored(true)
	->notPath('js')
	->notPath('l10n')
	->notPath('src')
	->notPath('vendor')
	->notPath('vendor-bin')
	->notPath('build')
	->notPath('node_modules')
	->in(__DIR__);
return $config;
