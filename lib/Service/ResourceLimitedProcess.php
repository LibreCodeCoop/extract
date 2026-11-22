<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Service;

/**
 * Coordinate bounded extraction and listing through the same process loop.
 * The process lifecycle and staging checks are independently testable.
 * Polling is not a kernel-enforced resource quota.
 */
final class ResourceLimitedProcess {
	private const POLL_INTERVAL_US = 50000;

	/**
	 * @param non-empty-list<string> $command Fixed executable and argument vector
	 * @return array{success: bool, reason: string}
	 */
	public static function run(array $command, string $directory, int $maxBytes, int $maxEntries, int $timeoutSeconds): array {
		if ($maxBytes < 0 || $maxEntries < 1) {
			return ['success' => false, 'reason' => 'unavailable'];
		}
		$result = self::execute($command, $timeoutSeconds, new StagingBudgetInspector($directory, $maxBytes, $maxEntries), null);
		return ['success' => $result['success'], 'reason' => $result['reason']];
	}

	/**
	 * @param non-empty-list<string> $command Fixed executable and argument vector
	 * @return array{success: bool, reason: string, output: string}
	 */
	public static function capture(array $command, int $maxOutputBytes, int $timeoutSeconds): array {
		if ($maxOutputBytes < 1) {
			return ['success' => false, 'reason' => 'unavailable', 'output' => ''];
		}
		return self::execute($command, $timeoutSeconds, null, $maxOutputBytes);
	}

	/**
	 * Share one supervision loop for archive extraction and metadata listing.
	 * Only extraction needs filesystem scans; only listing retains stdout.
	 *
	 * @param non-empty-list<string> $command
	 * @return array{success: bool, reason: string, output: string}
	 */
	private static function execute(array $command, int $timeoutSeconds, ?StagingBudgetInspector $inspector, ?int $maxOutputBytes): array {
		if ($timeoutSeconds < 1) {
			return ['success' => false, 'reason' => 'unavailable', 'output' => ''];
		}
		$process = DecoderProcess::start($command);
		if ($process === null) {
			return ['success' => false, 'reason' => 'unavailable', 'output' => ''];
		}

		$reason = null;
		// Preserve the configured extraction timeout; limit listing to one hour.
		$deadline = microtime(true) + ($maxOutputBytes === null ? $timeoutSeconds : min($timeoutSeconds, 3600));
		try {
			while (true) {
				$reason = $process->drain($maxOutputBytes);
				if ($reason !== null) {
					break;
				}
				$reason = $inspector?->inspect();
				if ($reason !== null) {
					break;
				}
				if (microtime(true) >= $deadline) {
					$reason = 'timeout';
					break;
				}
				if ($process->hasExited() && ($maxOutputBytes === null || $process->outputComplete())) {
					break;
				}
				// A verbose decoder can fill the pipe much faster than a 50 ms
				// polling cadence allows it to be drained. Keep reading promptly
				// while data flows; retain the slower cadence when idle.
				usleep($process->hadOutput() ? 1000 : self::POLL_INTERVAL_US);
			}
		} catch (\Throwable) {
			$reason = 'inspection';
		} finally {
			$exitCode = $process->close();
		}

		if ($reason === null) {
			try {
				$reason = $inspector?->inspect();
			} catch (\Throwable) {
				$reason = 'inspection';
			}
		}
		if ($reason === null && $exitCode !== 0) {
			$reason = 'exit';
		}
		return ['success' => $reason === null, 'reason' => $reason ?? '', 'output' => $process->output()];
	}
}
