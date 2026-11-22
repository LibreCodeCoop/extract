<?php

/**
 * SPDX-FileCopyrightText: 2012-2022 Paul Lereverend <paulereverend@gmail.com>
 * SPDX-FileCopyrightText: 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Service;

use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use ZipArchive;

final class ExtractionService {
	private const DEFAULT_MAX_EXTRACTED_BYTES = 2147483648; // 2 GiB
	private const DEFAULT_MAX_ARCHIVE_ENTRIES = 10000;
	private const MAX_ARCHIVE_LISTING_BYTES = 16777216;
	private const ARCHIVE_LIST_TIMEOUT_SECONDS = 20;

	public function __construct(
		private IL10N $l,
		private LoggerInterface $logger,
		private IConfig $config,
	) {
	}

	/**
	 * Reject archive names that may escape the extraction staging directory,
	 * including absolute, Windows drive and traversal paths.
	 */
	private function isUnsafePath(string $entry): bool {
		$path = str_replace('\\', '/', $entry);
		if (preg_match('/[\x00-\x1f\x7f]/', $entry) === 1) {
			return true;
		}
		if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path) === 1) {
			return true;
		}
		return preg_match('#(^|/)\.\.($|/)#', $path) === 1;
	}

	/** @return null|array{code: 0, desc: string} */
	private function rejectUnsafe(string $entry): ?array {
		if (!$this->isUnsafePath($entry)) {
			return null;
		}
		$this->logger->warning('Refusing archive with unsafe entry');
		return ['code' => 0, 'desc' => $this->l->t('Archive contains an unsafe path and was not extracted')];
	}

	/** @return null|array{code: 0, desc: string} */
	private function assertSafeZip(ZipArchive $zip): ?array {
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name === false) {
				return ['code' => 0, 'desc' => $this->l->t('Failed to read archive index')];
			}
			if ($error = $this->rejectUnsafe($name)) {
				return $error;
			}
		}
		return null;
	}

	/**
	 * Run archive listing with a bounded output size and an execution deadline.
	 *
	 * @param non-empty-list<string> $command
	 * @return ?list<string>
	 */
	private function listWithLimits(array $command): ?array {
		$result = ResourceLimitedProcess::capture(
			$command,
			self::MAX_ARCHIVE_LISTING_BYTES,
			self::ARCHIVE_LIST_TIMEOUT_SECONDS,
		);
		if (!$result['success']) {
			$this->logger->warning('Archive listing rejected: ' . $result['reason']);
			return null;
		}
		return explode("\n", $result['output']);
	}

	/**
	 * Refuse archive links and special nodes before starting any extractor.
	 * A periodic filesystem scan does not prevent intermediate unsafe writes.
	 *
	 * @return null|array{code: 0, desc: string}
	 */
	private function rejectUnsafeMetadata(string $line): ?array {
		if (preg_match('/^(?:Symbolic Link|Hard Link|Junction|Reparse Point)\s*=\s*\S/i', $line) !== 1
			&& !(str_starts_with($line, 'Attributes = ')
				&& preg_match('/(?:^|\s)[lbpcs][-rwxstST]{9}(?:\s|$)/', $line) === 1)) {
			return null;
		}
		$this->logger->warning('Refusing archive with links or special files');
		return ['code' => 0, 'desc' => $this->l->t('Archive contains links or unsupported special files')];
	}

	/** @return null|array{code: 0, desc: string} */
	private function assertSafeRarShell(string $file): ?array {
		$output = $this->listWithLimits(['unrar', 'lb', $file]);
		if ($output === null) {
			return ['code' => 0, 'desc' => $this->l->t('Failed to inspect RAR contents')];
		}
		foreach ($output as $entry) {
			$entry = rtrim($entry, "\r");
			if ($entry !== '' && ($error = $this->rejectUnsafe($entry))) {
				return $error;
			}
		}
		// The plain filename list does not reveal links. Inspect entry types
		// before extraction, using 7z technical metadata when available.
		if ($this->isCommandAvailable('7z')) {
			return $this->assertSafeRarSevenZip($file);
		}
		$details = $this->listWithLimits(['unrar', 'lt', $file]);
		if ($details === null) {
			return ['code' => 0, 'desc' => $this->l->t('Failed to inspect RAR contents')];
		}
		$types = 0;
		foreach ($details as $line) {
			if (preg_match('/^\s*Type\s*:\s*(.+)$/i', $line, $match) === 1) {
				$types++;
				if (preg_match('/^(?:File|Directory)$/i', trim($match[1])) !== 1) {
					return ['code' => 0, 'desc' => $this->l->t('Archive contains links or unsupported special files')];
				}
			}
		}
		if ($types === 0) {
			return ['code' => 0, 'desc' => $this->l->t('Could not verify RAR entry types')];
		}
		return null;
	}

	/**
	 * Validate 7za archive paths and reject metadata announcing extraction
	 * beyond the available quota, staging space, or configured resource caps.
	 * The external decoder may still misreport sizes; this is a preflight,
	 * not a substitute for process-level disk isolation.
	 *
	 * @return null|array{code: 0, desc: string}
	 */
	private function assertSafe7z(string $file, ?int $availableBytes, ?int $temporarySpace): ?array {
		$output = $this->listWithLimits(['7za', 'l', '-ba', '-slt', $file]);
		if ($output === null) {
			return ['code' => 0, 'desc' => $this->l->t('Failed to inspect archive contents')];
		}
		$budget = $this->configuredLimit('max_extracted_bytes', self::DEFAULT_MAX_EXTRACTED_BYTES);
		if ($availableBytes !== null && $availableBytes >= 0) {
			$budget = min($budget, $availableBytes);
		}
		if ($temporarySpace !== null && $temporarySpace >= 0) {
			$budget = min($budget, $temporarySpace);
		}
		$entryLimit = $this->configuredLimit('max_archive_entries', self::DEFAULT_MAX_ARCHIVE_ENTRIES);
		$entries = 0;
		$size = 0;
		foreach ($output as $line) {
			$line = rtrim($line, "\r");
			if ($error = $this->rejectUnsafeMetadata($line)) {
				return $error;
			}
			if (str_starts_with($line, 'Path = ')) {
				if ($error = $this->rejectUnsafe(substr($line, 7))) {
					return $error;
				}
				$entries++;
				if ($entries > $entryLimit) {
					return ['code' => 0, 'desc' => $this->l->t('Archive contains too many files')];
				}
			} elseif (str_starts_with($line, 'Size = ')) {
				$declaredSize = filter_var(trim(substr($line, 7)), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
				if ($declaredSize === false) {
					return ['code' => 0, 'desc' => $this->l->t('Failed to inspect archive contents')];
				}
				if ($declaredSize > $budget - $size) {
					return ['code' => 0, 'desc' => $this->l->t('Archive exceeds the allowed extraction size')];
				}
				$size += $declaredSize;
			}
		}
		return null;
	}

	/**
	 * Read a positive integer resource limit from application configuration.
	 * Invalid or overflowing values are ignored instead of disabling protection.
	 */
	private function configuredLimit(string $key, int $default): int {
		$value = $this->config->getAppValue('extract', $key, (string)$default);
		if (!is_string($value) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
			return $default;
		}
		return (int)$value;
	}

	/**
	 * Extract ZIP entries through bounded streams, never via an unbounded
	 * ZipArchive::extractTo(). The quota estimate is checked before opening a
	 * stream and the actual byte count is enforced while writing to staging.
	 *
	 * @param ?int $availableBytes Remaining destination quota, or negative if
	 *                             Nextcloud reports unlimited/unknown space.
	 * @return array{code: 0|1, desc?: string}
	 */
	public function extractZip(string $file, string $extractTo, ?int $availableBytes = null): array {
		if (!extension_loaded('zip')) {
			return ['code' => 0, 'desc' => $this->l->t('Zip extension is not available')];
		}

		$zip = new ZipArchive();
		if ($zip->open($file) !== true) {
			return ['code' => 0, 'desc' => $this->l->t('Cannot open Zip file')];
		}

		try {
			if ($error = $this->assertSafeZip($zip)) {
				return $error;
			}

			$limit = $this->configuredLimit('max_extracted_bytes', self::DEFAULT_MAX_EXTRACTED_BYTES);
			if ($availableBytes !== null && $availableBytes >= 0) {
				$limit = min($limit, $availableBytes);
			}
			$temporarySpace = disk_free_space(dirname($extractTo));
			if ($temporarySpace !== false) {
				$limit = min($limit, (int)$temporarySpace);
			}
			if ($zip->numFiles > $this->configuredLimit('max_archive_entries', self::DEFAULT_MAX_ARCHIVE_ENTRIES)) {
				return ['code' => 0, 'desc' => $this->l->t('Archive contains too many files')];
			}

			// Check declared sizes before creating any staging content. Streaming
			// below also enforces the actual size if metadata is dishonest.
			$declared = 0;
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$stat = $zip->statIndex($i);
				if ($stat === false || !isset($stat['size']) || !is_int($stat['size']) || $stat['size'] < 0) {
					return ['code' => 0, 'desc' => $this->l->t('Failed to inspect Zip contents')];
				}
				if ($stat['size'] > $limit - $declared) {
					return ['code' => 0, 'desc' => $this->l->t('Archive exceeds the allowed extraction size')];
				}
				$declared += $stat['size'];
			}

			if (!is_dir($extractTo) && !mkdir($extractTo, 0700, true)) {
				return ['code' => 0, 'desc' => $this->l->t('Could not prepare extraction directory')];
			}

			$written = 0;
			$seen = [];
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = $zip->getNameIndex($i);
				if ($name === false || $name === '' || preg_match('//u', $name) !== 1) {
					return ['code' => 0, 'desc' => $this->l->t('Archive contains an invalid filename')];
				}
				$relative = rtrim($name, '/');
				if (isset($seen[$relative])) {
					return ['code' => 0, 'desc' => $this->l->t('Archive contains duplicate filenames')];
				}
				$seen[$relative] = true;
				$path = $extractTo . '/' . $relative;
				if (str_ends_with($name, '/')) {
					if (!is_dir($path) && !mkdir($path, 0700, true)) {
						return ['code' => 0, 'desc' => $this->l->t('Could not extract Zip archive')];
					}
					continue;
				}
				if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) {
					return ['code' => 0, 'desc' => $this->l->t('Could not extract Zip archive')];
				}
				$input = $zip->getStreamIndex($i);
				if ($input === false) {
					return ['code' => 0, 'desc' => $this->l->t('Could not extract Zip archive')];
				}
				try {
					$output = fopen($path, 'wb');
					if ($output === false) {
						return ['code' => 0, 'desc' => $this->l->t('Could not extract Zip archive')];
					}
					try {
						while (!feof($input)) {
							$chunk = fread($input, 65536);
							if ($chunk === false || ($chunk === '' && !feof($input))) {
								return ['code' => 0, 'desc' => $this->l->t('Could not extract Zip archive')];
							}
							if (strlen($chunk) > $limit - $written) {
								return ['code' => 0, 'desc' => $this->l->t('Archive exceeds the allowed extraction size')];
							}
							$written += strlen($chunk);
							$offset = 0;
							while ($offset < strlen($chunk)) {
								$count = fwrite($output, substr($chunk, $offset));
								if ($count === false || $count === 0) {
									return ['code' => 0, 'desc' => $this->l->t('Could not extract Zip archive')];
								}
								$offset += $count;
							}
						}
					} finally {
						fclose($output);
					}
				} finally {
					fclose($input);
				}
			}
			return ['code' => 1];
		} finally {
			$zip->close();
		}
	}

	/**
	 * @return (int|mixed)[]
	 *
	 * @psalm-return array{code: 0|1, desc?: string}
	 */
	/**
	 * Run a command-line decoder with periodic staging checks and a time limit.
	 * This is best-effort supervision, not a kernel-enforced filesystem quota.
	 *
	 * @param non-empty-list<string> $command
	 * @return array{code: 0|1, desc?: string}
	 */
	private function runExternalDecoder(array $command, string $extractTo, ?int $availableBytes = null): array {
		$limit = $this->configuredLimit('max_extracted_bytes', self::DEFAULT_MAX_EXTRACTED_BYTES);
		if ($availableBytes !== null && $availableBytes >= 0) {
			$limit = min($limit, $availableBytes);
		}
		$stagingSpace = disk_free_space(dirname($extractTo));
		if ($stagingSpace !== false) {
			$limit = min($limit, (int)$stagingSpace);
		}
		$result = ResourceLimitedProcess::run(
			$command,
			$extractTo,
			$limit,
			$this->configuredLimit('max_archive_entries', self::DEFAULT_MAX_ARCHIVE_ENTRIES),
			$this->configuredLimit('max_extraction_seconds', 300),
		);
		if (!$result['success']) {
			$this->logger->warning('Archive decoder stopped: ' . $result['reason']);
			$message = match ($result['reason']) {
				'size' => 'Archive exceeds the allowed extraction size',
				'entries' => 'Archive contains too many files',
				'timeout' => 'Archive extraction timed out',
				'symlink' => 'Symbolic links are not supported in extracted archives',
				default => 'Could not extract archive',
			};
			return ['code' => 0, 'desc' => $this->l->t($message)];
		}
		return ['code' => 1];
	}

	public function extractRar(string $file, string $extractTo, ?int $availableBytes = null): array {
		$response = [];

		if (!extension_loaded('rar')) {
			if (!$this->isCommandAvailable('unrar')) {
				return $this->extractRarWithSevenZip($file, $extractTo, $availableBytes);
			}
			if ($error = $this->assertSafeRarShell($file)) {
				return $error;
			}
			$result = $this->runExternalDecoder(['unrar', 'x', $file, '-R', $extractTo . '/', '-o+'], $extractTo, $availableBytes);
			if ($result['code'] !== 1) {
				return $result;
			}
		} else {
			return $this->extractRarWithPhpExtension($file, $extractTo, $availableBytes);
		}

		$response = array_merge($response, ['code' => 1]);
		return $response;
	}

	/**
	 * @return (int|mixed)[]
	 *
	 * @psalm-return array{code: 0|1, desc?: string}
	 */
	/**
	 * Stream a RAR entry into staging with strict per-entry and total-byte
	 * limits. Unlike RarEntry::extract(), PHP controls every output path.
	 *
	 * @param resource $input
	 */
	private function copyRarStream($input, string $target, int $expectedBytes, string $expectedCrc, int &$written, int $budget, int $deadline): bool {
		$output = fopen($target, 'xb');
		if ($output === false) {
			return false;
		}
		$size = 0;
		$hash = hash_init('crc32b');
		try {
			while (!feof($input)) {
				if (hrtime(true) >= $deadline) {
					return false;
				}
				$chunk = fread($input, 65536);
				if ($chunk === false || ($chunk === '' && !feof($input))) {
					return false;
				}
				$length = strlen($chunk);
				if ($length > $budget - $written || $length > $expectedBytes - $size) {
					return false;
				}
				$offset = 0;
				while ($offset < $length) {
					if (hrtime(true) >= $deadline) {
						return false;
					}
					$count = fwrite($output, substr($chunk, $offset));
					if ($count === false || $count === 0) {
						return false;
					}
					$offset += $count;
				}
				hash_update($hash, $chunk);
				$size += $length;
				$written += $length;
			}
		} finally {
			fclose($output);
		}
		return $size === $expectedBytes && hash_equals(strtolower($expectedCrc), hash_final($hash));
	}

	/**
	 * The PHP RAR extension must not bypass quota checks or create archive
	 * links using RarEntry::extract(). Stream only regular files and folders
	 * with a cumulative size budget and CRC verification.
	 *
	 * @return array{code: 0|1, desc?: string}
	 */
	private function extractRarWithPhpExtension(string $file, string $extractTo, ?int $availableBytes): array {
		$archive = rar_open($file);
		if ($archive === false) {
			return ['code' => 0, 'desc' => $this->l->t('Cannot open Rar file')];
		}
		try {
			$entries = rar_list($archive);
			if ($entries === false) {
				return ['code' => 0, 'desc' => $this->l->t('Failed to inspect RAR contents')];
			}
			if (count($entries) > $this->configuredLimit('max_archive_entries', self::DEFAULT_MAX_ARCHIVE_ENTRIES)) {
				return ['code' => 0, 'desc' => $this->l->t('Archive contains too many files')];
			}
			$budget = $this->configuredLimit('max_extracted_bytes', self::DEFAULT_MAX_EXTRACTED_BYTES);
			if ($availableBytes !== null && $availableBytes >= 0) {
				$budget = min($budget, $availableBytes);
			}
			$space = disk_free_space(dirname($extractTo));
			if ($space !== false) {
				$budget = min($budget, (int)$space);
			}
			$declared = 0;
			$paths = [];
			foreach ($entries as $entry) {
				$name = str_replace('\\', '/', $entry->getName());
				$relative = rtrim($name, '/');
				if ($relative === '' || preg_match('//u', $relative) !== 1 || isset($paths[$relative])) {
					return ['code' => 0, 'desc' => $this->l->t('Archive contains an invalid filename')];
				}
				if ($error = $this->rejectUnsafe($name)) {
					return $error;
				}
				$paths[$relative] = true;
				if ($entry->isEncrypted()) {
					return ['code' => 0, 'desc' => $this->l->t('Encrypted RAR archives are not supported')];
				}
				// Unix RAR4 entries can encode links and devices as native
				// filesystem types. RAR5 redirects are never materialized:
				// getStream() writes regular file bytes under PHP control.
				if (defined('RAR_HOST_UNIX') && $entry->getHostOs() === constant('RAR_HOST_UNIX')) {
					$type = $entry->getAttr() & 0xf000;
					if ($type !== 0x8000 && $type !== 0x4000) {
						return ['code' => 0, 'desc' => $this->l->t('Archive contains links or unsupported special files')];
					}
				}
				$size = $entry->getUnpackedSize();
				if ($size === false || $size < 0 || $size > $budget - $declared) {
					return ['code' => 0, 'desc' => $this->l->t('Archive exceeds the allowed extraction size')];
				}
				$declared += $size;
			}
			if (!is_dir($extractTo) && !mkdir($extractTo, 0700, true)) {
				return ['code' => 0, 'desc' => $this->l->t('Could not prepare extraction directory')];
			}
			$written = 0;
			$deadline = hrtime(true) + min($this->configuredLimit('max_extraction_seconds', 300), 3600) * 1000000000;
			foreach ($entries as $entry) {
				$name = rtrim(str_replace('\\', '/', $entry->getName()), '/');
				$target = $extractTo . '/' . $name;
				if ($entry->isDirectory()) {
					if (!is_dir($target) && !mkdir($target, 0700, true)) {
						return ['code' => 0, 'desc' => $this->l->t('Could not extract RAR archive')];
					}
					continue;
				}
				if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) {
					return ['code' => 0, 'desc' => $this->l->t('Could not extract RAR archive')];
				}
				$crc = $entry->getCrc();
				if (!is_string($crc) || preg_match('/^[0-9a-fA-F]{8}$/', $crc) !== 1) {
					return ['code' => 0, 'desc' => $this->l->t('Could not verify RAR entry integrity')];
				}
				$input = $entry->getStream();
				if ($input === false) {
					return ['code' => 0, 'desc' => $this->l->t('Could not read RAR entry')];
				}
				try {
					if (!$this->copyRarStream($input, $target, $entry->getUnpackedSize(), $crc, $written, $budget, $deadline)) {
						return ['code' => 0, 'desc' => $this->l->t('RAR extraction exceeded limits or failed integrity verification')];
					}
				} finally {
					fclose($input);
				}
			}
			return ['code' => 1];
		} catch (\Throwable $e) {
			$this->logger->warning('RAR stream extraction failed: ' . $e->getMessage());
			return ['code' => 0, 'desc' => $this->l->t('Could not extract RAR archive')];
		} finally {
			rar_close($archive);
		}
	}

	public function extractOther(string $file, string $extractTo, ?int $availableBytes = null): array {

		$temporarySpace = disk_free_space(dirname($extractTo));
		if ($error = $this->assertSafe7z(
			$file,
			$availableBytes,
			$temporarySpace === false ? null : (int)$temporarySpace,
		)) {
			return $error;
		}
		return $this->runExternalDecoder(['7za', '-y', 'x', $file, '-o' . $extractTo], $extractTo, $availableBytes);
	}

	/**
	 * Inspect RAR paths with the full 7z binary before extraction. Keeping the
	 * RAR listing separate from 7za allows the two formats to use different
	 * capabilities, and keeps additional 7za metadata checks independent.
	 *
	 * @return null|array{code: 0, desc: string}
	 */
	private function assertSafeRarSevenZip(string $file): ?array {
		$output = $this->listWithLimits(['7z', 'l', '-ba', '-slt', $file]);
		if ($output === null) {
			return ['code' => 0, 'desc' => $this->l->t('Failed to inspect RAR contents')];
		}
		foreach ($output as $line) {
			$line = rtrim($line, "\r");
			if ($error = $this->rejectUnsafeMetadata($line)) {
				return $error;
			}
			if (str_starts_with($line, 'Path = ')) {
				if ($error = $this->rejectUnsafe(substr($line, 7))) {
					return $error;
				}
			}
		}
		return null;
	}

	/**
	 * Use the full 7z binary as an alternate RAR decoder on distributions that
	 * cannot install the unrar command or the PHP rar extension.
	 */
	private function extractRarWithSevenZip(string $file, string $extractTo, ?int $availableBytes = null): array {
		if (!$this->isCommandAvailable('7z')) {
			return ['code' => 0, 'desc' => $this->l->t('RAR extraction requires unrar, the PHP rar extension, or the full 7z binary')];
		}
		if ($error = $this->assertSafeRarSevenZip($file)) {
			return $error;
		}
		return $this->runExternalDecoder(['7z', '-y', 'x', $file, '-o' . $extractTo], $extractTo, $availableBytes);
	}

	/**
	 * Check for a fixed, known external archive utility before invocation.
	 */
	private function isCommandAvailable(string $binary): bool {
		$return = 0;
		$output = [];
		exec('command -v ' . escapeshellarg($binary) . ' >/dev/null 2>&1', $output, $return);
		return $return === 0;
	}

}
