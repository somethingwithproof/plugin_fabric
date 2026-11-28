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
 * One finished tool run. stdout is kept only when neither limit was hit,
 * and is kept on a non-zero exit, because perfquery prints the ports it
 * read before the one that failed. stderr is a bounded excerpt for the
 * log, never parsed (REQ-EXEC-006).
 */
final readonly class ProcessResult {
	/**
	 * Construct a new ProcessResult instance.
	 *
	 * @param ?int $exitCode
	 * @param string $stdout
	 * @param string $stderr
	 * @param bool $timedOut
	 * @param bool $tooLarge
	 * @param bool $started
	 * @param float $duration
	 */
	public function __construct(
		public ?int $exitCode,
		public string $stdout,
		public string $stderr,
		public bool $timedOut,
		public bool $tooLarge,
		public bool $started,
		public float $duration,
	) {
	}

	/**
	 * Why the run gave no complete output, or null when it did.
	 */
	public function error(): ?CommandError {
		return match (true) {
			!$this->started       => CommandError::NotRunnable,
			$this->timedOut       => CommandError::Timeout,
			$this->tooLarge       => CommandError::TooLarge,
			$this->exitCode !== 0 => CommandError::ExitStatus,
			default               => null,
		};
	}
}
