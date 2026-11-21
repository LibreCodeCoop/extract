<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Service\ExtractionService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RarFallbackTest extends TestCase {
	/**
	 * Small RAR 2 fixture adapted from libarchive's freely distributed
	 * test_read_format_rar.rar.uu (libarchive/test), unmodified contents.
	 */
	private const RAR_FIXTURE = 'UmFyIRoHAM+QcwAADQAAAAAAAACEUnQgkDIAFAAAABQAAAADQqLIvrd22j4UMAgApIEAAHRlc3QudHh0gAi3dto+t3baPnRlc3QgdGV4dCBkb2N1bWVudA0KnS90IJAyAAgAAAAIAAAAA3tEybbRTNg+FDAIAP+hAAB0ZXN0bGlua8AI0UzYPlBf2j50ZXN0LnR4dM3gdCCQOgAUAAAAFAAAAANCosi+Y3faPhQwEACkgQAAdGVzdGRpclx0ZXN0LnR4dMDMY3faPmN32j50ZXN0IHRleHQgZG9jdW1lbnQNCqHIdOCQMQAAAAAAAAAAAAMAAAAAY3faPhQwBwDtQQAAdGVzdGRpcsDMY3faPmR32j7m53TgkDYAAAAAAAAAAAADAAAAAJ2r1T4UMAwA7UEAAHRlc3RlbXB0eWRpcoDMnavVPsVd2j7EPXsAQAcA';

	public function testRarUsesFullSevenZipWhenUnrarIsUnavailable(): void {
		if (extension_loaded('rar') || trim((string)shell_exec('command -v unrar 2>/dev/null')) !== '') {
			self::markTestSkipped('RAR support already provided by unrar or PHP extension');
		}
		if (trim((string)shell_exec('command -v 7z 2>/dev/null')) === '') {
			self::markTestSkipped('The full 7z executable is required for this fallback test');
		}

		$directory = sys_get_temp_dir() . '/extract-rar-fallback-' . bin2hex(random_bytes(8));
		self::assertTrue(mkdir($directory, 0700));
		$archive = $directory . '/fixture.rar';
		file_put_contents($archive, base64_decode(self::RAR_FIXTURE, true));
		$destination = $directory . '/out';
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$service = new ExtractionService($l10n, $this->createStub(LoggerInterface::class));

		try {
			$result = $service->extractRar($archive, $destination);
			self::assertSame(['code' => 1], $result);
			self::assertSame("test text document\r\n", file_get_contents($destination . '/test.txt'));
			self::assertSame("test text document\r\n", file_get_contents($destination . '/testdir/test.txt'));
		} finally {
			if (is_dir($destination)) {
				$files = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($destination, \FilesystemIterator::SKIP_DOTS),
					\RecursiveIteratorIterator::CHILD_FIRST,
				);
				foreach ($files as $entry) {
					if ($entry->isLink() || !$entry->isDir()) {
						unlink($entry->getPathname());
					} else {
						rmdir($entry->getPathname());
					}
				}
				rmdir($destination);
			}
			unlink($archive);
			rmdir($directory);
		}
	}
}
