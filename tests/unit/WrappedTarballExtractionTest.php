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

	protected function tearDown(): void {
		$this->removeDirectory($this->tmpDir);
		parent::tearDown();
	}

	private function setProperty(string $name, object $value): void {
		$property = new \ReflectionProperty(ExtractionController::class, $name);
		$property->setValue($this->controller, $value);
	}

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

	private function createPayload(): string {
		$payload = $this->tmpDir . '/payload';
		mkdir($payload, 0777, true);
		file_put_contents($payload . '/hello.txt', 'hello world');
		mkdir($payload . '/nested', 0777, true);
		file_put_contents($payload . '/nested/inside.txt', 'nested content');

		return $payload;
	}

	public function testAWrappedTarballIsUnpackedIntoTheDestination(): void {
		$payload = $this->createPayload();
		$archive = $this->tmpDir . '/data.tar.gz';
		exec('tar czf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($payload) . ' . 2>/dev/null', $out, $return);
		self::assertSame(0, $return, 'Could not build the fixture archive');

		$destination = $this->tmpDir . '/result';
		$method = new \ReflectionMethod(ExtractionController::class, 'extractWrappedTarball');
		$response = $method->invoke($this->controller, $archive, $destination);

		self::assertSame(['code' => 1], $response);
		self::assertFileExists($destination . '/hello.txt');
		self::assertSame('hello world', file_get_contents($destination . '/hello.txt'));
		self::assertFileExists($destination . '/nested/inside.txt');
	}

	public function testTheScratchDirectoryIsRemovedAfterwards(): void {
		$payload = $this->createPayload();
		$archive = $this->tmpDir . '/data.tar.gz';
		exec('tar czf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($payload) . ' . 2>/dev/null');

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
		$payload = $this->createPayload();
		$archive = $this->tmpDir . '/data.tar';
		exec('tar cf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($payload) . ' . 2>/dev/null');

		$isWrapped = new \ReflectionMethod(ExtractionController::class, 'isWrappedTarball');
		self::assertFalse($isWrapped->invoke(null, 'data.tar'));

		$destination = $this->tmpDir . '/result';
		$service = new \ReflectionProperty(ExtractionController::class, 'extractionService');
		$response = $service->getValue($this->controller)->extractOther($archive, $destination);

		self::assertSame(['code' => 1], $response);
		self::assertFileExists($destination . '/hello.txt');
	}
}
