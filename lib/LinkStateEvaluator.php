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
 * The link-state rule (docs/PLAN.md "Counters and link state",
 * docs/research/link-downgrade.md), applied to what was recorded:
 *
 * - Logical state Down is down, and a down port is never degraded.
 * - Any other state but Active is unknown, as is an Active port whose peer
 *   discovery did not find: no peer means no "best" to compare with.
 * - An Active port with a peer is below best when its active width is
 *   below the best width (by lane count) or its active speed below the
 *   best speed (by rate), the rule iblinkinfo uses for "Could be".
 * - Below best is degraded only when known enabled values do not explain
 *   it, and limited when they do. Without enabled values, which the
 *   diagnostics backend never has, it stays below best with the cause
 *   unknown (owner decision, ADR 0009).
 * - A width or speed that is unknown makes the result unknown.
 *
 * REQ-LINK-005 reads "no remote end or down state is down"; revision 2 of
 * the plan narrowed it to logical state Down after research showed that a
 * missing peer can be a discovery gap on a working link.
 */
final class LinkStateEvaluator {
	public function evaluate(LinkState $link): LinkCondition {
		if ($link->logical === LogicalState::Down) {
			return LinkCondition::Down;
		}

		if ($link->logical !== LogicalState::Active || !$link->hasPeer) {
			return LinkCondition::Unknown;
		}

		if ($link->activeWidth === null || $link->activeSpeed === null || $link->bestWidth === null || $link->bestSpeed === null) {
			return LinkCondition::Unknown;
		}

		$belowWidth = $link->activeWidth->lanes() < $link->bestWidth->lanes();
		$belowSpeed = $link->activeSpeed->rank() < $link->bestSpeed->rank();

		if (!$belowWidth && !$belowSpeed) {
			return LinkCondition::Up;
		}

		if (($belowWidth && $link->enabledWidth === null) || ($belowSpeed && $link->enabledSpeed === null)) {
			return LinkCondition::BelowBest;
		}

		$widthExplained = !$belowWidth || ($link->enabledWidth !== null && $link->activeWidth->lanes() >= $link->enabledWidth->lanes());
		$speedExplained = !$belowSpeed || ($link->enabledSpeed !== null && $link->activeSpeed->rank() >= $link->enabledSpeed->rank());

		return $widthExplained && $speedExplained ? LinkCondition::Limited : LinkCondition::Degraded;
	}
}
