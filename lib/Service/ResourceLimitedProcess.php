<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Service;

/**
 * Supervise an external archive decoder while it writes to a private staging
 * directory. Checks are periodic, so administrators must still isolate the
 * staging filesystem when they need a strict, kernel-enforced disk quota.
 */
final class ResourceLimitedProcess {
	private const POLL_INTERVAL_US = 100000;

	/**
	 * @param non-empty-list<string> $command Fixed executable and argument vector, never a shell command
	 * @return array{success: bool, reason: string}
	 */
	public static function run(array $command, string $directory, int $maxBytes, int $maxEntries, int $timeoutSeconds): array {
		if ($maxBytes < 0 || $maxEntries < 1 || $timeoutSeconds < 1 || !function_exists('proc_open')) {
			return ['success' => false, 'reason' => 'unavailable'];
		}

		try {
			$process = proc_open($command, [
				0 => ['pipe', 'r'],
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			], $pipes, null, null, ['bypass_shell' => true]);
		} catch (\Throwable) {
			return ['success' => false, 'reason' => 'unavailable'];
		}
		if (!is_resource($process)) {
			return ['success' => false, 'reason' => 'unavailable'];
		}

		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$reason = null;
		$exitCode = null;
		$deadline = microtime(true) + $timeoutSeconds;
		try {
			while (true) {
				// Drain bounded chunks so verbose decoders cannot block on full pipes.
				foreach ([1, 2] as $index) {
					fread($pipes[$index], 8192);
				}
				$reason = self::inspect($directory, $maxBytes, $maxEntries);
				if ($reason !== null) {
					break;
				}
				if (microtime(true) >= $deadline) {
					$reason = 'timeout';
					break;
				}
				$status = proc_get_status($process);
				if (!$status['running']) {
					$exitCode = $status['exitcode'];
					break;
				}
				usleep(self::POLL_INTERVAL_US);
			}
		} catch (\Throwable) {
			$reason = 'inspection';
		} finally {
			if ($reason !== null) {
				proc_terminate($process);
				usleep(self::POLL_INTERVAL_US);
				if (proc_get_status($process)['running']) {
					proc_terminate($process, 9);
				}
			}
			fclose($pipes[1]);
			fclose($pipes[2]);
			$closedCode = proc_close($process);
			if ($exitCode === null && $closedCode >= 0) {
				$exitCode = $closedCode;
			}
		}
		if ($reason !== null) {
			return ['success' => false, 'reason' => $reason];
		}
		try {
			$reason = self::inspect($directory, $maxBytes, $maxEntries);
		} catch (\Throwable) {
			$reason = 'inspection';
		}
		if ($reason !== null) {
			return ['success' => false, 'reason' => $reason];
		}
		if ($exitCode !== 0) {
			return ['success' => false, 'reason' => 'exit'];
		}
		return ['success' => true, 'reason' => ''];
	}

	private static function inspect(string $directory, int $maxBytes, int $maxEntries): ?string {
		if (!is_dir($directory)) {
			return null;
		}
		clearstatcache(true);
		$bytes = 0;
		$entries = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
		);
		foreach ($iterator as $entry) {
			if ($entry->isLink()) {
				return 'symlink';
			}
			if (++$entries > $maxEntries) {
				return 'entries';
			}
			if (!$entry->isDir()) {
				$size = $entry->getSize();
				if ($size < 0 || $size > $maxBytes - $bytes) {
					return 'size';
				}
				$bytes += $size;
			}
		}
		return null;
	}
}
