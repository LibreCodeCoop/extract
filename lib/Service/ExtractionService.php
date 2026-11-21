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

	/** @return null|array{code: 0, desc: string} */
	private function assertSafeRarShell(string $file): ?array {
		$output = [];
		$return = 0;
		exec('unrar lb ' . escapeshellarg($file) . ' 2>&1', $output, $return);
		if ($return !== 0) {
			$this->logger->error('unrar list failed (rc=' . $return . '): ' . implode("\n", $output));
			return ['code' => 0, 'desc' => $this->l->t('Failed to inspect RAR contents')];
		}
		foreach ($output as $entry) {
			if ($entry !== '' && ($error = $this->rejectUnsafe($entry))) {
				return $error;
			}
		}
		return null;
	}

	/** @return null|array{code: 0, desc: string} */
	private function assertSafe7z(string $file): ?array {
		$output = [];
		$return = 0;
		exec('7za l -ba -slt ' . escapeshellarg($file) . ' 2>&1', $output, $return);
		if ($return !== 0) {
			$this->logger->error('7za list failed (rc=' . $return . '): ' . implode("\n", $output));
			return ['code' => 0, 'desc' => $this->l->t('Failed to inspect archive contents')];
		}
		foreach ($output as $line) {
			if (str_starts_with($line, 'Path = ')) {
				if ($error = $this->rejectUnsafe(substr($line, 7))) {
					return $error;
				}
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
	public function extractRar(string $file, string $extractTo): array {
		$response = [];

		if (!extension_loaded('rar')) {
			if ($error = $this->assertSafeRarShell($file)) {
				return $error;
			}
			$output = [];
			$return = 0;
			exec('unrar x ' . escapeshellarg($file) . ' -R ' . escapeshellarg($extractTo) . '/ -o+', $output, $return);
			if ($return !== 0) {
				$response = array_merge($response, ['code' => 0, 'desc' => $this->l->t('Oops something went wrong. Check that you have rar extension or unrar installed')]);
				$this->logger->error('unrar failed with exit code ' . $return . '. Output: ' . print_r($output, true));
				return $response;
			}
		} else {
			$rar_file = rar_open($file);
			if ($rar_file === false) {
				$response = array_merge($response, ['code' => 0, 'desc' => $this->l->t('Cannot open Rar file')]);
				return $response;
			}
			$list = rar_list($rar_file);
			if ($list === false) {
				rar_close($rar_file);
				return ['code' => 0, 'desc' => $this->l->t('Failed to inspect RAR contents')];
			}
			foreach ($list as $archive_file) {
				if ($error = $this->rejectUnsafe($archive_file->getName())) {
					rar_close($rar_file);
					return $error;
				}
			}
			foreach ($list as $archive_file) {
				$entry = rar_entry_get($rar_file, $archive_file->getName());
				if ($entry->extract($extractTo) === false) {
					// A password-protected or damaged entry fails here; without this
					// check the method reported success and the caller published a
					// partial result.
					rar_close($rar_file);
					$response = array_merge($response, ['code' => 0, 'desc' => $this->l->t('Oops something went wrong.')]);
					$this->logger->error('rar_entry::extract failed for ' . $archive_file->getName());
					return $response;
				}
			}
			rar_close($rar_file);
		}

		$response = array_merge($response, ['code' => 1]);
		return $response;
	}

	/**
	 * @return (int|mixed)[]
	 *
	 * @psalm-return array{code: 0|1, desc?: string}
	 */
	public function extractOther(string $file, string $extractTo): array {
		$response = [];

		if ($error = $this->assertSafe7z($file)) {
			return $error;
		}
		$output = [];
		$return = 0;
		exec('7za -y x ' . escapeshellarg($file) . ' -o' . escapeshellarg($extractTo), $output, $return);

		// 7za reports failure through its exit code: 0 on success, 2 on a fatal
		// error such as a corrupt archive or a missing file, 127 when the
		// binary itself is absent. The output line count is not a usable signal
		// because failures print more lines than successes.
		if ($return !== 0) {
			$response = array_merge($response, ['code' => 0, 'desc' => $this->l->t('Oops something went wrong.')]);
			$this->logger->error('7za failed with exit code ' . $return . '. Is 7-Zip installed? Output: ' . print_r($output, true));
			return $response;
		}
		$response = array_merge($response, ['code' => 1]);
		return $response;
	}
}
