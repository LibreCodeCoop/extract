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
use OCP\Encryption\IManager;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;

use OCP\Files\InvalidPathException;

use OCP\Files\IRootFolder;

use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
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
		private IManager $encryptionManager,
		private string $userId,
		private IURLGenerator $urlGenerator,
		private IFilenameValidator $filenameValidator,
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
	 */
	private function postExtract(string $fileName, string $directory, string $extractTo, ?string $tmpPath): void {

		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($extractTo));
		foreach ($iterator as $file) {
			/** @var \SplFileInfo $file */
			if (!$this->filenameValidator->isFilenameValid($file->getBasename())) {
				$this->logger->warning(__METHOD__ . ': removing blacklisted file: ' . $file->getPathname());
				// remove it
				unlink($file->getPathname());
			}
		}

		$NCDestination = $directory . '/' . $fileName;
		if ($tmpPath !== null) {
			$tmpFolder = $this->rootFolder->get($tmpPath);
			$tmpFolder->move($this->userFolder->getFullPath($NCDestination));
		} else {
			// This seems to be enough to trigger a files-cache refresh
			$this->userFolder->get($NCDestination);
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

		if ($this->encryptionManager->isEnabled()) {
			$response = [];
			$response = array_merge($response, ['code' => 0, 'desc' => $this->l->t('Encryption is not supported yet')]);
			return new DataResponse($response);
		}
		$file = $this->getFile($directory, $nameOfFile);
		if ($file === false) {
			$response = ['code' => 0, 'desc' => $this->l->t('File not found')];
			return new DataResponse($response);
		}
		$dir = dirname($file);
		// Folder the result is published under: both suffixes are dropped for a
		// compressed tarball, so archive.tar.gz and archive.tar both become
		// archive. For every other format this is the name without extension.
		$fileName = self::resultFolderName($nameOfFile);
		$extractTo = $dir . '/' . $fileName;

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
			return new DataResponse($response);
		}

		$this->postExtract($fileName, $directory, $extractTo, $tmpPath);

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
