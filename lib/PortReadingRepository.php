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
 * Builds PortReading objects from the stored inventory and samples. Reads
 * only plugin tables; returns null on a database error, never a partial
 * list.
 */
final class PortReadingRepository {
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	/**
	 * Every port of the nodes mapped to a device, ordered by node GUID and
	 * port number.
	 *
	 * @return list<PortReading>|null
	 */
	public function forHost(int $hostId): ?array {
		$nodes = (new NodeRepository($this->connection))->forHost($hostId);

		return $nodes === null ? null : $this->forNodes($nodes);
	}

	/**
	 * @param list<Node> $nodes
	 *
	 * @return list<PortReading>|null
	 */
	public function forNodes(array $nodes): ?array {
		$repository = new NodeRepository($this->connection);
		$samples    = new SampleRepository($this->connection);
		$fabrics    = new FabricRepository($this->connection, $this->clock);
		$intervals  = [];
		$out        = [];

		usort($nodes, static fn (Node $a, Node $b): int => [$a->guid->text, $a->fabricId] <=> [$b->guid->text, $b->fabricId]);

		foreach ($nodes as $node) {
			if (!isset($intervals[$node->fabricId])) {
				$intervals[$node->fabricId] = $fabrics->find($node->fabricId)->interval ?? Fabric::DEFAULT_INTERVAL;
			}

			$ports = $repository->ports($node->id);
			$rows  = $samples->keyed($node->id);

			if ($ports === null || $rows === null) {
				return null;
			}

			foreach ($ports as $number => $port) {
				$basic    = isset($rows[$number . ':' . CounterGroup::Basic->value]) ? Sample::fromRow($rows[$number . ':' . CounterGroup::Basic->value]) : null;
				$extended = isset($rows[$number . ':' . CounterGroup::Extended->value]) ? Sample::fromRow($rows[$number . ':' . CounterGroup::Extended->value]) : null;
				$out[]    = new PortReading($node, $port, $intervals[$node->fabricId], $basic, $extended, $this->clock);
			}
		}

		return $out;
	}
}
