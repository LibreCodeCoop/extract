<?php

/**
 * SPDX-FileCopyrightText: 2012-2022 Paul Lereverend <paulereverend@gmail.com>
 * SPDX-FileCopyrightText: 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Service;

use OCP\IConfig;

/** Resolve positive application limits without exposing configuration parsing. */
final class ExtractionLimits {
	public function __construct(private IConfig $config) {
	}

	public function configuredLimit(string $key, int $default): int {
		$value = $this->config->getAppValue('extract', $key, (string)$default);
		if (!is_string($value) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
			return $default;
		}
		return (int)$value;
	}

	/** Calculate the staging budget consistently across ZIP, RAR and CLI. */
	public function byteBudget(string $extractTo, ?int $availableBytes): int {
		$limit = $this->configuredLimit('max_extracted_bytes', 2147483648);
		if ($availableBytes !== null && $availableBytes >= 0) {
			$limit = min($limit, $availableBytes);
		}
		$space = disk_free_space(dirname($extractTo));
		if ($space !== false) {
			$limit = min($limit, (int)$space);
		}
		return $limit;
	}

	public function entryLimit(): int {
		return $this->configuredLimit('max_archive_entries', 10000);
	}

	public function timeout(): int {
		return $this->configuredLimit('max_extraction_seconds', 300);
	}
}
