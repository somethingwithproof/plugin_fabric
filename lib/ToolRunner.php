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
 */
final class ToolRunner {
	public function __construct(private readonly ProcessRunner $runner, private readonly Settings $settings) {
	}

	public function run(Command $command): ProcessResult {
		$result = $this->runner->run($command, (float) $this->settings->commandTimeout, $this->settings->maxOutputBytes());
		$error  = $result->error();

		if ($error !== null) {
			Log::warning(sprintf('%s: %s, exit %s, %.1f s: %s', $command->tool->value, $error->value, $result->exitCode ?? 'none', $result->duration, Log::excerpt($result->stderr)));
		}

		return $result;
	}
}
