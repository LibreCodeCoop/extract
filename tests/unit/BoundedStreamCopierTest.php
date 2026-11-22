<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Service\BoundedStreamCopier;
use PHPUnit\Framework\TestCase;

final class BoundedStreamCopierTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/extract-copy-' . bin2hex(random_bytes(8));
		self::assertTrue(mkdir($this->directory, 0700));
	}

	protected function tearDown(): void {
		foreach (glob($this->directory . '/*') ?: [] as $path) {
			unlink($path);
		}
		rmdir($this->directory);
	}

	private function stream(string $contents) {
		$stream = fopen('php://temp', 'w+b');
		self::assertIsResource($stream);
		fwrite($stream, $contents);
		rewind($stream);
		return $stream;
	}

	public function testCopiesWithinBudgetAndVerifiesCrc(): void {
		$stream = $this->stream('hello world');
		$written = 0;
		try {
			self::assertTrue((new BoundedStreamCopier())->copy($stream, $this->directory . '/good.txt', $written, 11, 11, hash('crc32b', 'hello world')));
			self::assertSame(11, $written);
			self::assertSame('hello world', file_get_contents($this->directory . '/good.txt'));
		} finally {
			fclose($stream);
		}
	}

	public function testRejectsSizeOverflowAndWrongCrc(): void {
		$stream = $this->stream('1234567890');
		$written = 0;
		try {
			self::assertFalse((new BoundedStreamCopier())->copy($stream, $this->directory . '/over.txt', $written, 9, 10, hash('crc32b', '1234567890')));
			self::assertSame(0, $written);
		} finally {
			fclose($stream);
		}
		$stream = $this->stream('hello');
		try {
			self::assertFalse((new BoundedStreamCopier())->copy($stream, $this->directory . '/crc.txt', $written, 10, 5, 'deadbeef'));
		} finally {
			fclose($stream);
		}
	}

	public function testDoesNotOverwriteRarDestination(): void {
		file_put_contents($this->directory . '/exists.txt', 'original');
		$stream = $this->stream('new');
		$written = 0;
		try {
			self::assertFalse((new BoundedStreamCopier())->copy($stream, $this->directory . '/exists.txt', $written, 100, 3, hash('crc32b', 'new')));
			self::assertSame('original', file_get_contents($this->directory . '/exists.txt'));
		} finally {
			fclose($stream);
		}
	}

	public function testCopyWithoutDeclaredSizeUsesZipSemantics(): void {
		$stream = $this->stream('payload');
		$written = 0;
		try {
			self::assertTrue((new BoundedStreamCopier())->copy($stream, $this->directory . '/zip.txt', $written, 7));
			self::assertSame('payload', file_get_contents($this->directory . '/zip.txt'));
		} finally {
			fclose($stream);
		}
	}
}
