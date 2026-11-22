<?php

/**
 * SPDX-FileCopyrightText: 2012-2022 Paul Lereverend <paulereverend@gmail.com>
 * SPDX-FileCopyrightText: 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Service;

/**
 * Copy decoded bytes with one cumulative budget and optional size, deadline
 * and CRC requirements. Input ownership remains with the calling extractor.
 */
final class BoundedStreamCopier {
	/**
	 * @param resource $input
	 */
	public function copy(
		$input,
		string $target,
		int &$written,
		int $budget,
		?int $expectedBytes = null,
		?string $expectedCrc = null,
		?int $deadline = null,
	): bool {
		if ($budget < 0 || $written < 0 || $written > $budget || ($expectedBytes !== null && $expectedBytes < 0)) {
			return false;
		}
		// Preserve ZIP overwrite semantics; RAR entries must never overwrite.
		$output = @fopen($target, $expectedBytes === null ? 'wb' : 'xb');
		if ($output === false) {
			return false;
		}
		$entryBytes = 0;
		$hash = $expectedCrc !== null ? hash_init('crc32b') : null;
		try {
			while (!feof($input)) {
				if ($deadline !== null && hrtime(true) >= $deadline) {
					return false;
				}
				$chunk = fread($input, 65536);
				if ($chunk === false || ($chunk === '' && !feof($input))) {
					return false;
				}
				$length = strlen($chunk);
				if ($length > $budget - $written || ($expectedBytes !== null && $length > $expectedBytes - $entryBytes)) {
					return false;
				}
				$offset = 0;
				while ($offset < $length) {
					if ($deadline !== null && hrtime(true) >= $deadline) {
						return false;
					}
					$count = fwrite($output, substr($chunk, $offset));
					if ($count === false || $count === 0) {
						return false;
					}
					$offset += $count;
				}
				if ($hash !== null) {
					hash_update($hash, $chunk);
				}
				$entryBytes += $length;
				$written += $length;
			}
		} finally {
			fclose($output);
		}
		if ($expectedBytes !== null && $entryBytes !== $expectedBytes) {
			return false;
		}
		return $hash === null || hash_equals(strtolower($expectedCrc), hash_final($hash));
	}
}
