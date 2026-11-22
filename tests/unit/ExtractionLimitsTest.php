<?php

/**
 * SPDX-FileCopyrightText: 2012-2022 Paul Lereverend <paulereverend@gmail.com>
 * SPDX-FileCopyrightText: 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Service\ExtractionLimits;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class ExtractionLimitsTest extends TestCase {
	public function testInvalidLimitsFallBackToSafeDefaults(): void {
		$config = $this->createStub(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default): string => 'not-an-int');
		$limits = new ExtractionLimits($config);
		self::assertSame(10000, $limits->entryLimit());
		self::assertSame(300, $limits->timeout());
	}

	public function testQuotaCanOnlyReduceTheBudget(): void {
		$config = $this->createStub(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default): string => $default);
		$limits = new ExtractionLimits($config);
		self::assertSame(15, $limits->byteBudget(sys_get_temp_dir() . '/extract-limits-destination', 15));
	}
}
