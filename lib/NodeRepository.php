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
 * Stored nodes and their ports. Reads return null on a database error,
 * never an empty list (see Row::rows()). Operator changes are guarded by
 * the node's revision: a change applies only if the node is unchanged
 * since the page read it, so a sweep that ran in between is never undone.
 */
final class NodeRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	public function find(int $id): ?Node {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_fabric_node WHERE id = ?', [$id], true, $this->connection);

		return is_array($row) ? Node::fromRow($row) : null;
	}

	/**
	 * @return list<Node>|null
	 */
	public function forFabric(int $fabricId): ?array {
		return $this->nodes(Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_fabric_node WHERE fabric_id = ? ORDER BY guid', [$fabricId], true, $this->connection)));
	}

	/**
	 * Nodes mapped to a Cacti device, for the script server path in M3.
	 *
	 * @return list<Node>|null
	 */
	public function forHost(int $hostId): ?array {
		return $this->nodes(Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_fabric_node WHERE host_id = ? ORDER BY fabric_id, guid', [$hostId], true, $this->connection)));
	}

	/**
	 * @return list<Node>|null
	 */
	public function all(): ?array {
		return $this->nodes(Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_fabric_node ORDER BY fabric_id, guid', [], true, $this->connection)));
	}

	/**
	 * @return array<int, Port>|null port number => port
	 */
	public function ports(int $nodeId): ?array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_fabric_port WHERE node_id = ? ORDER BY port_number', [$nodeId], true, $this->connection));

		if ($rows === null) {
			return null;
		}

		$out = [];

		foreach ($rows as $row) {
			$port = Port::fromRow($row);

			if ($port !== null) {
				$out[$port->number] = $port;
			}
		}

		return $out;
	}

	/**
	 * Accept the conflicting sighting as the node's identity: the operator
	 * confirmed the device behind the GUID changed (REQ-INV-005). The node
	 * is swept again from the next cycle. The sighting is recorded as
	 * accepted, so a later discovery that sees the same thing again,
	 * such as the same GUID printed twice, raises no new conflict.
	 */
	public function acceptConflict(Node $node): bool {
		$seen = $node->conflictSeen;

		if ($node->identity !== IdentityState::Conflict || $seen === null) {
			return false;
		}

		return $this->guarded($node, 'node_type = ?, port_count = ?, device_id = ?, vendor_id = ?, sysimg_guid = ?, identity = ?, conflict_seen = ?, accepted_signature = ?', [
			$seen->type->value, $seen->portCount, $seen->deviceId, $seen->vendorId, $seen->sysimgGuid->text ?? '', IdentityState::Ok->value, '', $node->conflictSignature,
		]);
	}

	/**
	 * Map a node to a Cacti device or, with 0, unmap it. Mapping never
	 * creates or changes the device.
	 */
	public function setHost(Node $node, int $hostId): bool {
		return $this->guarded($node, 'host_id = ?', [max(0, $hostId)]);
	}

	/**
	 * @param list<int|string> $values
	 */
	private function guarded(Node $node, string $set, array $values): bool {
		$ok = db_execute_prepared("UPDATE plugin_fabric_node SET $set, revision = revision + 1 WHERE id = ? AND revision = ?", [...$values, $node->id, $node->revision], true, $this->connection);

		return $ok !== false && db_affected_rows($this->connection) === 1;
	}

	/**
	 * @param list<array<array-key, mixed>>|null $rows
	 *
	 * @return list<Node>|null
	 */
	private function nodes(?array $rows): ?array {
		if ($rows === null) {
			return null;
		}

		$out = [];

		foreach ($rows as $row) {
			$node = Node::fromRow($row);

			if ($node !== null) {
				$out[] = $node;
			}
		}

		return $out;
	}
}
