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
 * One connected port in a discovery. ibnetdiscover's topology output lists
 * only ports with a remote end, so every TopologyPort has one. The port
 * GUID is printed for channel adapter and router ports only, and is an
 * attribute (docs/research/identity.md, rule 3).
 */
final readonly class TopologyPort {
	public function __construct(
		public int $number,
		public ?Guid $portGuid,
		public int $lid,
		public PortRef $remote,
		public ?LinkWidth $width,
		public ?LinkSpeed $speed,
	) {
	}
}
