<?php

namespace OCA\Extract\Controller;

/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\Extract\ResponseDefinitions;

use OCA\Extract\Service\ExtractionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;

use OCP\Files\InvalidPathException;

use OCP\Files\IRootFolder;

use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * @psalm-import-type ExtractFolder from ResponseDefinitions
 */
final class ExtractionController extends AEnvironmentAwareController {

	/**
	 * Archive MIME types the app can extract, mapped to the extraction strategy.
	 *
	 * The keys must cover every MIME type Nextcloud resolves for the supported
	 * archive extensions (see resources/config/mimetypemapping.dist.json), so a
	 * file selected in the Files app always maps to a strategy. `application/gzip`
	 * is what Nextcloud reports for .gz and .tgz; `application/x-gzip` is kept
	 * because other tooling still emits it.
	 */
	public const SUPPORTED_MIME_TYPES = [
		'application/zip' => 'zip',
		'application/x-rar-compressed' => 'rar',
		'application/x-tar' => 'other',
		'application/gzip' => 'other',
		'application/x-gzip' => 'other',
		'application/x-7z-compressed' => 'other',
		'application/x-bzip2' => 'other',
		'application/x-deb' => 'other',
		'application/x-compressed' => 'other',
	];

	/**
	 * File-name suffixes of tarballs that are wrapped in a compression layer.
	 *
	 * These need two passes: first the wrapper (gzip, bzip2, xz) is unwrapped,
	 * then the inner tar is expanded. A plain `.tar` is deliberately absent: 7za
	 * expands it in a single pass, so treating it as wrapped would make the
	 * controller look for a second archive inside the extracted payload.
	 */
	private const WRAPPED_TARBALL_EXTENSIONS = ['tar.gz', 'tgz', 'tar.bz2', 'tbz', 'tbz2', 'tar.xz', 'txz'];

	/** @var Folder */
	private $userFolder;

	public function __construct(
		string $AppName,
		IRequest $request,
		private ExtractionService $extractionService,
		private IRootFolder $rootFolder,
		private IL10N $l,
		private LoggerInterface $logger,
		private string $userId,
		private IURLGenerator $urlGenerator,
		private IFilenameValidator $filenameValidator,
		private ITempManager $tempManager,
	) {
		parent::__construct($AppName, $request);
		$this->userFolder = $this->rootFolder->getUserFolder($this->userId);
	}

	private function getFile(string $directory, string $fileName): string|false {
		$fileNode = $this->userFolder->get($directory . '/' . $fileName);
		return $fileNode->getStorage()->getLocalFile($fileNode->getInternalPath());
	}

	/**
	 * Whether a file name refers to a tar wrapped in a compression layer.
	 *
	 * @param string $fileName The Nextcloud file name.
	 */
	private static function isWrappedTarball(string $fileName): bool {
		$lower = strtolower($fileName);
		foreach (self::WRAPPED_TARBALL_EXTENSIONS as $extension) {
			if (str_ends_with($lower, '.' . $extension) === true) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Name of the folder the extraction result is published under.
	 *
	 * Compressed tarballs lose both suffixes: `archive.tar.gz` becomes
	 * `archive`, matching the folder a plain `archive.tar` produces. 7za names
	 * the inner tar after the first suffix, so the name cannot be taken from the
	 * extracted file.
	 *
	 * @param string $fileName The Nextcloud file name.
	 */
	private static function resultFolderName(string $fileName): string {
		$lower = strtolower($fileName);
		foreach (self::WRAPPED_TARBALL_EXTENSIONS as $extension) {
			if (str_ends_with($lower, '.' . $extension) === true) {
				return substr($fileName, 0, -strlen($extension) - 1);
			}
		}
		if (str_ends_with($lower, '.tar') === true) {
			return substr($fileName, 0, -4);
		}
		return pathinfo($fileName, PATHINFO_FILENAME);
	}

	/**
	 * Whether a result-folder name is usable.
	 *
	 * The name must be a plain single path component. Stripping the suffixes
	 * from a crafted name can leave a path component behind:
	 *
	 *   `.tar.gz`   -> ''    (destination would be the containing directory)
	 *   `..tar.gz`  -> '.'   (destination would be the containing directory)
	 *   `...tar.gz` -> '..'  (destination would be the parent directory)
	 *
	 * Rejecting anything that is empty, `.` or `..` keeps the destination inside
	 * the folder the archive lives in.
	 *
	 * @param string $folderName The candidate folder name.
	 */
	private static function isUsableFolderName(string $folderName): bool {
		$trimmed = trim($folderName);
		if ($trimmed === '' || $trimmed === '.' || $trimmed === '..') {
			return false;
		}

		// A separator would also escape the containing folder.
		return str_contains($trimmed, '/') === false && str_contains($trimmed, '\\') === false;
	}

	/**
	 * Extract a tar that is wrapped in a compression layer.
	 *
	 * The wrapper is unwrapped into a request-owned scratch directory first,
	 * then the inner tar is expanded into the destination. The inner tar is
	 * found by scanning the scratch directory because 7za names it from the
	 * compression header rather than from the archive file name.
	 *
	 * @param string $file The local file-system path of the archive.
	 * @param string $extractTo The local file-system path of the destination.
	 * @return array{code: 0|1, desc?: string}
	 */
	private function extractWrappedTarball(string $file, string $extractTo): array {
		// A unique scratch directory: a predictable sibling name could collide
		// with, and then delete, an unrelated folder in the same directory.
		$scratch = $extractTo . '.unwrapped.' . bin2hex(random_bytes(8));

		$response = $this->extractionService->extractOther($file, $scratch);
		if (($response['code'] ?? 0) === 0) {
			$this->removeDirectory($scratch);
			return $response;
		}

		$innerTar = $this->findSingleFile($scratch);
		if ($innerTar === null) {
			$this->logger->error(__METHOD__ . ': no inner tar found in ' . $scratch);
			$this->removeDirectory($scratch);
			return ['code' => 0, 'desc' => $this->l->t('Oops something went wrong.')];
		}

		$response = $this->extractionService->extractOther($innerTar, $extractTo);
		$this->removeDirectory($scratch);

		return $response;
	}

	/**
	 * Return the only file inside a directory, or null when there is not
	 * exactly one file.
	 *
	 * @param string $directory The directory to inspect.
	 */
	private function findSingleFile(string $directory): ?string {
		if (is_dir($directory) === false) {
			return null;
		}

		$files = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
		);
		foreach ($iterator as $entry) {
			/** @var \SplFileInfo $entry */
			if ($entry->isFile() === true) {
				$files[] = $entry->getPathname();
			}
		}

		return count($files) === 1 ? $files[0] : null;
	}

	/**
	 * Recursively remove a directory, ignoring a missing one.
	 *
	 * @param string $directory The directory to remove.
	 */
	private function removeDirectory(string $directory): void {
		if (is_dir($directory) === false) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($iterator as $entry) {
			/** @var \SplFileInfo $entry */
			if ($entry->isDir() === true) {
				rmdir($entry->getPathname());
			} else {
				unlink($entry->getPathname());
			}
		}
		rmdir($directory);
	}

	/**
	 * Register the new files to the NC filesystem.
	 *
	 * @param string $fileName The Nextcloud file name.
	 *
	 * @param string $directory The Nextcloud directory name.
	 *
	 * @param string $extractTo The local file-system path of the directory
	 *                          with the extracted data, i.e. this is the OS path.
	 *
	 * @param null|string $tmpPath The Nextcloud temporary path. This is only
	 *                             non-null when extracting from external storage.
	 *
	 * @param bool $importFromScratch Whether the payload has to be imported through
	 *                                the Nextcloud filesystem instead of being read
	 *                                from the data directory.
	 */
	private function postExtract(string $fileName, string $directory, string $extractTo, ?string $tmpPath, bool $importFromScratch = false): void {
		$this->removeForbiddenFiles($extractTo);

		$NCDestination = $directory . '/' . $fileName;
		if ($importFromScratch === true) {
			$this->importDirectory($extractTo, $this->destinationFolder($directory, $fileName));
		} elseif ($tmpPath !== null) {
			$tmpFolder = $this->rootFolder->get($tmpPath);
			$tmpFolder->move($this->userFolder->getFullPath($NCDestination));
		} else {
			// This seems to be enough to trigger a files-cache refresh
			$this->userFolder->get($NCDestination);
		}
	}

	/**
	 * Delete every extracted file whose name Nextcloud forbids.
	 *
	 * @param string $directory The local file-system path of the extracted tree.
	 */
	private function removeForbiddenFiles(string $directory): void {
		// SKIP_DOTS matters: without it the iterator yields `.` and `..` for every
		// directory in the tree, which isFilenameValid() rejects and unlink() then
		// fails on.
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
		);
		foreach ($iterator as $file) {
			/** @var \SplFileInfo $file */
			if (!$this->filenameValidator->isFilenameValid($file->getBasename())) {
				$this->logger->warning(__METHOD__ . ': removing blacklisted file: ' . $file->getPathname());
				unlink($file->getPathname());
			}
		}
	}

	/**
	 * Resolve the folder the extracted tree is imported into, creating it if needed.
	 *
	 * The local path lets the extraction merge into an existing folder, so the
	 * import must do the same instead of failing when a folder of that name is
	 * already present.
	 *
	 * @param string $directory The Nextcloud directory holding the archive.
	 * @param string $fileName The result-folder name.
	 */
	private function destinationFolder(string $directory, string $fileName): Folder {
		$parent = $this->userFolder->get($directory);
		if ($parent instanceof Folder === false) {
			throw new NotPermittedException('The archive is not inside a folder');
		}
		if ($parent->nodeExists($fileName) === true) {
			$existing = $parent->get($fileName);
			if ($existing instanceof Folder === false) {
				throw new NotPermittedException($fileName . ' already exists and is not a folder');
			}
			return $existing;
		}

		return $parent->newFolder($fileName);
	}

	/**
	 * Copy an extracted tree into a Nextcloud folder through the files API.
	 *
	 * This is the import step for destinations where the archive cannot be unpacked
	 * beside the original file (object storage, or server-side encryption). Every
	 * entry is created with the files API, so the files-cache entries and the write
	 * hooks are produced by the copy itself instead of relying on the storage being
	 * scanned afterwards.
	 *
	 * Directories are visited before their contents, so a parent always exists when
	 * its children are written.
	 *
	 * @param string $source The local file-system path of the extracted tree.
	 * @param Folder $target The Nextcloud folder that receives the tree's contents.
	 */
	private function importDirectory(string $source, Folder $target): void {
		$source = rtrim($source, '/');
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
		);
		foreach ($iterator as $entry) {
			/** @var \SplFileInfo $entry */
			$relative = ltrim(substr($entry->getPathname(), strlen($source)), '/');
			if ($entry->isDir() === true) {
				$this->importFolder($target, $relative);
				continue;
			}

			$this->importFile($target, $relative, $entry->getPathname());
		}
	}

	/**
	 * Return the folder at $relative, creating it when it does not exist yet.
	 *
	 * Merging into an existing folder mirrors the local path, where the extraction
	 * unpacks into whatever folder already sits at the destination name.
	 *
	 * @param Folder $target The folder that receives the tree.
	 * @param string $relative The path of the folder within $target.
	 */
	private function importFolder(Folder $target, string $relative): Folder {
		if ($target->nodeExists($relative) === true) {
			$existing = $target->get($relative);
			if ($existing instanceof Folder === false) {
				throw new NotPermittedException($relative . ' exists and is not a folder');
			}
			return $existing;
		}

		return $target->newFolder($relative);
	}

	/**
	 * Write one extracted file into the target folder.
	 *
	 * The stream is handed to the files API so a large file is not read into memory.
	 * The API closes it as part of the write; the guard here covers the path where it
	 * does not, so the handle never leaks or gets closed twice.
	 *
	 * @param Folder $target The folder that receives the tree.
	 * @param string $relative The path of the file within $target.
	 * @param string $path The local file-system path of the extracted file.
	 */
	private function importFile(Folder $target, string $relative, string $path): void {
		$handle = fopen($path, 'rb');
		if ($handle === false) {
			throw new NotPermittedException('Could not read ' . $path);
		}
		try {
			$target->newFile($relative, $handle);
		} finally {
			if (is_resource($handle) === true) {
				fclose($handle);
			}
		}
	}

	/**
	 * The only AJAX callback. This is a hook for ordinary cloud-users, os no admin required
	 *
	 * @param string $nameOfFile Name of the file to be extracted
	 * @param string $directory Directory where the file is located
	 * @param bool $external Is the file located on an external storage?
	 * @param string $mime MIME type of the file
	 * @return DataResponse<Http::STATUS_OK, array{code?: 0|1, desc?: string, extracted?: ExtractFolder}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array<empty>, array{}>
	 *
	 * 200: OK
	 * 404: Not found or invalid path
	 */
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/extraction/execute', requirements: ['apiVersion' => '(v1)'])]
	#[NoAdminRequired]
	public function execute(
		string $nameOfFile,
		string $directory,
		bool $external,
		string $mime,
	): DataResponse {
		if (isset(self::SUPPORTED_MIME_TYPES[$mime]) === false) {
			return new DataResponse(['code' => 0, 'desc' => $this->l->t('Unsupported archive type')]);
		}

		$type = self::SUPPORTED_MIME_TYPES[$mime];

		// Reject a name that reduces to nothing (for example `.tar.gz`) before any
		// work: the destination would otherwise be the containing directory.
		$resultFolder = self::resultFolderName($nameOfFile);
		if (self::isUsableFolderName($resultFolder) === false) {
			return new DataResponse(['code' => 0, 'desc' => $this->l->t('Unsupported archive type')]);
		}

		try {
			$archiveNode = $this->userFolder->get($directory . '/' . $nameOfFile);
		} catch (NotFoundException $e) {
			return new DataResponse(['code' => 0, 'desc' => $this->l->t('File not found')]);
		}

		// The extraction unpacks the archive next to the original file and then
		// relies on Nextcloud seeing the result in the data directory. That only
		// works when the destination is real local storage, because getLocalFile()
		// then returns a path inside the data directory.
		//
		// On object storage, or with server-side encryption enabled, getLocalFile()
		// returns a temporary copy instead (Storage\Wrapper\Encryption::getLocalFile()
		// falls through to LocalTempFileTrait::getCachedFile(), and Common::isLocal()
		// documents that "the common implementation returns a temporary file by
		// default"). Unpacking beside such a path would land outside the data
		// directory and nothing would be published. Unpack into a request-owned local
		// directory and import the tree through the Nextcloud filesystem instead.
		// Folder the result is published under: both suffixes are dropped for a
		// compressed tarball, so archive.tar.gz and archive.tar both become
		// archive. For every other format this is the name without extension.
		$fileName = $resultFolder;
		$file = $this->getFile($directory, $nameOfFile);
		if ($file === false) {
			$response = ['code' => 0, 'desc' => $this->l->t('File not found')];
			return new DataResponse($response);
		}

		$destinationStorage = $external === true
			? $this->userFolder->getStorage()
			: $archiveNode->getStorage();
		$importFromScratch = $destinationStorage->isLocal() === false;

		$scratch = null;
		if ($importFromScratch === true) {
			$scratch = $this->tempManager->getTemporaryFolder();
			if ($scratch === false) {
				$this->logger->error(__METHOD__ . ': could not create a temporary extraction directory');
				return new DataResponse(['code' => 0, 'desc' => $this->l->t('Oops something went wrong.')]);
			}
			$tmpPath = null;
			$extractTo = rtrim($scratch, '/') . '/' . $fileName;
		} else {
			$extractTo = dirname($file) . '/' . $fileName;

			// if the file is an external storage
			if ($external) {
				$appPath = $this->userId . '/' . $this->appName;
				try {
					$appDirectory = $this->rootFolder->get($appPath);
				} catch (\OCP\Files\NotFoundException $e) {
					$appDirectory = $this->rootFolder->newFolder($appPath);
				}
				$archiveDir = $fileName;

				// remove temporary directory if exists from interrupted previous runs
				try {
					$appDirectory->get($archiveDir)->delete();
				} catch (\OCP\Files\NotFoundException $e) {
					// ok
				}

				$tmpPath = $appDirectory->getPath() . '/' . $archiveDir;
				$extractTo = $appDirectory->getStorage()->getLocalFile($appDirectory->getInternalPath()) . '/' . $archiveDir;
			} else {
				$tmpPath = null;
			}
		}

		switch ($type) {
			case 'zip':
				$response = $this->extractionService->extractZip($file, $extractTo);
				break;
			case 'rar':
				$response = $this->extractionService->extractRar($file, $extractTo);
				break;
			default:
				if (self::isWrappedTarball($nameOfFile) === true) {
					$response = $this->extractWrappedTarball($file, $extractTo);
				} else {
					// A plain .tar and every non-tar format is a single 7za pass.
					$response = $this->extractionService->extractOther($file, $extractTo);
				}
				break;
		}

		// Report the failure as the service described it instead of running the
		// post-processing over a destination that was never created.
		if (($response['code'] ?? 0) === 0) {
			if ($scratch !== null) {
				$this->removeDirectory($scratch);
			}
			return new DataResponse($response);
		}

		try {
			$this->postExtract($fileName, $directory, $extractTo, $tmpPath, $importFromScratch);
		} catch (NotFoundException|NotPermittedException|InvalidPathException $e) {
			$this->logger->error(__METHOD__ . ': could not publish the extracted folder: ' . $e->getMessage());
			return new DataResponse(['code' => 0, 'desc' => $this->l->t('Oops something went wrong.')]);
		} finally {
			if ($scratch !== null) {
				$this->removeDirectory($scratch);
			}
		}

		try {
			// collect and return the properties of the resulting folder node
			$extractDir = '/' . trim($directory . '/' . $fileName, '/');
			$node = $this->userFolder->get($extractDir);
			$fileId = $node->getId();
			$owner = $node->getOwner()?->getUID();
			$permissions = $node->getPermissions();
			$mTime = $node->getMTime();
			$source = $this->urlGenerator->getBaseUrl() . '/remote.php/dav/files/' . $this->userId . "$extractDir";
			$root = '/files/' . $this->userId;
			$mountType = $node->getMountPoint()->getMountType();
			$ownerDisplayName = $node->getOwner()?->getDisplayName();

			$folder = [];
			$folder['fileId'] = $fileId;
			$folder['source'] = $source;
			$folder['root'] = $root;
			$folder['owner'] = $owner;
			$folder['permissions'] = $permissions;
			$folder['mtime'] = $mTime;
			$folder['mount-type'] = $mountType;
			$folder['owner-display-name'] = $ownerDisplayName;
			$response['extracted'] = $folder;

		} catch (NotFoundException $e) {
			$this->logger->debug(' - NotFoundException: ' . print_r($e->getMessage(), true));
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		} catch (InvalidPathException $e) {
			$this->logger->debug(' - InvalidPathException: ' . print_r($e->getMessage(), true));
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse($response);
	}
}
