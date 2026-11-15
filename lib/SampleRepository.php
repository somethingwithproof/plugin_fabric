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
 * The latest counter sample per port and group (REQ-SWEEP-007). A read
 * replaces the values and their observation time. A failed read touches
 * only the attempt time and error, so the last good values keep their own
 * time and age into stale; they are never replaced by zeros.
 */
final class SampleRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	public function record(int $nodeId, Counters $counters, Backend $backend, int $observedAt): bool {
		return db_execute_prepared(
			"INSERT INTO plugin_fabric_sample (node_id, port_number, counter_group, observed_at, attempted_at, backend, cap_mask, cap_mask2, counters, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '')
			ON DUPLICATE KEY UPDATE observed_at = VALUES(observed_at), attempted_at = VALUES(attempted_at), backend = VALUES(backend), cap_mask = VALUES(cap_mask), cap_mask2 = VALUES(cap_mask2), counters = VALUES(counters), error = ''",
			[$nodeId, $counters->port, $counters->group->value, $observedAt, $observedAt, $backend->value, $counters->capMask, $counters->capMask2 ?? 0, $counters->toJson()],
			true,
			$this->connection,
		) !== false;
	}

	public function recordFailure(int $nodeId, int $port, CounterGroup $group, CommandError $error, int $attemptedAt): bool {
		return db_execute_prepared(
			"INSERT INTO plugin_fabric_sample (node_id, port_number, counter_group, observed_at, attempted_at, counters, error) VALUES (?, ?, ?, 0, ?, '', ?)
			ON DUPLICATE KEY UPDATE attempted_at = VALUES(attempted_at), error = VALUES(error)",
			[$nodeId, $port, $group->value, $attemptedAt, $error->value],
			true,
			$this->connection,
		) !== false;
	}

	/**
	 * @return list<array<array-key, mixed>>|null
	 */
	public function forNode(int $nodeId): ?array {
		return Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_fabric_sample WHERE node_id = ? ORDER BY port_number, counter_group', [$nodeId], true, $this->connection));
	}
}
