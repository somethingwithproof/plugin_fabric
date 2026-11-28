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
 * Starts sweeps lazily (ADR 0008). The Fabric Port data query calls
 * forDevice() through the script server when the poller asks for a mapped
 * device's first value; fabric_sweep.php calls
 * forFabric(). Either way a sweep runs only on the poller the fabric is
 * assigned to, only while the plugin and the fabric are enabled, and only
 * for what is due. Claims keep concurrent callers from sweeping the same
 * fabric topology or node twice; a caller that loses a claim does not
 * wait, it returns and reads what the winner stores.
 *
 * There is no poller_bottom prefetch.
 *
 * A deadline (microtime(true)) bounds every tool run of this object's
 * sweeps, the command timeout included; the script server passes one below
 * Cacti's script timeout, so a get call returns in time with the last
 * stored values or U (fabric_port_query()).
 */
final class Sweep {
	/** Topology state for Sweep. */
	private readonly TopologySweep $topology;
	/** Counters state for Sweep. */
	private readonly CounterSweep $counters;
	/** Ufm state for Sweep. */
	private readonly UfmSweep $ufm;

	/**
	 * Construct a new Sweep instance.
	 *
	 * @param \PDO|false $connection
	 * @param Clock $clock
	 * @param ProcessRunner $runner
	 * @param Settings $settings
	 * @param int $pollerId
	 * @param bool $pluginEnabled
	 * @param int $toolOwner
	 * @param ?float $deadline
	 * @param ?UfmHttpTransport $ufmTransport
	 * @param ?Vault $ufmVault
	 */
	public function __construct(
		private readonly \PDO|false $connection,
		private readonly Clock $clock,
		ProcessRunner $runner,
		Settings $settings,
		private readonly int $pollerId,
		private readonly bool $pluginEnabled,
		int $toolOwner = 0,
		?float $deadline = null,
		?UfmHttpTransport $ufmTransport = null,
		?Vault $ufmVault = null,
	) {
		// Leave most of a script-server call for counters even when
		// topology discovery runs up to its limit every polling cycle.
		$topologyDeadline = $deadline === null ? null : microtime(true) + max(0.0, $deadline - microtime(true)) / 3;
		$this->topology = new TopologySweep($connection, $clock, $runner, $settings, $toolOwner, $topologyDeadline);
		$this->counters = new CounterSweep($connection, $clock, $runner, $settings, $toolOwner, $deadline);
		$this->ufm      = new UfmSweep($connection, $clock, $settings, $ufmTransport, $ufmVault, $deadline);
	}

	/**
	 * Sweep what is due for the fabric nodes mapped to one Cacti device.
	 *
	 * @return int nodes whose counters were swept
	 */
	public function forDevice(int $hostId): int {
		if (!$this->pluginEnabled || $hostId < 1) {
			return 0;
		}

		$nodes = (new NodeRepository($this->connection))->forHost($hostId);

		if ($nodes === null) {
			return 0;
		}

		$swept = 0;

		foreach ($nodes as $node) {
			$fabric = (new FabricRepository($this->connection, $this->clock))->find($node->fabricId);

			if ($fabric !== null) {
				$swept += $this->sweepNodes($fabric, [$node], false);
			}
		}

		return $swept;
	}

	/**
	 * Sweep a fabric's topology and nodes, or with $guid one node. $force
	 * ignores whether they are due, for an operator's manual run; claims
	 * still apply. Optional sharding ($shardIndex in 0..$shardCount-1) splits
	 * node counter sweeps deterministically by node GUID across workers.
	 *
	 * @return int|null nodes whose counters were swept, or null when the fabric may not be swept here
	 */
	public function forFabric(int $fabricId, ?Guid $guid, bool $force, int $shardIndex = 0, int $shardCount = 1): ?int {
		$fabric = (new FabricRepository($this->connection, $this->clock))->find($fabricId);

		if (!$this->pluginEnabled || $fabric === null || !$this->allowed($fabric)) {
			return null;
		}

		if ($fabric->backend === Backend::Ufm) {
			return $this->ufm->run($fabric, ClaimRepository::holder(), !$force);
		}

		if ($force || $this->topology->due($fabric) === true) {
			$this->topology->run($fabric, ClaimRepository::holder(), !$force);
		}

		$nodes = (new NodeRepository($this->connection))->forFabric($fabric->id);

		if ($nodes === null) {
			return null;
		}

		if ($guid !== null) {
			$nodes = array_values(array_filter($nodes, static fn (Node $n): bool => $n->guid->equals($guid)));
		} elseif ($shardCount > 1 && $shardIndex >= 0 && $shardIndex < $shardCount) {
			$nodes = array_values(array_filter($nodes, static fn (Node $n): bool => ((int) sprintf('%u', crc32($n->guid->text))) % $shardCount === $shardIndex));
		}

		return $this->sweepNodes($fabric, $nodes, $force, false);
	}

	/**
	 * Allowed.
	 *
	 * @param Fabric $fabric
	 * @return bool
	 */
	public function allowed(Fabric $fabric): bool {
		return $fabric->enabled() && $fabric->pollerId === $this->pollerId;
	}

	/**
	 * @param list<Node> $nodes
	 */
	private function sweepNodes(Fabric $fabric, array $nodes, bool $force, bool $topology = true): int {
		if (!$this->allowed($fabric)) {
			return 0;
		}

		if ($topology && $this->topology->due($fabric) === true) {
			$this->topology->run($fabric, ClaimRepository::holder(), true);
		}

		$swept = 0;

		foreach ($nodes as $node) {
			// Read again: the topology sweep may have just marked it
			// missing or conflicted.
			$current = (new NodeRepository($this->connection))->find($node->id);

			if ($current === null || !$current->sweepable() || (!$force && $this->counters->due($fabric, $current) !== true)) {
				continue;
			}

			if ($this->counters->run($fabric, $current, ClaimRepository::holder(), !$force) !== null) {
				$swept++;
			}
		}

		return $swept;
	}
}
