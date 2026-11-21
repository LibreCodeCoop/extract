<?php

/**
 * SPDX-FileCopyrightText: 2012-2022 Paul Lereverend <paulereverend@gmail.com>
 * SPDX-FileCopyrightText: 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Service;

use OCP\IL10N;
use Psr\Log\LoggerInterface;
use ZipArchive;

final class ExtractionService {

	public function __construct(
		private IL10N $l,
		private LoggerInterface $logger,
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
	private function assertSafe7z(string $file, bool $useFullSevenZip = false): ?array {
		$output = [];
		$return = 0;
		$binary = $useFullSevenZip ? '7z' : '7za';
		exec($binary . ' l -ba -slt ' . escapeshellarg($file) . ' 2>&1', $output, $return);
		if ($return !== 0) {
			$this->logger->error($binary . ' list failed (rc=' . $return . '): ' . implode("\n", $output));
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
	 * Use the full 7z binary as an alternate RAR decoder on distributions that
	 * cannot install the unrar command or the PHP rar extension.
	 */
	private function extractRarWithSevenZip(string $file, string $extractTo): array {
		if (!$this->isCommandAvailable('7z')) {
			return ['code' => 0, 'desc' => $this->l->t('RAR extraction requires unrar, the PHP rar extension, or the full 7z binary')];
		}
		if ($error = $this->assertSafe7z($file, true)) {
			return $error;
		}
		$output = [];
		$return = 0;
		exec('7z -y x ' . escapeshellarg($file) . ' -o' . escapeshellarg($extractTo) . ' 2>&1', $output, $return);
		if ($return !== 0) {
			$this->logger->error('RAR extraction via 7z failed (rc=' . $return . '): ' . implode("\n", $output));
			return ['code' => 0, 'desc' => $this->l->t('Could not extract RAR archive')];
		}
		return ['code' => 1];
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

	/**
	 * @return (bool|int|mixed)[]
	 *
	 * @psalm-return array{code: 0|1, desc?: string}
	 */
	public function extractZip(string $file, string $extractTo): array {
		$response = [];

		if (!extension_loaded('zip')) {
			$response = array_merge($response, ['code' => 0, 'desc' => $this->l->t('Zip extension is not available')]);
			return $response;
		}

		$zip = new ZipArchive();

		if ($zip->open($file) !== true) {
			$response = array_merge($response, ['code' => 0, 'desc' => $this->l->t('Cannot open Zip file')]);
			return $response;
		}

		if ($error = $this->assertSafeZip($zip)) {
			$zip->close();
			return $error;
		}

		$success = $zip->extractTo($extractTo);
		$zip->close();
		$response = array_merge($response, ['code' => $success ? 1 : 0]);
		return $response;
	}

	/**
	 * @return (int|mixed)[]
	 *
	 * @psalm-return array{code: 0|1, desc?: string}
	 */
	public function extractRar(string $file, string $extractTo): array {
		$response = [];

		if (!extension_loaded('rar')) {
			if (!$this->isCommandAvailable('unrar')) {
				return $this->extractRarWithSevenZip($file, $extractTo);
			}
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
