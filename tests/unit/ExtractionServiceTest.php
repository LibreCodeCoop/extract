<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Service\ExtractionService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Tests for the extraction service.
 *
 * The zip and 7z fixtures are built on disk and extracted for real, so the
 * assertions cover the tool invocation and not just the return shape.
 */
final class ExtractionServiceTest extends TestCase {
	private ?ExtractionService $service = null;
	/** @var IL10N */
	private ?IL10N $l10n = null;
	/** @var LoggerInterface */
	private ?LoggerInterface $logger = null;
	private string $tmpDir = '';

	/**
	 * Build the service under test and a scratch directory.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->l10n = $this->createStub(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => $text,
		);
		$this->logger = $this->createStub(LoggerInterface::class);

		$this->service = new ExtractionService($this->l10n, $this->logger, $this->createStub(\OCP\IConfig::class));

		$this->tmpDir = sys_get_temp_dir() . '/extract-test-' . bin2hex(random_bytes(6));
		if (mkdir($this->tmpDir, 0777, true) === false && is_dir($this->tmpDir) === false) {
			self::fail('Could not create the temporary test directory');
		}
	}

	/**
	 * Remove the scratch directory.
	 */
	protected function tearDown(): void {
		$this->removeDirectory($this->tmpDir);
		parent::tearDown();
	}

	/**
	 * Recursively remove a directory, ignoring a missing one.
	 */
	private function removeDirectory(string $path): void {
		if (is_dir($path) === false) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($iterator as $item) {
			/** @var \SplFileInfo $item */
			if ($item->isDir() === true) {
				rmdir($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}
		rmdir($path);
	}

	/**
	 * Create a zip fixture with the given entries.
	 */
	private function createZip(string $path, array $entries): void {
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
	}

	/**
	 * A zip is unpacked with its nested entries.
	 */
	public function testExtractZipExtractsAllEntries(): void {
		$archive = $this->tmpDir . '/archive.zip';
		$this->createZip($archive, [
			'hello.txt' => 'hello world',
			'nested/inside.txt' => 'nested content',
		]);

		$destination = $this->tmpDir . '/out';

		$response = $this->service->extractZip($archive, $destination);

		self::assertSame(['code' => 1], $response);
		self::assertFileExists($destination . '/hello.txt');
		self::assertSame('hello world', file_get_contents($destination . '/hello.txt'));
		self::assertFileExists($destination . '/nested/inside.txt');
		self::assertSame('nested content', file_get_contents($destination . '/nested/inside.txt'));
	}

	/**
	 * A file that is not a zip reports failure.
	 */
	public function testExtractZipRejectsANonZipFile(): void {
		$archive = $this->tmpDir . '/not-an-archive.zip';
		file_put_contents($archive, 'this is definitely not a zip archive');

		$response = $this->service->extractZip($archive, $this->tmpDir . '/out');

		self::assertSame(0, $response['code']);
		self::assertSame('Cannot open Zip file', $response['desc']);
	}

	/**
	 * A missing archive reports failure.
	 */
	public function testExtractZipReportsWhenTheArchiveIsMissing(): void {
		$response = $this->service->extractZip($this->tmpDir . '/does-not-exist.zip', $this->tmpDir . '/out');

		self::assertSame(0, $response['code']);
		self::assertArrayHasKey('desc', $response);
	}

	/**
	 * A corrupt 7z archive reports failure.
	 */
	public function testExtractOtherReportsFailureWhenTheArchiveCannotBeRead(): void {
		if (trim((string)shell_exec('command -v 7za 2>/dev/null')) === '') {
			self::markTestSkipped('7za is not installed, the failure branch cannot be exercised');
		}

		$archive = $this->tmpDir . '/broken.7z';
		file_put_contents($archive, 'not a real 7z archive');

		$response = $this->service->extractOther($archive, $this->tmpDir . '/out');

		self::assertSame(0, $response['code']);
		self::assertArrayHasKey('desc', $response);
	}

	/**
	 * A successful extraction must report code 1 and unpack the payload.
	 *
	 * The archive is a committed fixture, so no archive has to be built on the
	 * host. 7za is still required to perform the extraction, and the test is
	 * skipped when it is absent.
	 */
	public function testExtractOtherExtractsAValidArchive(): void {
		if (trim((string)shell_exec('command -v 7za 2>/dev/null')) === '') {
			self::markTestSkipped('7za is not installed, the success branch cannot be exercised');
		}

		$archive = __DIR__ . '/../Fixtures/valid.7z';
		self::assertFileExists($archive);

		$destination = $this->tmpDir . '/out';
		$response = $this->service->extractOther($archive, $destination);

		self::assertSame(['code' => 1], $response);
		self::assertFileExists($destination . '/hello.txt');
		self::assertSame("hello world\n", file_get_contents($destination . '/hello.txt'));
	}

	/**
	 * The 7za listing must preflight the declared uncompressed size against
	 * the same Nextcloud destination quota used for ZIP.
	 */
	public function testRejects7zThatExceedsDestinationQuota(): void {
		if (trim((string)shell_exec('command -v 7za 2>/dev/null')) === '') {
			self::markTestSkipped('7za is not installed');
		}
		$archive = __DIR__ . '/../Fixtures/valid.7z';
		$out = $this->tmpDir . '/out';

		$response = $this->service->extractOther($archive, $out, 5);

		self::assertSame(0, $response['code']);
		self::assertSame('Archive exceeds the allowed extraction size', $response['desc']);
		self::assertDirectoryDoesNotExist($out);
	}

	/**
	 * The regular-sized fixture must still extract when the available quota
	 * covers its declared uncompressed bytes.
	 */
	public function testExtracts7zWithinDestinationQuota(): void {
		if (trim((string)shell_exec('command -v 7za 2>/dev/null')) === '') {
			self::markTestSkipped('7za is not installed');
		}
		$archive = __DIR__ . '/../Fixtures/valid.7z';
		$out = $this->tmpDir . '/out';

		$response = $this->service->extractOther($archive, $out, 100);

		self::assertSame(['code' => 1], $response);
		self::assertSame("hello world\n", file_get_contents($out . '/hello.txt'));
	}

	/**
	 * TAR is handled by the external 7za decoder too: its declared size must
	 * be checked before any output is staged. A permitted quota is the
	 * positive control using the exact same synthetic archive.
	 */
	public function testTarArchiveRespectsDestinationQuota(): void {
		if (trim((string)shell_exec('command -v 7za 2>/dev/null')) === '') {
			self::markTestSkipped('7za is not installed');
		}
		$archive = $this->tmpDir . '/quota.tar';
		$tar = new \PharData($archive);
		$tar->addFromString('payload.txt', str_repeat('x', 256));
		unset($tar);

		$denied = $this->tmpDir . '/denied';
		$failure = $this->service->extractOther($archive, $denied, 64);
		self::assertSame(0, $failure['code']);
		self::assertSame('Archive exceeds the allowed extraction size', $failure['desc']);
		self::assertDirectoryDoesNotExist($denied);

		$allowed = $this->tmpDir . '/allowed';
		self::assertSame(['code' => 1], $this->service->extractOther($archive, $allowed, 512));
		self::assertSame(str_repeat('x', 256), file_get_contents($allowed . '/payload.txt'));
	}








	/**
	 * A decompression bomb must be refused before staging any files when the
	 * total uncompressed content cannot fit the user's Nextcloud quota.
	 */
	public function testRejectsZipExceedingDestinationQuotaBeforeWriting(): void {
		$archive = $this->tmpDir . '/too-large.zip';
		$this->createZip($archive, ['large.txt' => str_repeat('x', 100)]);
		$out = $this->tmpDir . '/out';

		$response = $this->service->extractZip($archive, $out, 50);

		self::assertSame(0, $response['code']);
		self::assertSame('Archive exceeds the allowed extraction size', $response['desc']);
		self::assertDirectoryDoesNotExist($out);
	}

	/**
	 * A configurable entry count rejects an archive with excessive file-count
	 * amplification, without creating the output directory.
	 */
	public function testRejectsZipWithTooManyEntries(): void {
		$archive = $this->tmpDir . '/too-many.zip';
		$this->createZip($archive, ['first.txt' => 'a', 'second.txt' => 'b']);
		$config = $this->createStub(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $key === 'max_archive_entries' ? '1' : $default,
		);
		$service = new ExtractionService($this->l10n, $this->logger, $config);
		$out = $this->tmpDir . '/out';

		$response = $service->extractZip($archive, $out);

		self::assertSame(0, $response['code']);
		self::assertSame('Archive contains too many files', $response['desc']);
		self::assertDirectoryDoesNotExist($out);
	}

	/**
	 * Bounded streaming still preserves UTF-8 paths, nested directories and
	 * archive contents for legitimate files.
	 */
	public function testStreamedZipPreservesUnicodeNames(): void {
		$archive = $this->tmpDir . '/unicode.zip';
		$this->createZip($archive, [
			'Café.txt' => 'cafe',
			'한국어/전자책.txt' => 'korean',
			'中文.txt' => 'chinese',
		]);
		$out = $this->tmpDir . '/out';

		$response = $this->service->extractZip($archive, $out);

		self::assertSame(['code' => 1], $response);
		self::assertSame('cafe', file_get_contents($out . '/Café.txt'));
		self::assertSame('korean', file_get_contents($out . '/한국어/전자책.txt'));
		self::assertSame('chinese', file_get_contents($out . '/中文.txt'));
	}

	/**
	 * A rar archive without rar support reports failure.
	 */
	public function testExtractRarReportsFailureWhenNoRarSupportIsAvailable(): void {
		if (extension_loaded('rar') === true || trim((string)shell_exec('command -v unrar 2>/dev/null')) !== '') {
			self::markTestSkipped('rar support is installed, the failure branch cannot be exercised');
		}

		$archive = $this->tmpDir . '/broken.rar';
		file_put_contents($archive, 'not a real rar archive');

		$response = $this->service->extractRar($archive, $this->tmpDir . '/out');

		self::assertSame(0, $response['code']);
		self::assertArrayHasKey('desc', $response);
	}
}
