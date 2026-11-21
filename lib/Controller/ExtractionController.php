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
use OCP\Files\File;
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
	 * Validate a destination relative to the archive's containing folder.
	 *
	 * Each component is validated individually; a name may contain subfolders,
	 * but cannot address the parent folder, an absolute path, or a control byte.
	 */
	private static function isUsableRelativeDestinationPath(string $path): bool {
		foreach (explode('/', $path) as $component) {
			if (self::isUsableFolderName($component) === false
				|| preg_match('/[\x00-\x1f\x7f]/', $component) === 1) {
				return false;
			}
		}
		return true;
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
			// Never follow a link to an archive outside our staging directory.
			if ($entry->isLink()) {
				return null;
			}
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
			// A symlink to a directory must be unlinked, not rmdir()'d.
			if ($entry->isDir() === true && $entry->isLink() === false) {
				rmdir($entry->getPathname());
			} else {
				unlink($entry->getPathname());
			}
		}
		rmdir($directory);
	}

	/**
	 * Validate and publish extracted files through the Nextcloud files API.
	 */
	private function postExtract(string $fileName, string $directory, string $extractTo, bool $rejectExisting = false): void {
		$this->assertNoSymbolicLinks($extractTo);
		$this->assertValidExtractedNames($extractTo);

		// Only a newly created destination belongs entirely to this operation.
		// Never delete an existing folder, which legacy clients may merge into.
		$destinationExisted = $this->userFolder->get($directory)->nodeExists($fileName);
		$target = $this->destinationFolder($directory, $fileName, $rejectExisting);
		try {
			$this->importDirectory($extractTo, $target);
		} catch (\Throwable $e) {
			if (!$destinationExisted) {
				try {
					$target->delete();
				} catch (\Throwable $cleanupError) {
					$this->logger->error('Could not remove a partially imported extraction: ' . $cleanupError->getMessage());
				}
			}
			$this->logger->error('Extraction import failed: ' . $e->getMessage());
			throw new NotPermittedException('Could not complete extraction');
		}
	}

	/**
	 * Reject links before importing any content from the extraction staging area.
	 */
	private function assertNoSymbolicLinks(string $directory): void {
		if (is_dir($directory) === false) {
			throw new NotPermittedException('The extraction result does not exist');
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
		);
		foreach ($iterator as $entry) {
			if ($entry->isLink()) {
				throw new NotPermittedException('Symbolic links are not supported in extracted archives');
			}
		}
	}

	/**
	 * Check every file and directory component before any data is published.
	 * Silently removing an invalid name can report success for a partial archive,
	 * which users may mistake for a complete backup extraction.
	 *
	 * @param string $directory The local file-system path of the extracted tree.
	 */
	private function assertValidExtractedNames(string $directory): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
		);
		foreach ($iterator as $entry) {
			/** @var \SplFileInfo $entry */
			$name = $entry->getBasename();
			if (preg_match('//u', $name) !== 1 || !$this->filenameValidator->isFilenameValid($name)) {
				throw new NotPermittedException('Archive contains filenames that Nextcloud cannot store');
			}
		}
	}

	/**
	 * Resolve the folder the extracted tree is imported into, creating it if needed.
	 *
	 * Clients using the implicit legacy destination may merge into an existing
	 * folder. An explicitly chosen destination must be new, and its final
	 * component is checked again before any import begins.
	 *
	 * @param string $directory The Nextcloud directory holding the archive.
	 * @param string $fileName The result-folder name.
	 */
	private function destinationFolder(string $directory, string $fileName, bool $rejectExisting = false): Folder {
		$parent = $this->userFolder->get($directory);
		if ($parent instanceof Folder === false || $parent->isCreatable() === false) {
			throw new NotPermittedException('No permission to create files in the destination folder');
		}

		$components = explode('/', $fileName);
		$last = count($components) - 1;
		foreach ($components as $index => $component) {
			if ($parent->nodeExists($component) === true) {
				if ($rejectExisting === true && $index === $last) {
					throw new NotPermittedException('Destination folder already exists');
				}
				$existing = $parent->get($component);
				if ($existing instanceof Folder === false || $existing->isCreatable() === false) {
					throw new NotPermittedException($component . ' is not a writable folder');
				}
				$parent = $existing;
			} else {
				$parent = $parent->newFolder($component);
			}
		}

		return $parent;
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
	 * @param bool $external Retained for backward compatibility; storage is resolved server-side.
	 * @param string $mime MIME type of the file
	 * @param ?string $targetDirName Optional relative folder path under the archive directory
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
		?string $targetDirName = null,
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

		// Explicit destinations may include subfolders, but never escape the
		// archive's directory. The server validates again after frontend checks.
		$fileName = $targetDirName === null ? $resultFolder : trim($targetDirName);
		if (self::isUsableRelativeDestinationPath($fileName) === false) {
			return new DataResponse(['code' => 0, 'desc' => $this->l->t('Invalid extraction folder name')]);
		}
		if ($targetDirName !== null) {
			foreach (explode('/', $fileName) as $component) {
				if ($this->filenameValidator->isFilenameValid($component) === false) {
					return new DataResponse(['code' => 0, 'desc' => $this->l->t('Invalid extraction folder name')]);
				}
			}
		}

		try {
			$archiveNode = $this->userFolder->get($directory . '/' . $nameOfFile);
			$destinationParent = $this->userFolder->get($directory);
		} catch (NotFoundException|NotPermittedException $e) {
			return new DataResponse(['code' => 0, 'desc' => $this->l->t('File not found')]);
		}
		if ($destinationParent instanceof Folder === false || $destinationParent->isCreatable() === false) {
			return new DataResponse(['code' => 0, 'desc' => $this->l->t('You do not have permission to extract here')]);
		}
		// An explicit destination must not overwrite an existing folder.
		// Keep legacy clients (which omit targetDirName) compatible.
		if ($targetDirName !== null && $destinationParent->nodeExists($fileName) === true) {
			return new DataResponse(['code' => 0, 'desc' => $this->l->t('Destination folder already exists')]);
		}

		// Never write extracted files directly into a mounted storage path.
		// Untrusted archives are unpacked into an isolated temporary directory
		// and imported through Nextcloud so file permissions and hooks apply.
		// The archive was already resolved above. Never attempt to extract a
		// folder masquerading as an archive or resolve the node twice.
		if ($archiveNode instanceof File === false) {
			return new DataResponse(['code' => 0, 'desc' => $this->l->t('File not found')]);
		}
		try {
			$file = $archiveNode->getStorage()->getLocalFile($archiveNode->getInternalPath());
		} catch (NotFoundException|NotPermittedException $e) {
			return new DataResponse(['code' => 0, 'desc' => $this->l->t('File not found')]);
		}
		if ($file === false) {
			return new DataResponse(['code' => 0, 'desc' => $this->l->t('File not found')]);
		}

		$scratch = $this->tempManager->getTemporaryFolder();
		if ($scratch === false) {
			$this->logger->error(__METHOD__ . ': could not create a temporary extraction directory');
			return new DataResponse(['code' => 0, 'desc' => $this->l->t('Oops something went wrong.')]);
		}
		// Do not interpolate user-controlled names into operating-system paths.
		$extractTo = rtrim($scratch, '/') . '/payload';

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
			$this->postExtract($fileName, $directory, $extractTo, $targetDirName !== null);
		} catch (NotFoundException|NotPermittedException|InvalidPathException $e) {
			$this->logger->error(__METHOD__ . ': could not publish the extracted folder: ' . $e->getMessage());
			$description = $e->getMessage() === 'Archive contains filenames that Nextcloud cannot store'
				? $this->l->t('Archive contains filenames that Nextcloud cannot store')
				: $this->l->t('Oops something went wrong.');
			return new DataResponse(['code' => 0, 'desc' => $description]);
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
			// Files app nodes require an encoded DAV URL. A user-selected target
			// can contain spaces, #, ? or Unicode characters in any component.
			$encodedDir = implode('/', array_map('rawurlencode', explode('/', $extractDir)));
			$source = $this->urlGenerator->getBaseUrl() . '/remote.php/dav/files/' . rawurlencode($this->userId) . $encodedDir;
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
