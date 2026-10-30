<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Controller\ExtractionController;
use OCA\Extract\Service\ExtractionService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Guards the mapping between the archive MIME types Nextcloud reports and the
 * extraction strategy the controller selects.
 */
final class ExtractionControllerTest extends TestCase {
	/**
	 * Every archive MIME Nextcloud reports is mapped.
	 */
	public function testEverySupportedExtensionHasAMimeType(): void {
		// The MIME types Nextcloud resolves for the extensions listed in the
		// README (see resources/config/mimetypemapping.dist.json in the server).
		$nextcloudArchiveMimeTypes = [
			'application/zip', // .zip
			'application/x-rar-compressed', // .rar
			'application/x-tar', // .tar
			'application/gzip', // .gz and .tgz
			'application/x-bzip2', // .bz2 and .tbz2
			'application/x-7z-compressed', // .7z
			'application/x-deb', // .deb
		];

		foreach ($nextcloudArchiveMimeTypes as $mime) {
			self::assertArrayHasKey(
				$mime,
				ExtractionController::SUPPORTED_MIME_TYPES,
				"$mime is not mapped to an extraction strategy",
			);
		}
	}

	/**
	 * application/gzip uses the generic strategy.
	 */
	public function testGzipUsesTheGenericStrategy(): void {
		// Nextcloud reports application/gzip for .gz/.tgz; the controller used
		// to only know application/x-gzip, which made the action fail.
		self::assertSame('other', ExtractionController::SUPPORTED_MIME_TYPES['application/gzip']);
	}

	/**
	 * Zip and rar use their dedicated strategies.
	 */
	public function testZipAndRarUseTheirDedicatedStrategies(): void {
		self::assertSame('zip', ExtractionController::SUPPORTED_MIME_TYPES['application/zip']);
		self::assertSame('rar', ExtractionController::SUPPORTED_MIME_TYPES['application/x-rar-compressed']);
	}

	/**
	 * Every mapped strategy is one the controller implements.
	 */
	public function testEveryStrategyIsKnown(): void {
		foreach (ExtractionController::SUPPORTED_MIME_TYPES as $mime => $strategy) {
			self::assertContains($strategy, ['zip', 'rar', 'other'], "$mime maps to unknown strategy $strategy");
		}
	}

	/**
	 * Only tarballs with a real compression wrapper take the two-pass path.
	 *
	 * A plain `.tar` must not: 7za expands it in a single pass, so treating it as
	 * wrapped would make the controller look for a second archive inside the
	 * extracted payload.
	 */
	public function testWrappedTarballsAreRecognised(): void {
		$method = new \ReflectionMethod(ExtractionController::class, 'isWrappedTarball');

		$wrapped = [
			'archive.tar.gz',
			'archive.tar.bz2',
			'archive.tar.xz',
			'archive.tgz',
			'archive.tbz',
			'archive.tbz2',
			'archive.txz',
			'ARCHIVE.TGZ',
		];
		foreach ($wrapped as $name) {
			self::assertTrue($method->invoke(null, $name), "$name should take the two-pass path");
		}
	}

	/**
	 * Plain archives are not treated as wrapped tarballs.
	 */
	public function testPlainArchivesAreNotWrappedTarballs(): void {
		$method = new \ReflectionMethod(ExtractionController::class, 'isWrappedTarball');

		$plain = ['archive.tar', 'archive.zip', 'archive.7z', 'archive.rar', 'archive.deb', 'archive.gz'];
		foreach ($plain as $name) {
			self::assertFalse($method->invoke(null, $name), "$name should take the single-pass path");
		}
	}

	/**
	 * Compressed tarballs lose both suffixes so the result folder matches the
	 * one a plain archive of the same base name produces.
	 */
	public function testResultFolderNameDropsBothSuffixes(): void {
		$method = new \ReflectionMethod(ExtractionController::class, 'resultFolderName');

		$expected = [
			'archive.tar.gz' => 'archive',
			'archive.tgz' => 'archive',
			'archive.tar.bz2' => 'archive',
			'archive.tbz2' => 'archive',
			'archive.tar' => 'archive',
			'my.data.tar.gz' => 'my.data',
			'archive.zip' => 'archive',
			'archive.7z' => 'archive',
		];
		foreach ($expected as $name => $folder) {
			self::assertSame($folder, $method->invoke(null, $name), "wrong result folder for $name");
		}
	}

	/**
	 * A name that reduces to a path component must be rejected: the destination
	 * would otherwise be the containing or the parent directory, unpacking the
	 * archive outside the folder it lives in.
	 */
	public function testDegenerateArchiveNamesAreRejected(): void {
		$name = new \ReflectionMethod(ExtractionController::class, 'resultFolderName');
		$usable = new \ReflectionMethod(ExtractionController::class, 'isUsableFolderName');

		foreach (['.tar.gz', '.tar', '.tbz2', '.gz', '..tar.gz', '...tar.gz', '..', '.'] as $fileName) {
			self::assertFalse(
				$usable->invoke(null, $name->invoke(null, $fileName)),
				"$fileName must not be accepted as a result folder",
			);
		}
	}

	/**
	 * Extraction must still run when the destination is not real local storage.
	 *
	 * The result cannot be unpacked beside the archive there: with server-side
	 * encryption enabled, or on object storage, getLocalFile() returns a temporary
	 * file, so the destination would resolve outside the data directory and nothing
	 * would be published. The controller must unpack into a request-owned temporary
	 * directory and import the tree through the Nextcloud filesystem instead of
	 * refusing the request.
	 *
	 * This drives execute() with a non-local storage and asserts the extraction
	 * service is reached, which a source-text scan cannot prove.
	 */
	public function testExecuteImportsOnNonLocalStorage(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(false);
		// getFile() calls this and requires a string path.
		$storage->method('getLocalFile')->willReturn('/tmp/extract-test-archive.zip');

		$node = $this->createMock(File::class);
		$node->method('getStorage')->willReturn($storage);
		$node->method('getInternalPath')->willReturn('files/archive.zip');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturn($node);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => $text,
		);

		$scratch = sys_get_temp_dir() . '/extract-scratch-' . bin2hex(random_bytes(6));
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFolder')->willReturnCallback(
			function () use ($scratch): string {
				mkdir($scratch, 0700, true);
				return $scratch;
			},
		);

		// A real service instance. The archive path does not exist, so extractZip()
		// reports "Cannot open Zip file" — reaching it proves the non-local storage
		// no longer refuses the request.
		$service = new ExtractionService($l10n, $this->createMock(LoggerInterface::class));

		$controller = new ExtractionController(
			'extract',
			$this->createMock(IRequest::class),
			$service,
			$rootFolder,
			$l10n,
			$this->createMock(LoggerInterface::class),
			'user',
			$this->createMock(IURLGenerator::class),
			$this->createMock(IFilenameValidator::class),
			$tempManager,
		);

		$response = $controller->execute('archive.zip', '/', false, 'application/zip');
		$data = $response->getData();

		self::assertSame(0, $data['code']);
		// Not a storage refusal: the request reached the extraction service.
		self::assertNotSame('Extraction is only supported on local storage', $data['desc']);
		self::assertSame('Cannot open Zip file', $data['desc']);
		// The request-owned scratch directory must not outlive the request.
		self::assertDirectoryDoesNotExist($scratch);
	}

	/**
	 * Ordinary local storage keeps unpacking beside the archive.
	 */
	public function testExecuteAllowsLocalStorage(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);

		// getFile() calls this and requires a string path.
		$storage->method('getLocalFile')->willReturn('/tmp/extract-test-archive.zip');

		$node = $this->createMock(File::class);
		$node->method('getStorage')->willReturn($storage);
		$node->method('getInternalPath')->willReturn('files/archive.zip');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturn($node);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => $text,
		);

		// The scratch directory must not be requested on local storage.
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->expects(self::never())->method('getTemporaryFolder');

		// A real service instance. The archive path does not exist, so
		// extractZip() reports "Cannot open Zip file" — reaching it proves the
		// local-storage path let the request through.
		$service = new ExtractionService($l10n, $this->createMock(LoggerInterface::class));

		$controller = new ExtractionController(
			'extract',
			$this->createMock(IRequest::class),
			$service,
			$rootFolder,
			$l10n,
			$this->createMock(LoggerInterface::class),
			'user',
			$this->createMock(IURLGenerator::class),
			$this->createMock(IFilenameValidator::class),
			$tempManager,
		);

		$response = $controller->execute('archive.zip', '/', false, 'application/zip');
		$data = $response->getData();
		self::assertSame(0, $data['code']);
		self::assertSame('Cannot open Zip file', $data['desc']);
	}

	/**
	 * The import step recreates the extracted tree through the files API.
	 *
	 * Nested directories must be created before their contents, and every file must
	 * be written with its own stream, so the copy produces the files-cache entries
	 * and write hooks that a plain storage scan would have to backfill.
	 */
	public function testImportDirectoryCopiesTheTreeThroughTheFilesApi(): void {
		$source = sys_get_temp_dir() . '/extract-import-' . bin2hex(random_bytes(6));
		mkdir($source . '/sub', 0700, true);
		file_put_contents($source . '/hello.txt', 'hello world');
		file_put_contents($source . '/sub/inside.txt', 'nested content');

		$created = [];
		$target = $this->createMock(Folder::class);
		$target->method('newFolder')->willReturnCallback(
			function (string $path) use (&$created): Folder {
				$created[] = ['dir', $path];
				return $this->createMock(Folder::class);
			},
		);
		$target->method('newFile')->willReturnCallback(
			function (string $path, $content) use (&$created) {
				self::assertIsResource($content);
				$created[] = ['file', $path, stream_get_contents($content)];
				return $this->createMock(File::class);
			},
		);

		$reflection = new \ReflectionClass(ExtractionController::class);
		$controller = $reflection->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(ExtractionController::class, 'importDirectory');

		try {
			$method->invoke($controller, $source, $target);
		} finally {
			$this->removeDirectory($source);
		}

		self::assertContains(['dir', 'sub'], $created);
		self::assertContains(['file', 'hello.txt', 'hello world'], $created);
		self::assertContains(['file', 'sub/inside.txt', 'nested content'], $created);

		// The parent directory must be created before the file it contains.
		$dirIndex = array_search(['dir', 'sub'], $created, true);
		$fileIndex = array_search(['file', 'sub/inside.txt', 'nested content'], $created, true);
		self::assertLessThan($fileIndex, $dirIndex);
	}

	/**
	 * Forbidden file names are removed before the tree is published.
	 *
	 * The walk must skip the `.` and `..` entries: the filename validator rejects
	 * them, and the removal then fails because unlink() cannot remove a directory.
	 */
	public function testRemoveForbiddenFilesSkipsDotEntries(): void {
		$source = sys_get_temp_dir() . '/extract-forbidden-' . bin2hex(random_bytes(6));
		mkdir($source . '/sub', 0700, true);
		file_put_contents($source . '/keep.txt', 'keep');
		file_put_contents($source . '/.htaccess', 'forbidden');
		file_put_contents($source . '/sub/.htaccess', 'forbidden');

		$validator = $this->createMock(IFilenameValidator::class);
		$validator->method('isFilenameValid')->willReturnCallback(
			// The real validator rejects `.` and `..` as dot directories, so the
			// stub must too, otherwise it would hide a walk that visits them.
			static fn (string $name): bool => $name !== '.htaccess' && $name !== '.' && $name !== '..',
		);

		$reflection = new \ReflectionClass(ExtractionController::class);
		$controller = $reflection->newInstanceWithoutConstructor();
		$this->setProperty($controller, 'filenameValidator', $validator);
		$this->setProperty($controller, 'logger', $this->createMock(LoggerInterface::class));

		$method = new \ReflectionMethod(ExtractionController::class, 'removeForbiddenFiles');

		try {
			$method->invoke($controller, $source);

			self::assertFileExists($source . '/keep.txt');
			self::assertFileDoesNotExist($source . '/.htaccess');
			self::assertFileDoesNotExist($source . '/sub/.htaccess');
			self::assertDirectoryExists($source . '/sub');
		} finally {
			$this->removeDirectory($source);
		}
	}

	/**
	 * Inject a private property on the controller under test.
	 *
	 * @param object $controller The controller instance.
	 * @param string $name The property name.
	 * @param object $value The value to assign.
	 */
	private function setProperty(object $controller, string $name, object $value): void {
		$property = new \ReflectionProperty(ExtractionController::class, $name);
		$property->setValue($controller, $value);
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
	 * The frontend gates the action on its own copy of the archive MIME list, so
	 * the two lists must not drift apart.
	 */
	public function testTheFrontendAndBackendMimeListsAgree(): void {
		$action = file_get_contents(__DIR__ . '/../../src/actions/extract-action.ts');
		self::assertIsString($action);

		preg_match('/SUPPORTED_ARCHIVE_MIME_TYPES\s*=\s*\[(.*?)\]/s', $action, $matches);
		self::assertNotEmpty($matches, 'Could not find SUPPORTED_ARCHIVE_MIME_TYPES in the file action');

		preg_match_all("/'([^']+)'/", $matches[1], $frontendMatches);
		$frontend = $frontendMatches[1];
		self::assertNotEmpty($frontend, 'The frontend MIME list is empty');

		$backend = array_keys(ExtractionController::SUPPORTED_MIME_TYPES);

		sort($frontend);
		sort($backend);

		self::assertSame(
			$backend,
			$frontend,
			'The frontend and backend archive MIME lists have drifted apart',
		);
	}
}
