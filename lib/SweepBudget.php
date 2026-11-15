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
 * The time and failure budget of one node sweep (REQ-SWEEP-002). A port
 * that does not answer costs the tools' full retry time, so a node with
 * many dead ports stops early rather than hold its claim for minutes.
 */
final class SweepBudget {
	private int $failures = 0;

	public function __construct(private readonly float $deadline, private readonly int $maxFailures) {
	}

	public function fail(): void {
		$this->failures++;
	}

	public function spent(): bool {
		return $this->failures >= $this->maxFailures || microtime(true) >= $this->deadline;
	}
}
