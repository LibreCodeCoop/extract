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
	 * Reject absolute paths and any ".." path component. Modern libzip
	 * blocks traversal in ZipArchive::extractTo, but neither unrar nor
	 * 7za do — a crafted archive can write outside $extractTo.
	 */
	private function isUnsafePath(string $entry): bool {
		$norm = str_replace('\\', '/', $entry);
		if ($norm === '' || $norm === '.' || $norm === './') {
			return false;
		}
		if (str_starts_with($norm, '/')) {
			return true;
		}
		return (bool)preg_match('#(^|/)\.\.($|/)#', $norm);
	}

	/** @return null|array{code: 0, desc: string} */
	private function rejectUnsafe(string $entry): ?array {
		if (!$this->isUnsafePath($entry)) {
			return null;
		}
		$this->logger->warning('Refusing archive with unsafe entry: ' . $entry);
		return [
			'code' => 0,
			'desc' => $this->l->t('Archive contains an unsafe path and was not extracted'),
		];
	}

	/** Validate every ZIP entry via libzip indices. @return null|array{code: 0, desc: string} */
	private function assertSafeZip(ZipArchive $zip): ?array {
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name === false) {
				return ['code' => 0, 'desc' => $this->l->t('Failed to read archive index')];
			}
			if ($err = $this->rejectUnsafe($name)) {
				return $err;
			}
		}
		return null;
	}

	/** Validate every RAR entry via `unrar lb` (bare list). @return null|array{code: 0, desc: string} */
	private function assertSafeRarShell(string $file): ?array {
		$output = [];
		$return = 0;
		exec('unrar lb ' . escapeshellarg($file) . ' 2>&1', $output, $return);
		if ($return !== 0) {
			$this->logger->error('unrar list failed (rc=' . $return . '): ' . implode("\n", $output));
			return ['code' => 0, 'desc' => $this->l->t('Failed to inspect RAR contents')];
		}
		foreach ($output as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			if ($err = $this->rejectUnsafe($line)) {
				return $err;
			}
		}
		return null;
	}

	/** Validate every entry via `7za l -ba -slt`. @return null|array{code: 0, desc: string} */
	private function assertSafe7z(string $file): ?array {
		$output = [];
		$return = 0;
		exec('7za l -ba -slt ' . escapeshellarg($file) . ' 2>&1', $output, $return);
		if ($return !== 0) {
			$this->logger->error('7za list failed (rc=' . $return . '): ' . implode("\n", $output));
			return ['code' => 0, 'desc' => $this->l->t('Failed to inspect archive contents')];
		}
		foreach ($output as $line) {
			if (!str_starts_with($line, 'Path = ')) {
				continue;
			}
			$entry = substr($line, 7);
			if ($err = $this->rejectUnsafe($entry)) {
				return $err;
			}
		}
		return null;
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

		if ($err = $this->assertSafeZip($zip)) {
			$zip->close();
			return $err;
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
			if ($err = $this->assertSafeRarShell($file)) {
				return $err;
			}
			exec('unrar x ' . escapeshellarg($file) . ' -R ' . escapeshellarg($extractTo) . '/ -o+', $output, $return);
			if (sizeof($output) <= 4) {
				$response = array_merge($response, ['code' => 0, 'desc' => $this->l->t('Oops something went wrong. Check that you have rar extension or unrar installed')]);
				return $response;
			}
		} else {
			$rar_file = rar_open($file);
			$list = rar_list($rar_file);
			// Pre-validate every entry before extracting any of them.
			foreach ($list as $archive_file) {
				if ($err = $this->rejectUnsafe($archive_file->getName())) {
					rar_close($rar_file);
					return $err;
				}
			}
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

		if ($err = $this->assertSafe7z($file)) {
			return $err;
		}

		exec('7za -y x ' . escapeshellarg($file) . ' -o' . escapeshellarg($extractTo), $output, $return);

		if (sizeof($output) <= 5) {
			$response = array_merge($response, ['code' => 0, 'desc' => $this->l->t('Oops something went wrong.')]);
			$this->logger->error('Is 7-Zip installed? Output: ' . print_r($output, true));
			return $response;
		}
		$response = array_merge($response, ['code' => 1]);
		return $response;
	}
}
