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
 * Cacti device permissions applied to fabric records. Fabric Admin is a
 * realm, not a device grant: an admin whose device permissions are
 * restricted may change only records whose devices it may see.
 *
 * - A node mapped to a device needs that device. An unmapped node needs
 *   none.
 * - A fabric needs every device mapped to one of its nodes, because
 *   changing or deleting it changes what is recorded for all of them.
 * - Mapping a node to a device needs that device too.
 *
 * The caller supplies the check, so this class stays free of Cacti's
 * session.
 */
final class DeviceAccess {
	/**
	 * @param \Closure(int): bool $allowed host id => whether the user may see it
	 */
	public function __construct(private readonly \Closure $allowed) {
	}

	public function host(int $hostId): bool {
		return $hostId === 0 || ($this->allowed)($hostId);
	}

	public function node(Node $node): bool {
		return $this->host($node->hostId);
	}

	/**
	 * @param list<Node> $nodes every stored node, or at least the fabric's
	 */
	public function fabric(int $fabricId, array $nodes): bool {
		foreach ($nodes as $node) {
			if ($node->fabricId === $fabricId && !$this->node($node)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param list<Node> $nodes
	 *
	 * @return list<Node>
	 */
	public function visible(array $nodes): array {
		return array_values(array_filter($nodes, fn (Node $n): bool => $this->node($n)));
	}
}
