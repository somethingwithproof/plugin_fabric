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
 * One node block of a discovery. lid is the switch's port 0 LID; adapter
 * LIDs belong to their ports.
 */
final readonly class TopologyNode {
	/**
	 * @param array<int, TopologyPort> $ports port number => port
	 */
	public function __construct(
		public Guid $guid,
		public NodeType $type,
		public int $portCount,
		public int $deviceId,
		public int $vendorId,
		public ?Guid $sysimgGuid,
		public ?Guid $port0Guid,
		public string $description,
		public int $lid,
		public array $ports,
	) {
	}

	public function identity(): NodeIdentity {
		return new NodeIdentity($this->type, $this->portCount, $this->deviceId, $this->vendorId, $this->sysimgGuid);
	}
}
