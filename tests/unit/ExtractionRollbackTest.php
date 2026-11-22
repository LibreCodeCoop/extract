<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Controller\ExtractionController;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ExtractionRollbackTest extends TestCase {
	public static function destinationCases(): array {
		return [
			'new extraction folder is rolled back' => [false],
			'existing legacy folder is never deleted' => [true],
		];
	}

	/**
	 * An interrupted import must not leave a partly created new destination,
	 * nor remove an existing folder that a legacy client was merging into.
	 */
	#[DataProvider('destinationCases')]
	public function testImportFailureHandlesDestinationSafely(bool $alreadyExists): void {
		$source = sys_get_temp_dir() . '/extract-rollback-' . bin2hex(random_bytes(6));
		self::assertTrue(mkdir($source, 0700));
		file_put_contents($source . '/one.txt', 'one');
		file_put_contents($source . '/two.txt', 'two');

		$target = $this->createMock(Folder::class);
		$target->method('isCreatable')->willReturn(true);
		$target->expects($alreadyExists ? self::never() : self::once())->method('delete');
		$calls = 0;
		$target->expects(self::exactly(2))->method('newFile')->willReturnCallback(
			function (string $relative, $stream) use (&$calls): File {
				self::assertIsResource($stream);
				$calls++;
				if ($calls === 2) {
					throw new NotPermittedException('simulated quota or storage failure');
				}
				return $this->createStub(File::class);
			},
		);

		$parent = $this->createMock(Folder::class);
		$parent->method('isCreatable')->willReturn(true);
		$parent->expects(self::exactly(2))->method('nodeExists')->with('result')->willReturn($alreadyExists);
		if ($alreadyExists) {
			$parent->expects(self::once())->method('get')->with('result')->willReturn($target);
		} else {
			$parent->expects(self::once())->method('newFolder')->with('result')->willReturn($target);
		}

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->with('/')->willReturn($parent);
		$validator = $this->createStub(IFilenameValidator::class);
		$validator->method('isFilenameValid')->willReturn(true);
		$controller = (new \ReflectionClass(ExtractionController::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(ExtractionController::class, 'filenameValidator'))->setValue($controller, $validator);
		(new \ReflectionProperty(ExtractionController::class, 'userFolder'))->setValue($controller, $userFolder);
		(new \ReflectionProperty(ExtractionController::class, 'logger'))->setValue($controller, $this->createStub(LoggerInterface::class));

		try {
			try {
				(new \ReflectionMethod(ExtractionController::class, 'postExtract'))->invoke(
					$controller,
					'result',
					'/',
					$source,
					!$alreadyExists,
				);
				self::fail('A partial import must not report success');
			} catch (NotPermittedException $e) {
				self::assertSame('Could not complete extraction', $e->getMessage());
			}
			self::assertSame(2, $calls);
		} finally {
			unlink($source . '/one.txt');
			unlink($source . '/two.txt');
			rmdir($source);
		}
	}
}
