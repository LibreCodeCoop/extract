<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Service\ExtractionService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

final class ExtractionServiceTest extends TestCase {
	private ExtractionService $service;
	private IL10N&MockObject $l10n;
	private LoggerInterface&MockObject $logger;
	private string $tmpDir = '';

	/**
	 * Build the service under test and a scratch directory.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => $text,
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new ExtractionService($this->l10n, $this->logger);

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
