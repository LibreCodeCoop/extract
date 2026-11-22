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
	private const POLL_INTERVAL_US = 50000;
	private const MAX_DRAIN_PER_POLL = 524288;

	/**
	 * @param non-empty-list<string> $command Fixed executable and argument vector, never a shell command
	 * @return array{success: bool, reason: string}
	 */
	public static function run(array $command, string $directory, int $maxBytes, int $maxEntries, int $timeoutSeconds): array {
		if ($maxBytes < 0 || $maxEntries < 1 || $timeoutSeconds < 1 || !function_exists('proc_open')) {
			return ['success' => false, 'reason' => 'unavailable'];
		}

		$isolated = false;
		$command = self::isolatedCommand($command, $isolated);
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
		$groupId = null;
		$deadline = microtime(true) + $timeoutSeconds;
		try {
			while (true) {
				// Drain sufficient output to keep verbose decoders running,
				// without starving deadline and staging-space inspections.
				$remaining = self::MAX_DRAIN_PER_POLL;
				foreach ([1, 2] as $index) {
					while ($remaining > 0) {
						$chunk = fread($pipes[$index], min(65536, $remaining));
						if ($chunk === false) {
							$reason = 'io';
							break 2;
						}
						if ($chunk === '') {
							break;
						}
						$remaining -= strlen($chunk);
					}
				}
				if ($reason !== null) {
					break;
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
				if ($isolated && $groupId === null) {
					$groupId = self::processGroupId($status);
				}
				if (!$status['running']) {
					$exitCode = $status['exitcode'];
					break;
				}
				usleep(self::POLL_INTERVAL_US);
			}
		} catch (\Throwable) {
			$reason = 'inspection';
		} finally {
			// Clean up any descendants in the isolated session even when the
			// direct decoder process has already exited successfully.
			self::stopProcess($process, $groupId, $isolated);
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

	/**
	 * Inspect archive listing output with bounded memory and execution time.
	 * Never run listing commands through exec(), which buffers all output
	 * without imposing a timeout or a memory limit.
	 *
	 * @param non-empty-list<string> $command
	 * @return array{success: bool, reason: string, output: string}
	 */
	public static function capture(array $command, int $maxOutputBytes, int $timeoutSeconds): array {
		if ($maxOutputBytes < 1 || $timeoutSeconds < 1 || !function_exists('proc_open')) {
			return ['success' => false, 'reason' => 'unavailable', 'output' => ''];
		}
		$isolated = false;
		$command = self::isolatedCommand($command, $isolated);
		try {
			$process = proc_open($command, [
				0 => ['pipe', 'r'],
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			], $pipes, null, null, ['bypass_shell' => true]);
		} catch (\Throwable) {
			return ['success' => false, 'reason' => 'unavailable', 'output' => ''];
		}
		if (!is_resource($process)) {
			return ['success' => false, 'reason' => 'unavailable', 'output' => ''];
		}

		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$output = '';
		$capturedBytes = 0;
		$reason = null;
		$exitCode = null;
		$groupId = null;
		$deadline = hrtime(true) + (int)min($timeoutSeconds, 3600) * 1000000000;
		try {
			while (true) {
				$remaining = self::MAX_DRAIN_PER_POLL;
				foreach ([1, 2] as $index) {
					while ($remaining > 0) {
						$chunk = fread($pipes[$index], min(65536, $remaining));
						if ($chunk === false) {
							$reason = 'io';
							break 2;
						}
						if ($chunk === '') {
							break;
						}
						if (strlen($chunk) > $maxOutputBytes - $capturedBytes) {
							$reason = 'output';
							break 2;
						}
						$capturedBytes += strlen($chunk);
						$remaining -= strlen($chunk);
						if ($index === 1) {
							$output .= $chunk;
						}
					}
				}
				if ($reason !== null) {
					break;
				}
				if (hrtime(true) >= $deadline) {
					$reason = 'timeout';
					break;
				}
				$status = proc_get_status($process);
				if ($isolated && $groupId === null) {
					$groupId = self::processGroupId($status);
				}
				if (!$status['running']) {
					// proc_get_status may return -1 on repeated queries; preserve
					// the first valid exit code until the pipes reach EOF.
					if ($exitCode === null || $exitCode < 0) {
						$exitCode = $status['exitcode'];
					}
					if (feof($pipes[1]) && feof($pipes[2])) {
						break;
					}
				}
				usleep(self::POLL_INTERVAL_US);
			}
		} catch (\Throwable) {
			$reason = 'inspection';
		} finally {
			// Clean up any descendants in the isolated session even when the
			// direct decoder process has already exited successfully.
			self::stopProcess($process, $groupId, $isolated);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$closedCode = proc_close($process);
			if (($exitCode === null || $exitCode < 0) && $closedCode >= 0) {
				$exitCode = $closedCode;
			}
		}
		if ($reason === null && $exitCode !== 0) {
			$reason = 'exit';
		}
		return ['success' => $reason === null, 'reason' => $reason ?? '', 'output' => $output];
	}

	/**
	 * Prefer a separate process session on Unix so timed-out decoders cannot
	 * leave inherited child processes writing after the parent is terminated.
	 *
	 * @param non-empty-list<string> $command
	 * @return non-empty-list<string>
	 */
	private static function isolatedCommand(array $command, bool &$isolated): array {
		$isolated = false;
		if (DIRECTORY_SEPARATOR !== '/' || !function_exists('posix_kill') || !function_exists('posix_getpgid')) {
			return $command;
		}
		foreach (['/usr/bin/setsid', '/bin/setsid'] as $binary) {
			if (is_executable($binary)) {
				$isolated = true;
				return [$binary, ...$command];
			}
		}
		return $command;
	}

	/** @param array{pid: int, running: bool, command: string, exitcode: int, signaled: bool, stopped: bool, stopsig: int, termsig: int} $status */
	private static function processGroupId(array $status): ?int {
		$pid = $status['pid'];
		if ($pid <= 1 || $pid === posix_getpgrp()) {
			return null;
		}
		return @posix_getpgid($pid) === $pid ? $pid : null;
	}

	/**
	 * Terminate the decoder session when available, then stop its direct child
	 * as a fallback. Only signal a verified, distinct POSIX process group.
	 *
	 * @param resource $process
	 */
	private static function stopProcess($process, ?int $groupId, bool $isolated): void {
		if ($isolated && $groupId === null) {
			$groupId = self::processGroupId(proc_get_status($process));
		}
		if ($groupId !== null) {
			@posix_kill(-$groupId, 15);
			usleep(self::POLL_INTERVAL_US);
			if (@posix_kill(-$groupId, 0)) {
				@posix_kill(-$groupId, 9);
			}
		}
		if (proc_get_status($process)['running']) {
			proc_terminate($process);
			usleep(self::POLL_INTERVAL_US);
			if (proc_get_status($process)['running']) {
				proc_terminate($process, 9);
			}
		}
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
