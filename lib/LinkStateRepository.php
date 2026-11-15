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
 * Applies iblinkinfo port states to stored ports, in one transaction.
 *
 * iblinkinfo names a switch port by the switch's shared port GUID and an
 * adapter or router port by its own port GUID, never by node GUID
 * (docs/research/identity.md). A record is joined to a node through the
 * port 0 GUID discovery stored for a switch, or through the port GUID
 * stored for an adapter or router port. A record that joins to nothing is
 * counted, not guessed at: it is a node discovery has not imported yet.
 *
 * Only present, unconflicted nodes are updated. A stored port of such a
 * node that iblinkinfo did not print is missing, with its link unknown,
 * unless a line of that node was skipped: its other ports then keep their
 * last state and age into stale, as the skipped line may have named them.
 */
final class LinkStateRepository {
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	/**
	 * @return int|null records that matched no node, or null when nothing was applied
	 */
	public function import(Fabric $fabric, LinkReport $report): ?int {
		$records = $report->records;

		$nodes = (new NodeRepository($this->connection))->forFabric($fabric->id);

		if ($nodes === null) {
			return null;
		}

		$switches = [];
		$adapters = [];
		$byGuid   = [];
		$targets  = [];

		foreach ($nodes as $node) {
			if (!$node->sweepable()) {
				continue;
			}

			$targets[$node->id] = $node;

			if ($node->type === NodeType::Switch) {
				if ($node->port0Guid !== null) {
					$switches[$node->port0Guid->text] = $node->id;
				}

				continue;
			}

			$ports = (new NodeRepository($this->connection))->ports($node->id);

			if ($ports === null) {
				return null;
			}

			foreach ($ports as $port) {
				if ($port->portGuid !== null) {
					$adapters[$port->portGuid->text . ':' . $port->number] = $node->id;
					$byGuid[$port->portGuid->text]                         = $node->id;
				}
			}
		}

		$now       = $this->clock->now();
		$unmatched = 0;
		$seen      = [];

		$tx = new TransactionRepository($this->connection);

		if (!$tx->begin()) {
			return null;
		}

		foreach ($records as $record) {
			$nodeId = $switches[$record->portGuid->text] ?? $adapters[$record->portGuid->text . ':' . $record->port] ?? null;

			if ($nodeId === null) {
				$unmatched++;

				continue;
			}

			$seen[$nodeId][] = $record->port;
			$row             = $record->link->toRow();

			$tx->write(
				'INSERT INTO plugin_fabric_port (node_id, port_number, presence, first_seen, last_seen, logical_state, phys_state, active_width, active_speed, best_width, best_speed, enabled_width, enabled_speed, has_peer, link_observed_at)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
				ON DUPLICATE KEY UPDATE presence = VALUES(presence), last_seen = VALUES(last_seen), logical_state = VALUES(logical_state), phys_state = VALUES(phys_state), active_width = VALUES(active_width), active_speed = VALUES(active_speed), best_width = VALUES(best_width), best_speed = VALUES(best_speed), enabled_width = VALUES(enabled_width), enabled_speed = VALUES(enabled_speed), has_peer = VALUES(has_peer), link_observed_at = VALUES(link_observed_at)',
				[$nodeId, $record->port, Presence::Present->value, $now, $now, $row['logical_state'], $row['phys_state'], $row['active_width'], $row['active_speed'], $row['best_width'], $row['best_speed'], $row['enabled_width'], $row['enabled_speed'], $row['has_peer'], $now],
			);
		}

		$unknown = LinkState::unknown()->toRow();
		$partial = [];

		foreach ($report->skipped as $guid) {
			$nodeId = $switches[$guid->text] ?? $byGuid[$guid->text] ?? null;

			if ($nodeId !== null) {
				$partial[$nodeId] = true;
			}
		}

		foreach (array_keys($targets) as $nodeId) {
			if (isset($partial[$nodeId])) {
				continue;
			}

			$reported     = $seen[$nodeId] ?? [];
			$placeholders = $reported === [] ? '' : ' AND port_number NOT IN (' . implode(', ', array_fill(0, count($reported), '?')) . ')';

			$tx->write(
				"UPDATE plugin_fabric_port SET presence = ?, logical_state = ?, phys_state = ?, active_width = 0, active_speed = '', best_width = 0, best_speed = '', enabled_width = 0, enabled_speed = '', has_peer = 0, link_observed_at = ? WHERE node_id = ?$placeholders",
				[Presence::Missing->value, $unknown['logical_state'], $unknown['phys_state'], $now, $nodeId, ...$reported],
			);
		}

		if (!$tx->commit()) {
			Log::error(sprintf('Fabric %d: link states not applied: a write failed or the transaction ended', $fabric->id));

			return null;
		}

		return $unmatched;
	}
}
