<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_fabric, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_fabric
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Fabric;

/**
 * Runs a Command's argument vector through proc_open() with an array
 * command, so no shell parses anything (REQ-EXEC-001). The child gets
 * /dev/null on stdin, / as its working directory, and a fixed minimal
 * environment, so no PATH, locale, or loader variable of the poller reaches
 * it (REQ-EXEC-006). Its stdout is cut off and the child killed at
 * $maxBytes, and it is killed at $timeout; either way nothing it printed is
 * used (REQ-EXEC-004).
 */
final class ProcRunner implements ProcessRunner {
	/** Chunk constant for ProcRunner. */
	private const CHUNK       = 65536;
	/** Stderr keep constant for ProcRunner. */
	private const STDERR_KEEP = 2048;

	/**
	 * @param array<string, string> $env the whole environment of the child
	 */
	public function __construct(private readonly array $env = ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C']) {
	}

	/**
	 * Run.
	 *
	 * @param Command $command
	 * @param float $timeout
	 * @param int $maxBytes
	 * @return ProcessResult
	 */
	public function run(Command $command, float $timeout, int $maxBytes): ProcessResult {
		$job = $this->start($command->argv);

		if ($job === null) {
			return new ProcessResult(null, '', '', false, false, false, 0.0);
		}

		while (true) {
			$this->pump($job, $maxBytes);
			$elapsed = microtime(true) - $job->started;

			if ($job->tooLarge || $elapsed > $timeout) {
				return $this->kill($job, $elapsed > $timeout && !$job->tooLarge);
			}

			if ($job->exit === null) {
				$status = proc_get_status($job->process);

				if (!$status['running']) {
					$job->exit = $status['exitcode'];
				}
			}

			if ($job->exit !== null && $job->stdoutEof && $job->stderrEof) {
				fclose($job->stdout);
				fclose($job->stderr);
				proc_close($job->process);

				return new ProcessResult($job->exit, $job->out, $job->err, false, false, true, microtime(true) - $job->started);
			}
		}
	}

	/**
	 * @param list<string> $argv
	 */
	private function start(array $argv): ?ProcJob {
		if ($argv === [] || !is_file($argv[0]) || !is_executable($argv[0])) {
			return null;
		}

		$pipes   = [];
		$process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', $this->env);

		if (!is_resource($process) || !isset($pipes[1], $pipes[2]) || !is_resource($pipes[1]) || !is_resource($pipes[2])) {
			return null;
		}

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		return new ProcJob($process, $pipes[1], $pipes[2], microtime(true));
	}

	/**
	 * Read whatever the child has written, waiting at most a tenth of a
	 * second.
	 */
	private function pump(ProcJob $job, int $maxBytes): void {
		$read = [];

		if (!$job->stdoutEof) {
			$read[] = $job->stdout;
		}

		if (!$job->stderrEof) {
			$read[] = $job->stderr;
		}

		if ($read === []) {
			usleep(10000);

			return;
		}

		$write  = null;
		$except = null;

		if (@stream_select($read, $write, $except, 0, 100000) === false) {
			usleep(10000);

			return;
		}

		foreach ($read as $stream) {
			$isOut = $stream === $job->stdout;
			$chunk = fread($stream, self::CHUNK);

			if ($chunk === false || ($chunk === '' && feof($stream))) {
				if ($isOut) {
					$job->stdoutEof = true;
				} else {
					$job->stderrEof = true;
				}

				continue;
			}

			if ($isOut) {
				$job->out .= $chunk;

				if (strlen($job->out) > $maxBytes) {
					$job->tooLarge = true;
				}
			} elseif (strlen($job->err) < self::STDERR_KEEP) {
				$job->err .= substr($chunk, 0, self::STDERR_KEEP - strlen($job->err));
			}
		}
	}

	/**
	 * Kill.
	 *
	 * @param ProcJob $job
	 * @param bool $timedOut
	 * @return ProcessResult
	 */
	private function kill(ProcJob $job, bool $timedOut): ProcessResult {
		proc_terminate($job->process, 9);
		fclose($job->stdout);
		fclose($job->stderr);
		proc_close($job->process);

		// A cut-off answer is never handed on as if it were whole.
		return new ProcessResult(null, '', $job->err, $timedOut, $job->tooLarge, true, microtime(true) - $job->started);
	}
}
