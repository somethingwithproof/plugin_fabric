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
 * Proposed re-links: a new adapter that took over the neighbor port of a
 * missing one, most often a replaced HCA (REQ-INV-006). The plugin never
 * moves a device mapping on its own; an operator confirms or rejects.
 */
final class RelinkRepository {
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	/**
	 * Open proposals with their node GUIDs, newest first.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	public function open(): ?array {
		return Row::rows(db_fetch_assoc_prepared(
			'SELECT r.id, r.fabric_id, r.old_node_id, r.new_node_id, r.neighbor_guid, r.neighbor_port, r.proposed_at, o.guid AS old_guid, o.description AS old_description, o.host_id AS old_host_id, n.guid AS new_guid, n.description AS new_description, n.host_id AS new_host_id
			FROM plugin_fabric_relink r
			INNER JOIN plugin_fabric_node o ON o.id = r.old_node_id
			INNER JOIN plugin_fabric_node n ON n.id = r.new_node_id
			WHERE r.state = ?
			ORDER BY r.proposed_at DESC, r.id DESC',
			[RelinkState::Proposed->value],
			true,
			$this->connection,
		));
	}

	/**
	 * One proposal with the devices of both nodes, in any state.
	 *
	 * @return array<array-key, mixed>|null
	 */
	public function find(int $id): ?array {
		$row = db_fetch_row_prepared(
			'SELECT r.id, r.state, r.old_node_id, r.new_node_id, o.host_id AS old_host_id, n.host_id AS new_host_id
			FROM plugin_fabric_relink r
			INNER JOIN plugin_fabric_node o ON o.id = r.old_node_id
			INNER JOIN plugin_fabric_node n ON n.id = r.new_node_id
			WHERE r.id = ?',
			[$id],
			true,
			$this->connection,
		);

		return is_array($row) && $row !== [] ? $row : null;
	}

	/**
	 * Move the old node's device mapping to the new node and mark the old
	 * node replaced. Applies only while the proposal is open, the old node
	 * is still missing, and the new node is still present and unmapped; any
	 * other open proposal for either node is rejected with it. The device
	 * check uses the same node rows whose revisions the writes require, so
	 * a mapping changed in between makes the confirmation fail.
	 *
	 * @return int|null the device that moved (0 when the old node had none), or null when nothing changed
	 */
	public function confirm(int $id, DeviceAccess $access): ?int {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_fabric_relink WHERE id = ? AND state = ?', [$id, RelinkState::Proposed->value], true, $this->connection);

		if (!is_array($row)) {
			return null;
		}

		$nodes = new NodeRepository($this->connection);
		$old   = $nodes->find(Row::int($row, 'old_node_id'));
		$new   = $nodes->find(Row::int($row, 'new_node_id'));

		if ($old === null || $new === null || !$access->node($old) || !$access->node($new) || $old->presence !== Presence::Missing || $new->presence !== Presence::Present || $new->hostId !== 0) {
			return null;
		}

		$now = $this->clock->now();

		$tx = new TransactionRepository($this->connection);

		if (!$tx->begin()) {
			return null;
		}

		$steps = [
			['UPDATE plugin_fabric_relink SET state = ?, decided_at = ? WHERE id = ? AND state = ?', [RelinkState::Confirmed->value, $now, $id, RelinkState::Proposed->value], 1],
			['UPDATE plugin_fabric_node SET host_id = ?, revision = revision + 1 WHERE id = ? AND revision = ?', [$old->hostId, $new->id, $new->revision], 1],
			['UPDATE plugin_fabric_node SET host_id = 0, presence = ?, revision = revision + 1 WHERE id = ? AND revision = ?', [Presence::Replaced->value, $old->id, $old->revision], 1],
			['UPDATE plugin_fabric_relink SET state = ?, decided_at = ? WHERE state = ? AND id != ? AND (old_node_id IN (?, ?) OR new_node_id IN (?, ?))', [RelinkState::Rejected->value, $now, RelinkState::Proposed->value, $id, $old->id, $new->id, $old->id, $new->id]],
		];

		foreach ($steps as $step) {
			$tx->write($step[0], $step[1], $step[2] ?? null);
		}

		// The revision guards make $old->hostId the device that moved.
		return $tx->commit() ? $old->hostId : null;
	}

	public function reject(int $id): bool {
		$ok = db_execute_prepared('UPDATE plugin_fabric_relink SET state = ?, decided_at = ? WHERE id = ? AND state = ?', [RelinkState::Rejected->value, $this->clock->now(), $id, RelinkState::Proposed->value], true, $this->connection);

		return $ok !== false && db_affected_rows($this->connection) === 1;
	}
}
