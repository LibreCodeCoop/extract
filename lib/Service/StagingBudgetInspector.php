<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Service;

/**
 * Pure filesystem budget check, independent from the archive decoder.
 * Never follows symlinks when walking temporary extraction directories.
 */
final class StagingBudgetInspector {
	public function __construct(
		private readonly string $directory,
		private readonly int $maxBytes,
		private readonly int $maxEntries,
	) {
	}

	/** @return null|'symlink'|'entries'|'size'|'inspection' */
	public function inspect(): ?string {
		clearstatcache(true);
		if (is_link($this->directory)) {
			return 'symlink';
		}
		if (!is_dir($this->directory)) {
			return file_exists($this->directory) ? 'inspection' : null;
		}
		$bytes = 0;
		$entries = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
		);
		foreach ($iterator as $entry) {
			if ($entry->isLink()) {
				return 'symlink';
			}
			if (++$entries > $this->maxEntries) {
				return 'entries';
			}
			if (!$entry->isDir()) {
				// Fail closed for device nodes, pipes and sockets in staging.
				if (!$entry->isFile()) {
					return 'inspection';
				}
				$size = $entry->getSize();
				if ($size < 0 || $size > $this->maxBytes - $bytes) {
					return 'size';
				}
				$bytes += $size;
			}
		}
		return null;
	}
}
