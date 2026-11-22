<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Service\ArchiveSafetyValidator;
use OCA\Extract\Service\BoundedStreamCopier;
use OCA\Extract\Service\ExtractionLimits;
use OCA\Extract\Service\ZipExtractor;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

final class ZipExtractorTest extends TestCase {
	public function testRejectsTraversalWithoutCreatingStagingFiles(): void {
		$directory = sys_get_temp_dir() . '/extract-zip-rule-' . bin2hex(random_bytes(8));
		self::assertTrue(mkdir($directory, 0700));
		$archive = $directory . '/unsafe.zip';
		$zip = new ZipArchive();
		self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
		self::assertTrue($zip->addFromString('../outside.txt', 'unsafe'));
		$zip->close();

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$validator = new ArchiveSafetyValidator($l10n, $this->createStub(LoggerInterface::class));
		$extractor = new ZipExtractor($l10n, $validator, new ExtractionLimits($this->createStub(IConfig::class)), new BoundedStreamCopier());
		$out = $directory . '/result';
		try {
			$response = $extractor->extract($archive, $out);
			self::assertSame(0, $response['code']);
			self::assertDirectoryDoesNotExist($out);
			self::assertFileDoesNotExist($directory . '/outside.txt');
		} finally {
			unlink($archive);
			rmdir($directory);
		}
	}
}
