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

use Somethingwithproof\Fabric\NodeRepository;
use Somethingwithproof\Fabric\Node;

/** @return list<array{id: string, name: string, state: string, observed: int, interval: int, detail: string}> */
function fabric_operator_rows(): array {
	$rows  = [];
	$nodes = (new NodeRepository(false))->all();

	if ($nodes === null) {
		throw new RuntimeException('Inventory unavailable');
	}

	foreach ($nodes as $node) {
		if ($node->hostId <= 0 || !fabric_device_allowed($node->hostId)) {
			continue;
		}
		$rows[] = [
			'id' => (string) $node->id,
			'name' => Node::labelOf($node->description, $node->guid->text),
			'state' => $node->presence->value . ' / ' . $node->identity->value,
			'observed' => $node->lastSeen,
			'interval' => max(60, (int) read_config_option('poller_interval')),
			'detail'       => __('Inspect port validity, link width and speed, and the congestion view. Escalate identity conflicts and device relinking to an administrator; do not reset counters.', 'fabric')
		];
	}

	return $rows;
}
