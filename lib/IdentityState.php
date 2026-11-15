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
 * Whether a node GUID still describes one device. The tools merge two
 * devices that claim one GUID without a word (docs/research/identity.md),
 * so a conflict is the plugin's own finding: the GUID came back with a
 * different node type, port count, device ID, vendor ID, or system image
 * GUID. A conflicted node is not swept until an operator resolves it
 * (REQ-INV-005).
 */
enum IdentityState: string {
	case Ok       = 'ok';
	case Conflict = 'conflict';
}
