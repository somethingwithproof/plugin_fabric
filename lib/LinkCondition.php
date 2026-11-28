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
 * The link dimension of measurement state (docs/PLAN.md "Measurement
 * state").
 */
enum LinkCondition: string {
	case Up        = 'up';
	case Limited   = 'limited';
	case BelowBest = 'below_best';
	case Degraded  = 'degraded';
	case Down      = 'down';
	case Unknown   = 'unknown';

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ($this) {
			self::Up        => __('Up', 'fabric'),
			self::Limited   => __('Below best, limited by enabled values', 'fabric'),
			self::BelowBest => __('Below best, cause unknown', 'fabric'),
			self::Degraded  => __('Degraded', 'fabric'),
			self::Down      => __('Down', 'fabric'),
			self::Unknown   => __('Unknown', 'fabric'),
		};
	}

	/**
	 * The planned "degraded" value of the state data source: 1 degraded; 0
	 * up, limited, or down (a down port is never degraded); and null,
	 * published as U, when below best without enabled values or unknown.
	 */
	public function degradedValue(): ?int {
		return match ($this) {
			self::Degraded                      => 1,
			self::Up, self::Limited, self::Down => 0,
			self::BelowBest, self::Unknown      => null,
		};
	}
}
