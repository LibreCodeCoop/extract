<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Capabilities;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the capabilities the app advertises to clients.
 */
final class CapabilitiesTest extends TestCase {
	private ?IAppManager $appManager = null;

	/**
	 * Set up the mocked app manager.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
	}

	/**
	 * The reported version comes from the app manager.
	 */
	public function testGetCapabilitiesExposesTheAppVersion(): void {
		$this->appManager->expects(self::once())
			->method('getAppVersion')
			->with('extract')
			->willReturn('3.1.0');

		$capabilities = (new Capabilities($this->appManager))->getCapabilities();

		self::assertArrayHasKey('extract', $capabilities);
		self::assertSame('3.1.0', $capabilities['extract']['version']);
	}

	/**
	 * Every advertised feature is listed.
	 */
	public function testGetCapabilitiesListsEverySupportedArchiveFeature(): void {
		$this->appManager->method('getAppVersion')->willReturn('3.1.0');

		$capabilities = (new Capabilities($this->appManager))->getCapabilities();

		self::assertSame(Capabilities::FEATURES, $capabilities['extract']['features']);
		self::assertContains('extract-zip', $capabilities['extract']['features']);
		self::assertContains('extract-rar', $capabilities['extract']['features']);
		self::assertContains('extract-7z', $capabilities['extract']['features']);
	}

	/**
	 * The config key is always present as an array.
	 */
	public function testGetCapabilitiesReturnsAConfigArray(): void {
		$this->appManager->method('getAppVersion')->willReturn('3.1.0');

		$capabilities = (new Capabilities($this->appManager))->getCapabilities();

		self::assertIsArray($capabilities['extract']['config']);
	}
}
