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
 * Counts from one node counter sweep. Ports are counted once each: read
 * when the basic counters were read, failed when they were not, skipped
 * when the budget ran out before they were tried.
 */
final class NodeSweepResult {
	/** Total state for NodeSweepResult. */
	public int $total = 0;
	/** Read state for NodeSweepResult. */
	public int $read = 0;
	/** Failed state for NodeSweepResult. */
	public int $failed = 0;
	/** Skipped state for NodeSweepResult. */
	public int $skipped = 0;
	/** Duration state for NodeSweepResult. */
	public float $duration = 0.0;
	/** Error state for NodeSweepResult. */
	public ?CommandError $error = null;

	/**
	 * Outcome.
	 *
	 * @return SweepOutcome
	 */
	public function outcome(): SweepOutcome {
		return match (true) {
			$this->total === 0           => SweepOutcome::Skipped,
			$this->read === $this->total => SweepOutcome::Completed,
			$this->read > 0              => SweepOutcome::Partial,
			default                      => SweepOutcome::Failed,
		};
	}
}
