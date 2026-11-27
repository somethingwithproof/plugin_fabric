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
 * Forward Error Correction (FEC) and pre-FEC Bit Error Rate (BER) link health
 * classification for high-speed InfiniBand and RoCEv2 fabrics (including
 * 400G NDR and 800G XDR PAM4 links).
 */
enum FecHealthLevel: string {
	case Healthy  = 'healthy';
	case Degraded = 'degraded';
	case Critical = 'critical';
	case Unknown  = 'unknown';

	/**
	 * Numeric severity rank for combining independent FEC checks.
	 *
	 * @return int
	 */
	public function rank(): int {
		return match ($this) {
			self::Unknown  => 0,
			self::Healthy  => 1,
			self::Degraded => 2,
			self::Critical => 3,
		};
	}

	/**
	 * Return the worse FEC health level of two assessments.
	 *
	 * @param self $a first level
	 * @param self $b second level
	 *
	 * @return self
	 */
	public static function worst(self $a, self $b): self {
		return $a->rank() >= $b->rank() ? $a : $b;
	}
}
