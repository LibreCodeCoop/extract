<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Service\ResourceLimitedProcess;
use PHPUnit\Framework\TestCase;

final class ResourceLimitedProcessTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		parent::setUp();
		if (!function_exists('proc_open')) {
			self::markTestSkipped('proc_open is not available');
		}
		$this->directory = sys_get_temp_dir() . '/extract-bounded-' . bin2hex(random_bytes(8));
		self::assertTrue(mkdir($this->directory, 0700));
	}

	protected function tearDown(): void {
		if (isset($this->directory) && is_dir($this->directory)) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST,
			);
			foreach ($iterator as $entry) {
				$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
			}
			rmdir($this->directory);
		}
		parent::tearDown();
	}

	public function testAcceptsExtractionBelowLimits(): void {
		$result = ResourceLimitedProcess::run([
			PHP_BINARY, '-r', 'file_put_contents($argv[1] . "/ok.txt", "safe");', $this->directory,
		], $this->directory, 16, 2, 3);
		self::assertSame(['success' => true, 'reason' => ''], $result);
		self::assertSame('safe', file_get_contents($this->directory . '/ok.txt'));
	}

	public function testRejectsTooManyBytesEvenWhenProcessExitsSuccessfully(): void {
		$result = ResourceLimitedProcess::run([
			PHP_BINARY, '-r', 'file_put_contents($argv[1] . "/large.txt", str_repeat("x", 256));', $this->directory,
		], $this->directory, 64, 3, 3);
		self::assertSame(['success' => false, 'reason' => 'size'], $result);
	}

	public function testRejectsTooManyEntries(): void {
		$result = ResourceLimitedProcess::run([
			PHP_BINARY, '-r', 'foreach(range(1, 4) as $i){ file_put_contents($argv[1] . "/" . $i, "a"); }', $this->directory,
		], $this->directory, 50, 2, 3);
		self::assertSame(['success' => false, 'reason' => 'entries'], $result);
	}

	public function testStopsDecoderThatRunsBeyondDeadline(): void {
		$result = ResourceLimitedProcess::run([
			PHP_BINARY, '-r', 'while (true) { usleep(200000); }',
		], $this->directory, 50, 2, 1);
		self::assertSame(['success' => false, 'reason' => 'timeout'], $result);
	}


	public function testRejectsStagedSymbolicLinks(): void {
		if (!function_exists('symlink')) {
			self::markTestSkipped('Symbolic links are unavailable on this platform');
		}
		$result = ResourceLimitedProcess::run([
			PHP_BINARY, '-r', 'symlink("somewhere-outside", $argv[1] . "/link");', $this->directory,
		], $this->directory, 50, 2, 3);
		self::assertSame(['success' => false, 'reason' => 'symlink'], $result);
	}


	public function testLargeStdoutDoesNotBlockAValidDecoder(): void {
		$result = ResourceLimitedProcess::run(
			[PHP_BINARY, '-r', 'echo str_repeat("x", 1000000);'],
			$this->directory,
			100,
			10,
			3,
		);
		self::assertSame(['success' => true, 'reason' => ''], $result);
	}

	public function testListingRejectsExcessOutput(): void {
		$result = ResourceLimitedProcess::capture(
			[PHP_BINARY, '-r', 'echo str_repeat("x", 1000000);'],
			1024,
			3,
		);
		self::assertFalse($result['success']);
		self::assertSame('output', $result['reason']);
		self::assertLessThanOrEqual(1024, strlen($result['output']));
	}

	public function testListingHasDeadline(): void {
		$result = ResourceLimitedProcess::capture(
			[PHP_BINARY, '-r', 'sleep(10);'],
			1024,
			1,
		);
		self::assertFalse($result['success']);
		self::assertSame('timeout', $result['reason']);
	}

	public function testListingAcceptsNormalOutput(): void {
		$result = ResourceLimitedProcess::capture(
			[PHP_BINARY, '-r', 'echo "Path = normal.txt\nSize = 5\n";'],
			1024,
			3,
		);
		self::assertSame(['success' => true, 'reason' => '', 'output' => "Path = normal.txt\nSize = 5\n"], $result);
	}

	public function testDoesNotAcceptNonZeroExit(): void {
		$result = ResourceLimitedProcess::run([
			PHP_BINARY, '-r', 'exit(9);',
		], $this->directory, 50, 2, 3);
		self::assertSame(['success' => false, 'reason' => 'exit'], $result);
	}
}
