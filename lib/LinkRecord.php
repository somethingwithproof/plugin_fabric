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
 * One port line of iblinkinfo --line. The tool names the port by its port
 * GUID, which every external port of a switch shares with the switch's
 * port 0 (docs/research/identity.md), so the port is identified by that
 * GUID and the port number together, then joined to a node record.
 */
final readonly class LinkRecord {
	/**
	 * Construct a new LinkRecord instance.
	 *
	 * @param Guid $portGuid
	 * @param int $lid
	 * @param int $port
	 * @param ?Guid $remotePortGuid
	 * @param ?int $remotePort
	 * @param LinkState $link
	 */
	public function __construct(
		public Guid $portGuid,
		public int $lid,
		public int $port,
		public ?Guid $remotePortGuid,
		public ?int $remotePort,
		public LinkState $link,
	) {
	}
}
