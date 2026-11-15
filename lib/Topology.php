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
 * A parsed discovery: every node it printed, keyed by GUID text, and the
 * local node the discovery started from.
 */
final readonly class Topology {
	/**
	 * @param array<string, TopologyNode> $nodes      GUID text => node
	 * @param list<string>                $duplicates GUID texts printed in more than one node block
	 */
	public function __construct(public array $nodes, public ?Guid $local, public array $duplicates) {
	}

	public function portCount(): int {
		return array_sum(array_map(static fn (TopologyNode $n): int => count($n->ports), $this->nodes));
	}
}
