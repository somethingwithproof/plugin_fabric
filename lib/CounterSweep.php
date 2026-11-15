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
 * One counter sweep of one node (REQ-SWEEP-002 to REQ-SWEEP-007).
 *
 * A switch is queried at its LID with comma-separated port lists of up to
 * CommandBuilder::MAX_PORTS ports; an adapter or router port at its own
 * LID, one port per call. The basic counters are read first; the extended
 * counters only for ports whose CapMask says they have them.
 *
 * perfquery stops at the first port that does not answer and exits
 * non-zero after printing the ports before it. That port is failed, and
 * the ports after it are asked for in the next call, so each port is
 * queried at most once per group and sweep. The sweep stops when the node
 * time budget or failure budget is spent; ports not reached are skipped and
 * read in a later cycle. Every port's result is recorded, and a failure is
 * never stored as a zero.
 */
final class CounterSweep {
	public function __construct(
		private readonly \PDO|false $connection,
		private readonly Clock $clock,
		private readonly ProcessRunner $runner,
		private readonly Settings $settings,
	) {
	}

	public function due(Fabric $fabric, Node $node): ?bool {
		$last = (new NodeSweepRepository($this->connection))->lastAttempt($node->id);

		return $last === null ? null : $last <= $this->clock->now() - intdiv($fabric->interval, 2);
	}

	/**
	 * Sweep under the node's claim. Null when another sweep holds it, or
	 * with $ifDue when another sweep finished the node between the caller's
	 * due check and the claim. The claim covers the node budget, the last
	 * command started inside it, and the writes after it.
	 */
	public function run(Fabric $fabric, Node $node, string $holder, bool $ifDue = false): ?NodeSweepResult {
		$claims = new ClaimRepository($this->connection, $this->clock);

		if (!$claims->acquire(ClaimRepository::NODE, $node->id, $holder, $this->settings->nodeBudget + $this->settings->commandTimeout + 30)) {
			return null;
		}

		// A deleted fabric takes its nodes with it.
		if ((new NodeRepository($this->connection))->find($node->id) === null || ($ifDue && $this->due($fabric, $node) !== true)) {
			$claims->release(ClaimRepository::NODE, $node->id, $holder);

			return null;
		}

		$sweeps = new NodeSweepRepository($this->connection);
		$start  = microtime(true);
		$result = new NodeSweepResult();

		try {
			if (!$sweeps->start($node->id, $this->clock->now())) {
				$result->error = CommandError::Storage;

				return $result;
			}

			$this->sweep($fabric, $node, $result, $start);
			$result->duration = microtime(true) - $start;
			$sweeps->finish($node->id, $result, $this->clock->now());
		} finally {
			$claims->release(ClaimRepository::NODE, $node->id, $holder);
		}

		return $result;
	}

	private function sweep(Fabric $fabric, Node $node, NodeSweepResult $result, float $start): void {
		$ports = (new NodeRepository($this->connection))->ports($node->id);

		if ($ports === null) {
			$result->error = CommandError::Storage;

			return;
		}

		// port number => LID to query it at
		$targets = [];

		foreach ($ports as $port) {
			if ($port->presence === Presence::Present && $port->number >= 1) {
				$targets[$port->number] = $node->type === NodeType::Switch ? $node->lid : $port->lid;
			}
		}

		$result->total = count($targets);

		if ($targets === []) {
			return;
		}

		$builder = new CommandBuilder($this->settings, $fabric);
		$budget  = new SweepBudget($start + $this->settings->nodeBudget, $this->settings->failureBudget);
		$samples = new SampleRepository($this->connection);

		try {
			$basic = $this->group($builder, $fabric, $node, $targets, CounterGroup::Basic, $budget, $samples);
		} catch (\InvalidArgumentException $e) {
			// Only the settings or the fabric record can make the first
			// command invalid; nothing was run.
			Log::error(sprintf('Fabric %d: node %s not swept: %s', $fabric->id, $node->guid->text, $e->getMessage()));
			$result->error   = CommandError::Config;
			$result->skipped = $result->total;

			return;
		}

		$extended = [];

		foreach ($basic['read'] as $port => $counters) {
			if ($counters->extendedSupported()) {
				$extended[$port] = $targets[$port];
			}
		}

		$result->read    = count($basic['read']);
		$result->failed  = count($basic['failed']);
		$result->skipped = $result->total - $result->read - $result->failed;
		$result->error   = $basic['error'];

		if ($extended !== []) {
			$this->group($builder, $fabric, $node, $extended, CounterGroup::Extended, $budget, $samples);
		}
	}

	/**
	 * Read one counter group for the given ports and record every result.
	 *
	 * @param array<int, int> $targets port number => LID
	 *
	 * @return array{read: array<int, Counters>, failed: array<int, CommandError>, error: ?CommandError}
	 */
	private function group(CommandBuilder $builder, Fabric $fabric, Node $node, array $targets, CounterGroup $group, SweepBudget $budget, SampleRepository $samples): array {
		$tools   = new ToolRunner($this->runner, $this->settings);
		$parser  = new PerfqueryParser();
		$read    = [];
		$failed  = [];
		$last    = null;
		$pending = array_keys($targets);

		while ($pending !== [] && !$budget->spent()) {
			$lid   = $targets[$pending[0]];
			$batch = $node->type === NodeType::Switch ? array_slice($pending, 0, CommandBuilder::MAX_PORTS) : [$pending[0]];

			if ($lid < 1 || $lid > CommandBuilder::MAX_LID) {
				// A port without a unicast LID cannot be addressed.
				foreach ($batch as $port) {
					$failed[$port] = CommandError::Config;
				}

				$last    = CommandError::Config;
				$pending = array_values(array_diff($pending, $batch));
				$budget->fail();

				continue;
			}

			$output = $tools->run($builder->counters($lid, $batch, $group === CounterGroup::Extended));
			$error  = $output->error();
			$got    = [];

			if ($error === null || $error === CommandError::ExitStatus) {
				try {
					foreach ($parser->parse($output->stdout) as $counters) {
						if ($counters->group !== $group || !in_array($counters->port, $batch, true) || isset($got[$counters->port])) {
							throw ParseFailure::at(0, 'a block for a port that was not asked for');
						}

						$got[$counters->port] = $counters;
					}
				} catch (ParseFailure $e) {
					Log::warning(sprintf('Fabric %d: node %s perfquery output not used: %s', $fabric->id, $node->guid->text, $e->getMessage()));
					$got   = [];
					$error = CommandError::Parse;
				}
			}

			$now = $this->clock->now();

			foreach ($got as $port => $counters) {
				$read[$port] = $counters;
				$samples->record($node->id, $counters, $fabric->backend, $now);
			}

			$tried = $batch;

			if ($error === CommandError::ExitStatus) {
				// perfquery printed the ports before the one that failed.
				// That one failed; the rest were never asked.
				$tried = [];

				foreach ($batch as $port) {
					$tried[] = $port;

					if (!isset($got[$port])) {
						break;
					}
				}
			}

			foreach ($tried as $port) {
				if (!isset($got[$port])) {
					$failed[$port] = $error ?? CommandError::Parse;
					$last          = $failed[$port];
					$samples->recordFailure($node->id, $port, $group, $failed[$port], $now);
					$budget->fail();
				}
			}

			$pending = array_values(array_diff($pending, $tried));
		}

		if ($pending !== []) {
			$last ??= CommandError::Budget;
		}

		return ['read' => $read, 'failed' => $failed, 'error' => $last];
	}
}
