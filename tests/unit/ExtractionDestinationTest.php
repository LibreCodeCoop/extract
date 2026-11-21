<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Controller\ExtractionController;
use OCA\Extract\Service\ExtractionService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\NotPermittedException;
use OCP\Files\Storage\IStorage;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

final class ExtractionDestinationTest extends TestCase {
	/**
	 * A relative target may contain nested folders, but never escape the archive's directory.
	 */
	public function testValidatesRelativeDestinations(): void {
		$check = new \ReflectionMethod(ExtractionController::class, 'isUsableRelativeDestinationPath');

		foreach (['backup', 'reports/2026', 'data.archive/final', 'a/mañana'] as $valid) {
			self::assertTrue($check->invoke(null, $valid), $valid);
		}

		foreach (['', '.', '..', '../escape', 'a/../escape', './safe', '/absolute', 'a//b', 'a/', 'a\\b', "a\x00b", "a\nb"] as $invalid) {
			self::assertFalse($check->invoke(null, $invalid), bin2hex($invalid));
		}
	}

	/**
	 * Existing explicit targets must be rejected before staging or extraction.
	 */
	public function testDuplicateExplicitDestinationDoesNotStartExtraction(): void {
		$archive = $this->createMock(File::class);
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturnCallback(static fn (string $path) => $path === '/' ? $folder : $archive);
		$folder->method('isCreatable')->willReturn(true);
		$folder->expects(self::once())->method('nodeExists')->with('reports/backup')->willReturn(true);

		$tmp = $this->createMock(ITempManager::class);
		$tmp->expects(self::never())->method('getTemporaryFolder');

		$controller = $this->controller($folder, $tmp);
		$response = $controller->execute('backup.zip', '/', false, 'application/zip', 'reports/backup')->getData();

		self::assertSame(0, $response['code']);
		self::assertSame('Destination folder already exists', $response['desc']);
	}

	/**
	 * Rejected traversal paths cannot create even the staging directory.
	 */
	public function testInvalidDestinationIsRejectedBeforeAccessingStorage(): void {
		$folder = $this->createMock(Folder::class);
		$folder->expects(self::never())->method('get');
		$tmp = $this->createMock(ITempManager::class);
		$tmp->expects(self::never())->method('getTemporaryFolder');

		$controller = $this->controller($folder, $tmp);
		$result = $controller->execute('backup.zip', '/', false, 'application/zip', '../escape')->getData();

		self::assertSame(0, $result['code']);
		self::assertSame('Invalid extraction folder name', $result['desc']);
	}

	/**
	 * The filename validator also applies to every nested segment.
	 */
	public function testForbiddenFilenameIsRejectedBeforeAccessingStorage(): void {
		$folder = $this->createMock(Folder::class);
		$folder->expects(self::never())->method('get');
		$tmp = $this->createMock(ITempManager::class);
		$tmp->expects(self::never())->method('getTemporaryFolder');

		$validator = $this->createMock(IFilenameValidator::class);
		$validator->method('isFilenameValid')->willReturnCallback(static fn (string $name): bool => $name !== '.htaccess');
		$controller = $this->controller($folder, $tmp, $validator);
		$result = $controller->execute('backup.zip', '/', false, 'application/zip', 'reports/.htaccess')->getData();

		self::assertSame(0, $result['code']);
		self::assertSame('Invalid extraction folder name', $result['desc']);
	}

	/**
	 * A request for an existing custom target must not silently merge it.
	 */
	public function testDestinationFolderRejectsExistingExplicitTarget(): void {
		$existing = $this->createMock(Folder::class);
		$existing->method('isCreatable')->willReturn(true);
		$parent = $this->createMock(Folder::class);
		$parent->method('isCreatable')->willReturn(true);
		$parent->method('nodeExists')->with('backup')->willReturn(true);
		$parent->method('get')->with('backup')->willReturn($existing);

		$root = $this->createMock(Folder::class);
		$root->method('get')->willReturn($parent);
		$controller = $this->controller($root, $this->createMock(ITempManager::class));

		$resolve = new \ReflectionMethod(ExtractionController::class, 'destinationFolder');
		self::assertSame($existing, $resolve->invoke($controller, '/', 'backup', false));

		$this->expectException(NotPermittedException::class);
		$resolve->invoke($controller, '/', 'backup', true);
	}

	/**
	 * New nested target folders are created only inside the archive's parent.
	 */
	public function testCreatesNestedFoldersInArchiveDirectory(): void {
		$destination = $this->createMock(Folder::class);
		$inner = $this->createMock(Folder::class);
		$inner->expects(self::once())->method('nodeExists')->with('2026')->willReturn(false);
		$inner->expects(self::once())->method('newFolder')->with('2026')->willReturn($destination);

		$parent = $this->createMock(Folder::class);
		$parent->method('isCreatable')->willReturn(true);
		$parent->expects(self::once())->method('nodeExists')->with('reports')->willReturn(false);
		$parent->expects(self::once())->method('newFolder')->with('reports')->willReturn($inner);

		$root = $this->createMock(Folder::class);
		$root->method('get')->willReturn($parent);
		$controller = $this->controller($root, $this->createMock(ITempManager::class));

		$resolve = new \ReflectionMethod(ExtractionController::class, 'destinationFolder');
		self::assertSame($destination, $resolve->invoke($controller, '/', 'reports/2026', true));
	}


	/**
	 * A custom nested destination is imported through Nextcloud's files API,
	 * even when the archive lives on non-local object storage such as S3.
	 */
	public function testCustomDestinationOnNonLocalStorage(): void {
		$rootDir = sys_get_temp_dir() . '/extract-s3-destination-' . bin2hex(random_bytes(8));
		self::assertTrue(mkdir($rootDir, 0700));
		$archive = $rootDir . '/backup.zip';
		$zip = new ZipArchive();
		self::assertTrue($zip->open($archive, ZipArchive::CREATE));
		self::assertTrue($zip->addFromString('hello.txt', 'from s3'));
		self::assertTrue($zip->close());

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(false);
		$storage->method('getLocalFile')->willReturn($archive);
		$archiveNode = $this->createMock(File::class);
		$archiveNode->method('getStorage')->willReturn($storage);
		$archiveNode->method('getInternalPath')->willReturn('files/backup.zip');

		$mount = $this->createMock(IMountPoint::class);
		$mount->method('getMountType')->willReturn('external');
		$destination = $this->createMock(Folder::class);
		$destination->method('getId')->willReturn(42);
		$destination->method('getPermissions')->willReturn(31);
		$destination->method('getMTime')->willReturn(12345);
		$destination->method('getMountPoint')->willReturn($mount);
		$written = null;
		$destination->expects(self::once())->method('newFile')->willReturnCallback(
			function (string $name, $stream) use (&$written) {
				self::assertSame('hello.txt', $name);
				self::assertIsResource($stream);
				$written = stream_get_contents($stream);
				return $this->createMock(File::class);
			},
		);

		$year = $this->createMock(Folder::class);
		$year->method('nodeExists')->with('Q4 #2026')->willReturn(false);
		$year->method('newFolder')->with('Q4 #2026')->willReturn($destination);
		$folder = $this->createMock(Folder::class);
		$folder->method('isCreatable')->willReturn(true);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFolder')->with('reports')->willReturn($year);
		$folder->method('get')->willReturnCallback(
			static function (string $path) use ($folder, $archiveNode, $destination) {
				return match ($path) {
					'/' => $folder,
					'//backup.zip' => $archiveNode,
					'/reports/Q4 #2026' => $destination,
					default => throw new \RuntimeException('Unexpected file lookup: ' . $path),
				};
			},
		);

		$temp = $this->createMock(ITempManager::class);
		$scratch = $rootDir . '/scratch';
		$temp->method('getTemporaryFolder')->willReturnCallback(
			static function () use ($scratch): string {
				mkdir($scratch, 0700);
				return $scratch;
			},
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => $text);
		$logger = $this->createMock(LoggerInterface::class);
		$validator = $this->createMock(IFilenameValidator::class);
		$validator->method('isFilenameValid')->willReturn(true);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://example.test');

		$controller = new ExtractionController(
			'extract',
			$this->createMock(IRequest::class),
			new ExtractionService($l10n, $logger),
			$root,
			$l10n,
			$logger,
			'user',
			$url,
			$validator,
			$temp,
		);
		try {
			$result = $controller->execute('backup.zip', '/', false, 'application/zip', 'reports/Q4 #2026')->getData();
			self::assertSame(1, $result['code']);
			self::assertSame('from s3', $written);
			self::assertSame(42, $result['extracted']['fileId']);
			self::assertSame(
				'https://example.test/remote.php/dav/files/user/reports/Q4%20%232026',
				$result['extracted']['source'],
			);
			self::assertDirectoryDoesNotExist($scratch);
		} finally {
			if (is_file($archive)) {
				unlink($archive);
			}
			if (is_dir($scratch)) {
				rmdir($scratch);
			}
			rmdir($rootDir);
		}
	}

	/**
	 * A folder cannot be passed off as an archive by supplying a ZIP MIME type.
	 */
	public function testRejectsFolderNodeAsArchiveWithoutReadingStorage(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('isCreatable')->willReturn(true);
		$folder->method('get')->willReturn($folder);
		$folder->method('nodeExists')->willReturn(false);
		$folder->expects(self::never())->method('getStorage');
		$temp = $this->createMock(ITempManager::class);
		$temp->expects(self::never())->method('getTemporaryFolder');

		$result = $this->controller($folder, $temp)->execute('folder.zip', '/', false, 'application/zip', 'output')->getData();
		self::assertSame(0, $result['code']);
		self::assertSame('File not found', $result['desc']);
	}

	private function controller(Folder $folder, ITempManager $temp, ?IFilenameValidator $validator = null): ExtractionController {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => $text);
		$logger = $this->createMock(LoggerInterface::class);
		if ($validator === null) {
			$validator = $this->createMock(IFilenameValidator::class);
			$validator->method('isFilenameValid')->willReturn(true);
		}

		return new ExtractionController(
			'extract',
			$this->createMock(IRequest::class),
			new ExtractionService($l10n, $logger),
			$root,
			$l10n,
			$logger,
			'user',
			$this->createMock(IURLGenerator::class),
			$validator,
			$temp,
		);
	}
}
