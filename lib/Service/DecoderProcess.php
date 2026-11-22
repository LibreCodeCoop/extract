<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Service;

/**
 * Owns a subprocess, its pipes, exit status and optional POSIX process group.
 * Processes are always closed, including after a supervision failure.
 */
final class DecoderProcess {
	private const MAX_DRAIN_PER_POLL = 524288;
	private const TERMINATION_WAIT_US = 50000;

	/** @var resource */
	private $process;
	/** @var array<int, resource> */
	private array $pipes;
	private ?int $exitCode = null;
	private ?int $groupId = null;
	private int $outputBytes = 0;
	private bool $drainedSinceLastPoll = false;
	private string $output = '';

	/**
	 * @param resource $process
	 * @param array<int, resource> $pipes
	 */
	private function __construct(
		$process,
		array $pipes,
		private readonly bool $isolated,
	) {
		$this->process = $process;
		$this->pipes = $pipes;
	}

	/**
	 * @param non-empty-list<string> $command A fixed argument vector, not a shell command
	 */
	public static function start(array $command): ?self {
		if (!function_exists('proc_open')) {
			return null;
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
			return null;
		}
		if (!is_resource($process)) {
			return null;
		}
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		return new self($process, $pipes, $isolated);
	}

	/**
	 * Drain stdout and stderr without buffering extraction output. For archive
	 * listings, limit the combined bytes while only retaining stdout.
	 */
	public function drain(?int $outputLimit): ?string {
		$this->drainedSinceLastPoll = false;
		$remaining = self::MAX_DRAIN_PER_POLL;
		foreach ([1, 2] as $index) {
			while ($remaining > 0) {
				$chunk = fread($this->pipes[$index], min(65536, $remaining));
				if ($chunk === false) {
					return 'io';
				}
				if ($chunk === '') {
					break;
				}
				$length = strlen($chunk);
				$this->drainedSinceLastPoll = true;
				$remaining -= $length;
				if ($outputLimit !== null) {
					if ($length > $outputLimit - $this->outputBytes) {
						return 'output';
					}
					$this->outputBytes += $length;
					if ($index === 1) {
						$this->output .= $chunk;
					}
				}
			}
		}
		return null;
	}

	/** Whether the last drain pass consumed bytes from either process pipe. */
	public function hadOutput(): bool {
		return $this->drainedSinceLastPoll;
	}

	public function hasExited(): bool {
		$status = proc_get_status($this->process);
		if ($this->isolated && $this->groupId === null) {
			$this->groupId = self::processGroupId($status);
		}
		if (!$status['running'] && ($this->exitCode === null || $this->exitCode < 0)) {
			$this->exitCode = $status['exitcode'];
		}
		return !$status['running'];
	}

	public function outputComplete(): bool {
		return feof($this->pipes[1]) && feof($this->pipes[2]);
	}

	public function output(): string {
		return $this->output;
	}

	/** Always tear down the process group and release every pipe. */
	public function close(): int {
		$this->stopProcess();
		fclose($this->pipes[1]);
		fclose($this->pipes[2]);
		$closedCode = proc_close($this->process);
		if (($this->exitCode === null || $this->exitCode < 0) && $closedCode >= 0) {
			$this->exitCode = $closedCode;
		}
		return $this->exitCode ?? -1;
	}

	/**
	 * @param non-empty-list<string> $command
	 * @return non-empty-list<string>
	 */
	private static function isolatedCommand(array $command, bool &$isolated): array {
		$isolated = false;
		if (DIRECTORY_SEPARATOR !== '/' || !function_exists('posix_kill') || !function_exists('posix_getpgid') || !function_exists('posix_getpgrp')) {
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

	/**
	 * @param array{pid: int, running: bool, command: string, exitcode: int, signaled: bool, stopped: bool, stopsig: int, termsig: int} $status
	 */
	private static function processGroupId(array $status): ?int {
		$pid = $status['pid'];
		if ($pid <= 1 || $pid === posix_getpgrp()) {
			return null;
		}
		return @posix_getpgid($pid) === $pid ? $pid : null;
	}

	private function stopProcess(): void {
		if ($this->isolated && $this->groupId === null) {
			$this->groupId = self::processGroupId(proc_get_status($this->process));
		}
		if ($this->groupId !== null) {
			@posix_kill(-$this->groupId, 15);
			usleep(self::TERMINATION_WAIT_US);
			if (@posix_kill(-$this->groupId, 0)) {
				@posix_kill(-$this->groupId, 9);
			}
		}
		if (proc_get_status($this->process)['running']) {
			proc_terminate($this->process);
			usleep(self::TERMINATION_WAIT_US);
			if (proc_get_status($this->process)['running']) {
				proc_terminate($this->process, 9);
			}
		}
	}
}
