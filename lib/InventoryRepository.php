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
 * Applies a parsed discovery to the stored inventory (REQ-INV-001 to
 * REQ-INV-006), in one transaction, so a failure part way leaves the
 * inventory as it was.
 *
 * - A GUID not stored before becomes a new node, present, unmapped.
 * - A stored GUID whose identity attributes match is updated: description,
 *   LID, port 0 GUID, and its connected ports. Neighbor changes are
 *   recorded and never raise a conflict.
 * - A stored GUID seen with a different node type, port count, device ID,
 *   vendor ID, or system image GUID, or printed twice in one discovery,
 *   becomes a conflict. Its stored attributes are kept, the sighting is
 *   kept beside them, and the node is not swept until an operator decides.
 * - A stored node the discovery did not print becomes missing. It is never
 *   deleted and keeps its device mapping.
 * - A new adapter or router node whose port now reaches the neighbor port a
 *   missing node of the same type reached is proposed as its replacement.
 *   Nothing moves until an operator confirms (REQ-INV-006).
 *
 * No Cacti device, graph, or data source is created or changed.
 */
final class InventoryRepository {
	/** Tx state for InventoryRepository. */
	private TransactionRepository $tx;

	/**
	 * Construct a new InventoryRepository instance.
	 *
	 * @param \PDO|false $connection
	 * @param Clock $clock
	 */
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	/**
	 * @return InventoryResult|null null when the stored inventory could not be read or written
	 */
	/**
	 * @param (\Closure(): bool)|null $owned asked just before the commit
	 *                                       whether the caller still holds
	 *                                       its sweep claim
	 */
	public function import(Fabric $fabric, Topology $topology, ?\Closure $owned = null): ?InventoryResult {
		$nodes = new NodeRepository($this->connection);
		$known = $nodes->forFabric($fabric->id);

		if ($known === null) {
			Log::error(sprintf('Fabric %d: stored inventory could not be read; discovery not applied', $fabric->id));

			return null;
		}

		$byGuid = [];

		foreach ($known as $node) {
			$byGuid[$node->guid->text] = $node;
		}

		// Neighbor ports of the nodes that are about to go missing, for
		// replacement proposals. Read before anything changes.
		$vacated = [];

		foreach ($known as $node) {
			if ($node->presence === Presence::Present && !isset($topology->nodes[$node->guid->text]) && $node->type !== NodeType::Switch) {
				$ports = $nodes->ports($node->id);

				if ($ports === null) {
					return null;
				}

				foreach ($ports as $port) {
					if ($port->remote !== null) {
						$vacated[$port->remote->key()] = $node;
					}
				}
			}
		}

		$now      = $this->clock->now();
		$result   = new InventoryResult();
		$this->tx = new TransactionRepository($this->connection);

		if (!$this->tx->begin()) {
			return null;
		}

		try {
			foreach ($topology->nodes as $guid => $seen) {
				$stored    = $byGuid[$guid] ?? null;
				$duplicate = in_array($guid, $topology->duplicates, true);

				if ($stored === null) {
					$id = $this->insertNode($fabric, $seen, $duplicate, $topology->signature($guid), $now);
					$this->writePorts($id, $seen, $now);
					$result->added++;

					if (!$duplicate && $seen->type !== NodeType::Switch) {
						$this->propose($fabric, $id, $seen, $vacated, $now, $result);
					}

					continue;
				}

				$differences = $stored->identity()->differences($seen->identity());
				$signature   = $topology->signature($guid);
				// The operator already accepted exactly this sighting.
				$accepted    = $stored->acceptedSignature !== '' && hash_equals($stored->acceptedSignature, $signature);

				if (!$accepted && ($differences !== [] || $duplicate)) {
					$this->markConflict($stored, $seen, $signature, $now);
					$result->conflicts++;

					continue;
				}

				$this->updateNode($stored, $seen, $now);
				$this->writePorts($stored->id, $seen, $now);
				$result->updated++;
			}

			foreach ($known as $node) {
				if (!isset($topology->nodes[$node->guid->text]) && $node->presence === Presence::Present) {
					$this->write('UPDATE plugin_fabric_node SET presence = ?, revision = revision + 1 WHERE id = ?', [Presence::Missing->value, $node->id]);
					$this->write('UPDATE plugin_fabric_port SET presence = ? WHERE node_id = ?', [Presence::Missing->value, $node->id]);
					$result->missing++;
				}
			}
		} catch (\RuntimeException $e) {
			$this->tx->rollback();
			Log::error(sprintf('Fabric %d: discovery not applied: %s', $fabric->id, $e->getMessage()));

			return null;
		}

		if ($owned !== null && !$owned()) {
			$this->tx->rollback();
			Log::warning(sprintf('Fabric %d: discovery not applied: the sweep claim expired or another sweep took it', $fabric->id));

			return null;
		}

		if (!$this->tx->commit()) {
			Log::error(sprintf('Fabric %d: discovery not applied: the transaction did not commit', $fabric->id));

			return null;
		}

		return $result;
	}

	/**
	 * Insert node.
	 *
	 * @param Fabric $fabric
	 * @param TopologyNode $seen
	 * @param bool $duplicate
	 * @param string $signature
	 * @param int $now
	 * @return int
	 * @throws \RuntimeException
	 */
	private function insertNode(Fabric $fabric, TopologyNode $seen, bool $duplicate, string $signature, int $now): int {
		$this->write(
			'INSERT INTO plugin_fabric_node (fabric_id, guid, node_type, port_count, device_id, vendor_id, sysimg_guid, port0_guid, description, lid, host_id, presence, identity, conflict_seen, conflict_signature, first_seen, last_seen, revision) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, 0)',
			[$fabric->id, $seen->guid->text, $seen->type->value, $seen->portCount, $seen->deviceId, $seen->vendorId, $seen->sysimgGuid->text ?? '', $seen->port0Guid->text ?? '', $seen->description, $seen->lid, Presence::Present->value, ($duplicate ? IdentityState::Conflict : IdentityState::Ok)->value, $duplicate ? $seen->identity()->toJson() : '', $duplicate ? $signature : '', $now, $now],
		);

		$id = $this->tx->insertId();

		if ($id < 1) {
			throw new \RuntimeException('node insert returned no id');
		}

		return $id;
	}

	/**
	 * Update node.
	 *
	 * @param Node $stored
	 * @param TopologyNode $seen
	 * @param int $now
	 * @return void
	 */
	private function updateNode(Node $stored, TopologyNode $seen, int $now): void {
		$this->write(
			'UPDATE plugin_fabric_node SET port0_guid = ?, description = ?, lid = ?, presence = ?, last_seen = ?, revision = revision + 1 WHERE id = ?',
			[$seen->port0Guid->text ?? '', $seen->description, $seen->lid, Presence::Present->value, $now, $stored->id],
		);
	}

	/**
	 * The stored identity stays; the conflicting sighting is kept beside it
	 * for the operator. A conflict is never cleared by a later discovery,
	 * only by an operator, and a sighting the operator accepted does not
	 * raise it again until the discovery sees something else.
	 */
	private function markConflict(Node $stored, TopologyNode $seen, string $signature, int $now): void {
		$this->write(
			'UPDATE plugin_fabric_node SET identity = ?, conflict_seen = ?, conflict_signature = ?, presence = ?, last_seen = ?, revision = revision + 1 WHERE id = ?',
			[IdentityState::Conflict->value, $seen->identity()->toJson(), $signature, Presence::Present->value, $now, $stored->id],
		);

		if ($stored->identity === IdentityState::Ok) {
			Log::warning(sprintf('Fabric %d: node %s changed identity; it is not swept until an administrator resolves it', $stored->fabricId, $stored->guid->text));
		}
	}

	/**
	 * Connected ports are upserted with their topology. A stored port the
	 * discovery did not print keeps its row with no remote end: discovery
	 * lists connected ports only, so iblinkinfo decides its presence.
	 */
	private function writePorts(int $nodeId, TopologyNode $seen, int $now): void {
		$this->write("UPDATE plugin_fabric_port SET remote_guid = '', remote_port = 0 WHERE node_id = ?", [$nodeId]);

		foreach ($seen->ports as $port) {
			$this->write(
				'INSERT INTO plugin_fabric_port (node_id, port_number, port_guid, lid, remote_guid, remote_port, presence, first_seen, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE port_guid = VALUES(port_guid), lid = VALUES(lid), remote_guid = VALUES(remote_guid), remote_port = VALUES(remote_port), presence = VALUES(presence), last_seen = VALUES(last_seen)',
				[$nodeId, $port->number, $port->portGuid->text ?? '', $port->lid, $port->remote->node->text, $port->remote->port, Presence::Present->value, $now, $now],
			);
		}
	}

	/**
	 * @param array<string, Node> $vacated neighbor port key => node that reached it
	 */
	private function propose(Fabric $fabric, int $newId, TopologyNode $seen, array $vacated, int $now, InventoryResult $result): void {
		foreach ($seen->ports as $port) {
			$old = $vacated[$port->remote->key()] ?? null;

			if ($old === null || $old->type !== $seen->type) {
				continue;
			}

			$this->write(
				'INSERT IGNORE INTO plugin_fabric_relink (fabric_id, old_node_id, new_node_id, neighbor_guid, neighbor_port, state, proposed_at, decided_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0)',
				[$fabric->id, $old->id, $newId, $port->remote->node->text, $port->remote->port, RelinkState::Proposed->value, $now],
			);
			$result->proposals++;
		}
	}

	/**
	 * @param list<int|string> $params
	 */
	private function write(string $sql, array $params): void {
		if (!$this->tx->write($sql, $params)) {
			throw new \RuntimeException('inventory write failed or the transaction ended');
		}
	}
}
