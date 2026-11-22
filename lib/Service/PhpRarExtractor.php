<?php

/**
 * SPDX-FileCopyrightText: 2012-2022 Paul Lereverend <paulereverend@gmail.com>
 * SPDX-FileCopyrightText: 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Service;

use OCP\IL10N;
use Psr\Log\LoggerInterface;

/** Extract via the optional PHP rar extension, with bounded streaming. */
final class PhpRarExtractor {
	public function __construct(
		private IL10N $l,
		private LoggerInterface $logger,
		private ArchiveSafetyValidator $safety,
		private ExtractionLimits $limits,
		private BoundedStreamCopier $copier,
	) {
	}

	public function extract(string $file, string $extractTo, ?int $availableBytes): array {
		$archive = rar_open($file);
		if ($archive === false) {
			return ['code' => 0, 'desc' => $this->l->t('Cannot open Rar file')];
		}
		try {
			$entries = rar_list($archive);
			if ($entries === false) {
				return ['code' => 0, 'desc' => $this->l->t('Failed to inspect RAR contents')];
			}
			$entryLimit = $this->limits->entryLimit();
			$entryCount = 0;
			$budget = $this->limits->byteBudget($extractTo, $availableBytes);
			$declared = 0;
			$paths = [];
			foreach ($entries as $entry) {
				if (++$entryCount > $entryLimit) {
					return ['code' => 0, 'desc' => $this->l->t('Archive contains too many files')];
				}
				$name = str_replace('\\', '/', $entry->getName());
				$relative = rtrim($name, '/');
				if ($relative === '' || preg_match('//u', $relative) !== 1 || isset($paths[$relative])) {
					return ['code' => 0, 'desc' => $this->l->t('Archive contains an invalid filename')];
				}
				if ($error = $this->safety->rejectUnsafe($name)) {
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
			$deadline = hrtime(true) + min($this->limits->timeout(), 3600) * 1000000000;
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
					if (!$this->copier->copy($input, $target, $written, $budget, $entry->getUnpackedSize(), $crc, $deadline)) {
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
}
