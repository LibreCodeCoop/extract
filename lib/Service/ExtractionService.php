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
			foreach ($list as $archive_file) {
				$entry = rar_entry_get($rar_file, $archive_file->getName());
				$entry->extract($extractTo);
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
