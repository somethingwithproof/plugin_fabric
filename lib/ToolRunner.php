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
 * Runs a Command under the configured limits and logs any failure with a
 * cleaned excerpt of the tool's standard error. The excerpt is for the
 * operator reading the log; no decision is made from it (REQ-EXEC-006).
 *
 * With a deadline (a microtime(true) value), each run's time limit is the
 * command timeout or the time left before the deadline, whichever is
 * shorter, so the caller returns by the deadline whatever a tool does. A
 * run with under MIN_SECONDS left is not started and reads as timed out.
 */
final class ToolRunner {
	public const MIN_SECONDS = 1.0;

	public function __construct(private readonly ProcessRunner $runner, private readonly Settings $settings, private readonly ?float $deadline = null) {
	}

	public function run(Command $command): ProcessResult {
		$limit = (float) $this->settings->commandTimeout;

		if ($this->deadline !== null) {
			$left = $this->deadline - microtime(true);

			if ($left < self::MIN_SECONDS) {
				Log::warning(sprintf('%s: not started: the time limit of this sweep is spent', $command->tool->value));

				return new ProcessResult(null, '', '', true, false, true, 0.0);
			}

			$limit = min($limit, $left);
		}

		$result = $this->runner->run($command, $limit, $this->settings->maxOutputBytes());
		$error  = $result->error();

		if ($error !== null) {
			Log::warning(sprintf('%s: %s, exit %s, %.1f s: %s', $command->tool->value, $error->value, $result->exitCode ?? 'none', $result->duration, Log::excerpt($result->stderr)));
		}

		return $result;
	}
}
