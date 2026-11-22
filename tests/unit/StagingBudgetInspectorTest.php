<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Service\StagingBudgetInspector;
use PHPUnit\Framework\TestCase;

final class StagingBudgetInspectorTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		parent::setUp();
		$this->directory = sys_get_temp_dir() . '/extract-inspector-' . bin2hex(random_bytes(8));
		self::assertTrue(mkdir($this->directory, 0700));
	}

	protected function tearDown(): void {
		if (isset($this->directory) && is_dir($this->directory)) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST,
			);
			foreach ($iterator as $entry) {
				if ($entry->isDir() && !$entry->isLink()) {
					rmdir($entry->getPathname());
				} else {
					unlink($entry->getPathname());
				}
			}
			rmdir($this->directory);
		}
		parent::tearDown();
	}

	public function testAcceptsNestedContentWithinLimits(): void {
		self::assertTrue(mkdir($this->directory . '/nested', 0700));
		self::assertSame(5, file_put_contents($this->directory . '/nested/file', 'hello'));
		self::assertNull((new StagingBudgetInspector($this->directory, 5, 2))->inspect());
	}

	public function testRejectsExcessBytes(): void {
		self::assertSame(6, file_put_contents($this->directory . '/file', '123456'));
		self::assertSame('size', (new StagingBudgetInspector($this->directory, 5, 1))->inspect());
	}

	public function testRejectsExcessEntriesIncludingDirectories(): void {
		self::assertTrue(mkdir($this->directory . '/nested', 0700));
		self::assertSame(1, file_put_contents($this->directory . '/nested/file', 'x'));
		self::assertSame('entries', (new StagingBudgetInspector($this->directory, 100, 1))->inspect());
	}

	public function testRejectsSymlinkWithoutFollowingIt(): void {
		if (!function_exists('symlink')) {
			self::markTestSkipped('Symlinks are not available');
		}
		self::assertTrue(symlink('/etc/passwd', $this->directory . '/link'));
		self::assertSame('symlink', (new StagingBudgetInspector($this->directory, 100, 10))->inspect());
	}

	public function testAbsentOutputDirectoryHasNoContent(): void {
		self::assertNull((new StagingBudgetInspector($this->directory . '/not-yet-created', 100, 5))->inspect());
	}

	public function testRejectsSymlinkUsedAsOutputDirectory(): void {
		if (!function_exists('symlink')) {
			self::markTestSkipped('Symlinks are not available');
		}
		$alias = $this->directory . '/alias';
		self::assertTrue(symlink('/tmp', $alias));
		self::assertSame('symlink', (new StagingBudgetInspector($alias, 100, 10))->inspect());
	}
}
