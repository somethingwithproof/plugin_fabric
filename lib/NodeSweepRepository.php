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
 * The last counter sweep of each node: when it ran, how long it took, and
 * how many ports it read, failed, or left for the next cycle
 * (REQ-HEALTH-001).
 */
final class NodeSweepRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * The last attempt time, 0 for never, or null on a database error.
	 */
	public function lastAttempt(int $nodeId): ?int {
		// db_fetch_cell_prepared() returns false for both no row and an
		// error; the row helper tells them apart.
		$row = db_fetch_row_prepared('SELECT last_attempt FROM plugin_fabric_node_sweep WHERE node_id = ?', [$nodeId], true, $this->connection);

		return is_array($row) ? Row::int($row, 'last_attempt') : null;
	}

	public function start(int $nodeId, int $now): bool {
		return db_execute_prepared(
			'INSERT INTO plugin_fabric_node_sweep (node_id, last_attempt) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_attempt = VALUES(last_attempt)',
			[$nodeId, $now],
			true,
			$this->connection,
		) !== false;
	}

	public function finish(int $nodeId, NodeSweepResult $result, int $now): bool {
		return db_execute_prepared(
			'UPDATE plugin_fabric_node_sweep SET last_success = IF(? IN (?, ?), ?, last_success), duration = ?, ports_total = ?, ports_read = ?, ports_failed = ?, ports_skipped = ?, outcome = ?, error = ? WHERE node_id = ?',
			[$result->outcome()->value, SweepOutcome::Completed->value, SweepOutcome::Partial->value, $now, round($result->duration, 3), $result->total, $result->read, $result->failed, $result->skipped, $result->outcome()->value, $result->error->value ?? '', $nodeId],
			true,
			$this->connection,
		) !== false;
	}

	/**
	 * @return array<int, array<array-key, mixed>>|null node id => row
	 */
	public function all(): ?array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_fabric_node_sweep', [], true, $this->connection));

		if ($rows === null) {
			return null;
		}

		$out = [];

		foreach ($rows as $row) {
			$out[Row::int($row, 'node_id')] = $row;
		}

		return $out;
	}
}
