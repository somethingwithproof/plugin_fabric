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
 * Port logical state. number() is the PortInfo encoding, the planned
 * port_state value of the state data source.
 */
enum LogicalState: string {
	case Down       = 'down';
	case Initialize = 'init';
	case Armed      = 'armed';
	case Active     = 'active';

	/**
	 * The text iblinkinfo prints (libibmad mad_dump_portstate()). "NoChange"
	 * and "?(n)" are unknown.
	 */
	public static function fromText(string $text): ?self {
		return match (trim($text)) {
			'Down'       => self::Down,
			'Initialize' => self::Initialize,
			'Armed'      => self::Armed,
			'Active'     => self::Active,
			default      => null,
		};
	}

	/**
	 * Number.
	 *
	 * @return int
	 */
	public function number(): int {
		return match ($this) {
			self::Down       => 1,
			self::Initialize => 2,
			self::Armed      => 3,
			self::Active     => 4,
		};
	}
}
