<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Controller\ExtractionController;
use OCA\Extract\Service\ExtractionService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * End-to-end checks for the two-pass tarball path.
 *
 * These run the real ExtractionService against real archives on disk. They exist
 * because the unit tests for the routing helpers alone did not catch two
 * regressions: a plain `.tar` being sent down the two-pass path (7za expands it
 * in a single pass), and a failed extraction still reaching the post-processing.
 */
final class WrappedTarballExtractionTest extends TestCase {
	private ExtractionController $controller;
	private string $tmpDir;

	/**
	 * Prepare a controller wired to the real extraction service.
	 */
	protected function setUp(): void {
		parent::setUp();

		if (trim((string)shell_exec('command -v 7za 2>/dev/null')) === '') {
			self::markTestSkipped('7za is not installed');
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => $text,
		);

		// Build the controller without its constructor: the constructor only
		// wires up the Nextcloud folder APIs, which the tarball helpers do not
		// touch. This keeps the real ExtractionService and the real filesystem
		// in the test instead of mocking the behaviour under test.
		$reflection = new \ReflectionClass(ExtractionController::class);
		$this->controller = $reflection->newInstanceWithoutConstructor();

		$this->setProperty('extractionService', new ExtractionService($l10n, $this->createMock(LoggerInterface::class)));
		$this->setProperty('logger', $this->createMock(LoggerInterface::class));
		$this->setProperty('l', $l10n);

		$this->tmpDir = sys_get_temp_dir() . '/extract-wrapped-' . bin2hex(random_bytes(6));
		mkdir($this->tmpDir, 0777, true);
	}

	/**
	 * Remove the working directory.
	 */
	protected function tearDown(): void {
		$this->removeDirectory($this->tmpDir);
		parent::tearDown();
	}

	/**
	 * Inject a private property on the controller under test.
	 *
	 * @param string $name The property name.
	 * @param object $value The value to assign.
	 */
	private function setProperty(string $name, object $value): void {
		$property = new \ReflectionProperty(ExtractionController::class, $name);
		$property->setValue($this->controller, $value);
	}

	/**
	 * Recursively remove a directory, ignoring a missing one.
	 *
	 * @param string $path The directory to remove.
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
	 * Build a tar archive using PharData.
	 *
	 * PharData builds the fixture in-process, so the test does not shell out
	 * to `tar` to create its own input.
	 *
	 * @param string $path Where to write the tar.
	 * @param bool $gzip Whether to wrap the tar in gzip.
	 * @return string The archive that was produced. PharData::compress()
	 *                appends `.gz`, so this can differ from $path.
	 */
	private function createTar(string $path, bool $gzip): string {
		$tar = new \PharData($path);
		$tar->addFromString('hello.txt', 'hello world');
		$tar->addFromString('nested/inside.txt', 'nested content');
		if ($gzip === true) {
			$tar->compress(\Phar::GZ);
			unset($tar);
			// compress() writes alongside the tar with a .gz suffix.
			return $path . '.gz';
		}
		unset($tar);
		return $path;
	}

	/**
	 * A wrapped tarball is unwrapped and expanded into the destination.
	 */
	public function testAWrappedTarballIsUnpackedIntoTheDestination(): void {
		$archive = $this->createTar($this->tmpDir . '/data.tar', true);

		$destination = $this->tmpDir . '/result';
		$method = new \ReflectionMethod(ExtractionController::class, 'extractWrappedTarball');
		$response = $method->invoke($this->controller, $archive, $destination);

		self::assertSame(['code' => 1], $response);
		self::assertFileExists($destination . '/hello.txt');
		self::assertSame('hello world', file_get_contents($destination . '/hello.txt'));
		self::assertFileExists($destination . '/nested/inside.txt');
	}

	/**
	 * The request-owned scratch directory does not outlive the extraction.
	 */
	public function testTheScratchDirectoryIsRemovedAfterwards(): void {
		$archive = $this->createTar($this->tmpDir . '/data.tar', true);

		$destination = $this->tmpDir . '/result';
		$method = new \ReflectionMethod(ExtractionController::class, 'extractWrappedTarball');
		$method->invoke($this->controller, $archive, $destination);

		$leftovers = glob($destination . '.unwrapped*');
		self::assertSame([], $leftovers === false ? [] : $leftovers, 'the scratch directory must be cleaned up');
	}

	/**
	 * A corrupt wrapped tarball must report failure instead of leaving a
	 * half-written destination behind.
	 */
	public function testACorruptWrappedTarballReportsFailure(): void {
		$archive = $this->tmpDir . '/broken.tar.gz';
		file_put_contents($archive, 'this is not a gzip archive');

		$destination = $this->tmpDir . '/result';
		$method = new \ReflectionMethod(ExtractionController::class, 'extractWrappedTarball');
		$response = $method->invoke($this->controller, $archive, $destination);

		self::assertSame(0, $response['code']);
		self::assertArrayHasKey('desc', $response);
		self::assertDirectoryDoesNotExist($destination);
	}

	/**
	 * A plain `.tar` is expanded by a single 7za pass, so it must never be sent
	 * down the two-pass path.
	 */
	public function testAPlainTarIsExpandedInASinglePass(): void {
		$archive = $this->createTar($this->tmpDir . '/data.tar', false);

		$isWrapped = new \ReflectionMethod(ExtractionController::class, 'isWrappedTarball');
		self::assertFalse($isWrapped->invoke(null, 'data.tar'));

		$destination = $this->tmpDir . '/result';
		$service = new \ReflectionProperty(ExtractionController::class, 'extractionService');
		$response = $service->getValue($this->controller)->extractOther($archive, $destination);

		self::assertSame(['code' => 1], $response);
		self::assertFileExists($destination . '/hello.txt');
	}
}
