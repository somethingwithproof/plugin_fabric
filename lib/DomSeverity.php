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
 * Optical transceiver Digital Optical Monitoring (DOM) severity state
 * following SFF-8636 and CMIS alarm/warning threshold semantics.
 */
enum DomSeverity: string {
	case Ok      = 'ok';
	case Warning = 'warning';
	case Alarm   = 'alarm';
	case Unknown = 'unknown';

	/**
	 * Numeric severity rank for combining per-metric DOM assessments.
	 *
	 * @return int
	 */
	public function rank(): int {
		return match ($this) {
			self::Unknown => 0,
			self::Ok      => 1,
			self::Warning => 2,
			self::Alarm   => 3,
		};
	}

	/**
	 * Return the higher-severity DOM state between two assessments.
	 *
	 * @param self $a first severity
	 * @param self $b second severity
	 *
	 * @return self
	 */
	public static function worst(self $a, self $b): self {
		return $a->rank() >= $b->rank() ? $a : $b;
	}
}
