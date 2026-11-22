<?php

/**
 * SPDX-FileCopyrightText: 2012-2022 Paul Lereverend <paulereverend@gmail.com>
 * SPDX-FileCopyrightText: 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Service;

use OCP\IL10N;
use ZipArchive;

/** ZIP-specific archive inspection and bounded extraction. */
final class ZipExtractor {
	public function __construct(
		private IL10N $l,
		private ArchiveSafetyValidator $safety,
		private ExtractionLimits $limits,
		private BoundedStreamCopier $copier,
	) {
	}

	private function assertSafeZip(ZipArchive $zip): ?array {
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name === false) {
				return ['code' => 0, 'desc' => $this->l->t('Failed to read archive index')];
			}
			if ($error = $this->safety->rejectUnsafe($name)) {
				return $error;
			}
		}
		return null;
	}

	public function extract(string $file, string $extractTo, ?int $availableBytes = null): array {
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

			$limit = $this->limits->byteBudget($extractTo, $availableBytes);
			if ($zip->numFiles > $this->limits->entryLimit()) {
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
					if (!$this->copier->copy($input, $path, $written, $limit)) {
						return ['code' => 0, 'desc' => $this->l->t('Could not extract Zip archive')];
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
}
