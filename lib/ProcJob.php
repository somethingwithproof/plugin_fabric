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
 * One child process while ProcRunner runs it. Internal to ProcRunner.
 */
final class ProcJob {
	/** Out state for ProcJob. */
	public string $out = '';
	/** Err state for ProcJob. */
	public string $err = '';
	/** Stdout eof state for ProcJob. */
	public bool $stdoutEof = false;
	/** Stderr eof state for ProcJob. */
	public bool $stderrEof = false;
	/** Too large state for ProcJob. */
	public bool $tooLarge = false;
	/** Exit state for ProcJob. */
	public ?int $exit = null;

	/**
	 * @param resource $process
	 * @param resource $stdout
	 * @param resource $stderr
	 */
	public function __construct(public readonly mixed $process, public readonly mixed $stdout, public readonly mixed $stderr, public readonly float $started) {
	}
}
