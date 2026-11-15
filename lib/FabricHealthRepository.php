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
 * The last topology sweep of each fabric: discovery and link-state runs
 * recorded separately, each with its attempt, last success, duration, and
 * error, and the privilege warnings found on the poller (REQ-HEALTH-001).
 */
final class FabricHealthRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * @return array<array-key, mixed>|null the row, an empty array for never, or null on a database error
	 */
	public function find(int $fabricId): ?array {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_fabric_health WHERE fabric_id = ?', [$fabricId], true, $this->connection);

		return is_array($row) ? $row : ($row === false ? null : []);
	}

	public function discovery(int $fabricId, int $attempt, ?CommandError $error, float $duration, int $nodes, int $ports, ?Guid $local): bool {
		return $this->upsert($fabricId, $error === null
			? ['discovery_attempt' => $attempt, 'discovery_success' => $attempt, 'discovery_duration' => round($duration, 3), 'discovery_error' => '', 'nodes_seen' => $nodes, 'ports_seen' => $ports, 'local_guid' => $local->text ?? '']
			: ['discovery_attempt' => $attempt, 'discovery_duration' => round($duration, 3), 'discovery_error' => $error->value]);
	}

	public function links(int $fabricId, int $attempt, ?CommandError $error, float $duration, int $unmatched): bool {
		return $this->upsert($fabricId, $error === null
			? ['links_attempt' => $attempt, 'links_success' => $attempt, 'links_duration' => round($duration, 3), 'links_error' => '', 'links_unmatched' => $unmatched]
			: ['links_attempt' => $attempt, 'links_duration' => round($duration, 3), 'links_error' => $error->value]);
	}

	/**
	 * @param list<string> $codes
	 */
	public function privilege(int $fabricId, array $codes): bool {
		return $this->upsert($fabricId, ['privilege' => implode(',', $codes)]);
	}

	/**
	 * @param array<string, int|float|string> $values column => value; columns are this class's literals
	 */
	private function upsert(int $fabricId, array $values): bool {
		$columns = array_keys($values);
		$update  = implode(', ', array_map(static fn (string $c): string => "$c = VALUES($c)", $columns));

		return db_execute_prepared(
			'INSERT INTO plugin_fabric_health (fabric_id, ' . implode(', ', $columns) . ') VALUES (?' . str_repeat(', ?', count($columns)) . ") ON DUPLICATE KEY UPDATE $update",
			[$fabricId, ...array_values($values)],
			true,
			$this->connection,
		) !== false;
	}
}
