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

final class FabricRepository {
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	public function find(int $id): ?Fabric {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_fabric_fabric WHERE id = ?', [$id], true, $this->connection);

		return is_array($row) ? Fabric::fromRow($row) : null;
	}

	/**
	 * @return list<Fabric>|null
	 */
	public function all(): ?array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_fabric_fabric ORDER BY name, id', [], true, $this->connection));

		if ($rows === null) {
			return null;
		}

		$out = [];

		foreach ($rows as $row) {
			$fabric = Fabric::fromRow($row);

			if ($fabric !== null) {
				$out[] = $fabric;
			}
		}

		return $out;
	}

	public function nameTaken(string $name, int $exceptId): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_fabric_fabric WHERE name = ? AND id != ?', [$name, $exceptId], '', true, $this->connection) > 0;
	}

	/**
	 * Whether another fabric already sweeps from this adapter port on this
	 * poller. Two fabrics there would see the same nodes, and the second
	 * would import each one again, unmapped, under its own records.
	 */
	public function portTaken(Fabric $fabric): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_fabric_fabric WHERE poller_id = ? AND adapter = ? AND adapter_port = ? AND id != ?', [$fabric->pollerId, $fabric->adapter, $fabric->adapterPort, $fabric->id], '', true, $this->connection) > 0;
	}

	/**
	 * Insert or update, returning the id, or 0 when the write failed or the
	 * fabric no longer exists. The unique key on poller, adapter, and port
	 * refuses a write that portTaken() would have caught, if another save
	 * raced this one.
	 */
	public function save(Fabric $fabric): int {
		$now    = $this->clock->now();
		$values = [$fabric->name, $fabric->backend->value, $fabric->pollerId, $fabric->interval, $fabric->adapter, $fabric->adapterPort, $fabric->adminState->value];

		if ($fabric->id === 0) {
			$ok = db_execute_prepared('INSERT INTO plugin_fabric_fabric (name, backend, poller_id, sweep_interval, adapter, adapter_port, admin_state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$values, $now, $now], true, $this->connection);

			return $ok !== false ? (int) db_fetch_insert_id($this->connection) : 0;
		}

		$ok = db_execute_prepared('UPDATE plugin_fabric_fabric SET name = ?, backend = ?, poller_id = ?, sweep_interval = ?, adapter = ?, adapter_port = ?, admin_state = ?, updated_at = ? WHERE id = ?', [...$values, $now, $fabric->id], true, $this->connection);

		return $ok !== false && $this->find($fabric->id) !== null ? $fabric->id : 0;
	}

	/**
	 * Remove a fabric with its inventory, samples, proposals, claims, and
	 * health. Rows go children first, so an interrupted delete leaves a
	 * fabric that can be deleted again. No Cacti device is touched and the
	 * fabric is not contacted.
	 */
	public function delete(int $id): bool {
		if ($this->find($id) === null) {
			return false;
		}

		$statements = [
			'DELETE s FROM plugin_fabric_sample s INNER JOIN plugin_fabric_node n ON n.id = s.node_id WHERE n.fabric_id = ?',
			'DELETE w FROM plugin_fabric_node_sweep w INNER JOIN plugin_fabric_node n ON n.id = w.node_id WHERE n.fabric_id = ?',
			"DELETE c FROM plugin_fabric_claim c INNER JOIN plugin_fabric_node n ON n.id = c.claim_key AND c.scope = 'node' WHERE n.fabric_id = ?",
			'DELETE p FROM plugin_fabric_port p INNER JOIN plugin_fabric_node n ON n.id = p.node_id WHERE n.fabric_id = ?',
			'DELETE FROM plugin_fabric_relink WHERE fabric_id = ?',
			'DELETE FROM plugin_fabric_node WHERE fabric_id = ?',
			"DELETE FROM plugin_fabric_claim WHERE scope = 'topology' AND claim_key = ?",
			'DELETE FROM plugin_fabric_health WHERE fabric_id = ?',
			'DELETE FROM plugin_fabric_fabric WHERE id = ?',
		];

		foreach ($statements as $sql) {
			if (db_execute_prepared($sql, [$id], true, $this->connection) === false) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Counts shown on the delete confirmation.
	 *
	 * @return array{nodes: int, mapped: int}
	 */
	public function deleteCounts(int $id): array {
		return [
			'nodes'  => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_fabric_node WHERE fabric_id = ?', [$id], '', true, $this->connection),
			'mapped' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_fabric_node WHERE fabric_id = ? AND host_id > 0', [$id], '', true, $this->connection),
		];
	}
}
