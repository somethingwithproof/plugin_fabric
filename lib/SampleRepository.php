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
	/**
	 * Construct a new SampleRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Replace a port's sample of one group, with the rates and
	 * discontinuities against the sample it replaces.
	 *
	 * @param array<array-key, mixed>|null $previous the stored row of the same port and group, null for none
	 *
	 * @return CounterDelta|null what changed, or null when the write failed
	 */
	public function record(int $nodeId, Counters $counters, Backend $backend, int $observedAt, ?array $previous = null): ?CounterDelta {
		$before   = $previous === null ? null : Counters::valuesFromJson(Row::string($previous, 'counters'));
		$beforeAt = $previous === null ? 0 : Row::int($previous, 'observed_at', 0, 0);
		$delta    = CounterDelta::between($before, $beforeAt, $counters, $observedAt);
		$count    = $previous === null ? 0 : Row::int($previous, 'discontinuities', 0, 0);
		$lastAt   = $previous === null ? 0 : Row::int($previous, 'discontinuity_at', 0, 0);

		if ($delta->reset !== []) {
			$count++;
			$lastAt = $observedAt;
		}

		$ok = db_execute_prepared(
			"INSERT INTO plugin_fabric_sample (node_id, port_number, counter_group, observed_at, attempted_at, backend, cap_mask, cap_mask2, counters, error, previous_at, rates, reset, discontinuities, discontinuity_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE observed_at = VALUES(observed_at), attempted_at = VALUES(attempted_at), backend = VALUES(backend), cap_mask = VALUES(cap_mask), cap_mask2 = VALUES(cap_mask2), counters = VALUES(counters), error = '',
				previous_at = VALUES(previous_at), rates = VALUES(rates), reset = VALUES(reset), discontinuities = VALUES(discontinuities), discontinuity_at = VALUES(discontinuity_at)",
			[$nodeId, $counters->port, $counters->group->value, $observedAt, $observedAt, $backend->value, $counters->capMask, $counters->capMask2 ?? 0, $counters->toJson(), $delta->since, $delta->ratesJson(), $delta->resetJson(), $count, $lastAt],
			true,
			$this->connection,
		);

		return $ok === false ? null : $delta;
	}

	/**
	 * Record failure.
	 *
	 * @param int $nodeId
	 * @param int $port
	 * @param CounterGroup $group
	 * @param CommandError $error
	 * @param int $attemptedAt
	 * @return bool
	 */
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

	/**
	 * A node's rows keyed by port and group, as CounterSweep compares them.
	 *
	 * @return array<string, array<array-key, mixed>>|null "port:group" => row
	 */
	public function keyed(int $nodeId): ?array {
		$rows = $this->forNode($nodeId);

		if ($rows === null) {
			return null;
		}

		$out = [];

		foreach ($rows as $row) {
			$out[Row::int($row, 'port_number') . ':' . Row::string($row, 'counter_group')] = $row;
		}

		return $out;
	}

	/**
	 * Load every stored counter sample for a fabric in one query, grouped by
	 * node_id and "port:group" key, avoiding per-node N+1 queries in UfmSweep.
	 *
	 * @param int $fabricId fabric identifier
	 *
	 * @return array<int, array<string, array<array-key, mixed>>>|null node_id => ("port:group" => row)
	 */
	public function keyedForFabric(int $fabricId): ?array {
		$rows = Row::rows(db_fetch_assoc_prepared(
			'SELECT s.* FROM plugin_fabric_sample s INNER JOIN plugin_fabric_node n ON n.id = s.node_id WHERE n.fabric_id = ? ORDER BY s.node_id, s.port_number, s.counter_group',
			[$fabricId],
			true,
			$this->connection,
		));

		if ($rows === null) {
			return null;
		}

		$out = [];

		foreach ($rows as $row) {
			$nodeId = Row::int($row, 'node_id');

			if ($nodeId > 0) {
				$out[$nodeId][Row::int($row, 'port_number') . ':' . Row::string($row, 'counter_group')] = $row;
			}
		}

		return $out;
	}
}
