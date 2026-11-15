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
 * Port physical state, as iblinkinfo prints it (libibmad
 * mad_dump_physportstate()). Recorded for display; the link-state rule
 * reads the logical state only.
 */
enum PhysState: string {
	case Sleep             = 'sleep';
	case Polling           = 'polling';
	case Disabled          = 'disabled';
	case Training          = 'training';
	case LinkUp            = 'linkup';
	case LinkErrorRecovery = 'error_recovery';
	case PhyTest           = 'phytest';

	public static function fromText(string $text): ?self {
		return match (trim($text)) {
			'Sleep'                     => self::Sleep,
			'Polling'                   => self::Polling,
			'Disabled'                  => self::Disabled,
			'PortConfigurationTraining' => self::Training,
			'LinkUp'                    => self::LinkUp,
			'LinkErrorRecovery'         => self::LinkErrorRecovery,
			'PhyTest'                   => self::PhyTest,
			default                     => null,
		};
	}
}
