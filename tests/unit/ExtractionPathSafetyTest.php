<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Service\ArchiveSafetyValidator;
use OCA\Extract\Service\ExtractionService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

final class ExtractionPathSafetyTest extends TestCase {
	private function service(): ExtractionService {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		return new ExtractionService($l10n, $this->createStub(LoggerInterface::class), $this->createStub(\OCP\IConfig::class));
	}

	public function testUnsafeArchiveEntryNamesAreRejected(): void {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$validator = new ArchiveSafetyValidator($l10n, $this->createStub(LoggerInterface::class));

		foreach (['../escape.txt', 'a/../../escape.txt', '..\\escape.txt', '/etc/passwd', '\\\\server\\share', 'C:\\outside.txt', 'C:relative.txt', "a\x00b"] as $entry) {
			self::assertSame(0, $validator->rejectUnsafe($entry)['code'], 'Unsafe path accepted: ' . bin2hex($entry));
		}
		foreach (['safe.txt', 'nested/file.txt', 'nested\\file.txt', 'a/..name.txt', './inside.txt'] as $entry) {
			self::assertNull($validator->rejectUnsafe($entry), 'Safe path rejected: ' . $entry);
		}
	}

	public function testZipRejectsUnsafeEntriesBeforeWritingAnyFiles(): void {
		if (!extension_loaded('zip')) {
			self::markTestSkipped('Zip extension is not installed');
		}
		$root = sys_get_temp_dir() . '/extract-security-' . bin2hex(random_bytes(8));
		self::assertTrue(mkdir($root, 0700));
		$archive = $root . '/bad.zip';
		$output = $root . '/out';
		try {
			$zip = new ZipArchive();
			self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
			self::assertTrue($zip->addFromString('safe.txt', 'safe'));
			self::assertTrue($zip->addFromString('../escape.txt', 'unsafe'));
			self::assertTrue($zip->close());
			$result = $this->service()->extractZip($archive, $output);
			self::assertSame(0, $result['code']);
			self::assertDirectoryDoesNotExist($output);
			self::assertFileDoesNotExist($root . '/escape.txt');
		} finally {
			if (is_file($archive)) {
				unlink($archive);
			}
			if (is_file($root . '/escape.txt')) {
				unlink($root . '/escape.txt');
			}
			if (is_dir($root)) {
				rmdir($root);
			}
		}
	}
}
