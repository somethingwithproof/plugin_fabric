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
 * Computes structured, maintenance-aware health reports for the CLI, Cacti
 * Doctor checks, and MCP read-only abilities. Never executes sweeps or
 * contacts a fabric or UFM endpoint.
 */
final class HealthEvaluator {
	public function __construct(
		private readonly FabricRepository $fabrics,
		private readonly NodeRepository $nodes,
		private readonly FabricHealthRepository $health,
		private readonly NodeSweepRepository $sweeps,
		private readonly RelinkRepository $relinks,
		private readonly SampleRepository $samples,
		private readonly Clock $clock,
		private readonly DeviceMaintenance $maintenance,
		private readonly Settings $settings,
		private readonly Schema $schema,
	) {
	}

	/**
	 * @return array{
	 *   status: string,
	 *   schema_current: bool,
	 *   schema_version: int,
	 *   checked_at: int,
	 *   summary: array{fabrics: int, enabled_fabrics: int, nodes: int, nodes_in_maintenance: int, conflicts: int, open_relinks: int, degraded_ports: int},
	 *   fabrics: list<array<string, mixed>>,
	 *   warnings: list<string>
	 * }
	 */
	public function evaluate(): array {
		$now           = $this->clock->now();
		$schemaCurrent = $this->schema->isCurrent();
		$schemaVersion = $this->schema->storedVersion();
		$fabrics       = $this->fabrics->all() ?? [];
		$nodes         = $this->nodes->all() ?? [];
		$sweeps        = $this->sweeps->all() ?? [];
		$relinks       = $this->relinks->open() ?? [];
		$freshnessEval = new FreshnessEvaluator($this->clock);
		$linkEval      = new LinkStateEvaluator();

		$warnings           = [];
		$fabricRows         = [];
		$enabledCount       = 0;
		$maintenanceNodes   = 0;
		$conflictCount      = 0;
		$degradedPorts      = 0;
		$hasFail            = !$schemaCurrent;
		$hasWarn            = false;

		if (!$schemaCurrent) {
			$warnings[] = sprintf('Plugin schema is at version %d (expected %d).', $schemaVersion, Schema::VERSION);
		}

		$nodesByFabric = [];

		foreach ($nodes as $node) {
			$nodesByFabric[$node->fabricId][] = $node;

			if ($node->identity === IdentityState::Conflict) {
				$conflictCount++;
			}

			if ($node->hostId > 0 && $this->maintenance->inMaintenance($node->hostId)) {
				$maintenanceNodes++;
			}
		}

		foreach ($fabrics as $fabric) {
			if ($fabric->enabled()) {
				$enabledCount++;
			}

			$row     = $this->health->find($fabric->id) ?? [];
			$success = Row::int($row, 'discovery_success');
			$fresh   = $fabric->enabled() ? $freshnessEval->evaluate($success, $fabric->interval) : Freshness::Current;
			$discErr = Row::string($row, 'discovery_error');
			$linkErr = Row::string($row, 'links_error');

			$fNodes       = $nodesByFabric[$fabric->id] ?? [];
			$fMaint       = 0;
			$fFailed      = 0;
			$fDegraded    = 0;

			foreach ($fNodes as $node) {
				$inMaint = $node->hostId > 0 && $this->maintenance->inMaintenance($node->hostId);

				if ($inMaint) {
					$fMaint++;
				}

				$sweepRow = $sweeps[$node->id] ?? null;
				$outcome  = $sweepRow !== null ? SweepOutcome::tryFrom(Row::string($sweepRow, 'outcome')) : null;

				if (!$inMaint && ($outcome === SweepOutcome::Failed || $outcome === SweepOutcome::Partial)) {
					$fFailed++;
				}

				foreach ($this->nodes->ports($node->id) ?? [] as $port) {
					$cond = $linkEval->evaluate($port->link);

					if (!$inMaint && ($cond === LinkCondition::Degraded || $cond === LinkCondition::BelowBest)) {
						$fDegraded++;
						$degradedPorts++;
					}
				}
			}

			$fabricStatus = 'pass';

			if ($fabric->enabled()) {
				if ($discErr !== '' || ($success === 0 && Row::int($row, 'discovery_attempt') > 0)) {
					$fabricStatus = 'fail';
					$hasFail      = true;
					$warnings[]   = sprintf('Fabric %s: discovery error (%s).', $fabric->name, $discErr !== '' ? $discErr : 'never succeeded');
				} elseif (!$fresh->usable()) {
					$fabricStatus = 'warn';
					$hasWarn      = true;
					$warnings[]   = sprintf('Fabric %s: inventory is %s.', $fabric->name, $fresh->value);
				}

				if ($linkErr !== '') {
					$fabricStatus = $fabricStatus === 'fail' ? 'fail' : 'warn';
					$hasWarn      = true;
					$warnings[]   = sprintf('Fabric %s: link state error (%s).', $fabric->name, $linkErr);
				}

				if ($fFailed > 0 || $fDegraded > 0) {
					$fabricStatus = $fabricStatus === 'fail' ? 'fail' : 'warn';
					$hasWarn      = true;
				}
			}

			$fabricRows[] = [
				'id'                   => $fabric->id,
				'name'                 => $fabric->name,
				'backend'              => $fabric->backend->value,
				'poller_id'            => $fabric->pollerId,
				'admin_state'          => $fabric->adminState->value,
				'status'               => $fabricStatus,
				'freshness'            => $fresh->value,
				'discovery_attempt'    => Row::int($row, 'discovery_attempt'),
				'discovery_success'    => $success,
				'discovery_error'      => $discErr,
				'links_error'          => $linkErr,
				'nodes_total'          => count($fNodes),
				'nodes_in_maintenance' => $fMaint,
				'nodes_sweep_failed'   => $fFailed,
				'degraded_ports'       => $fDegraded,
			];
		}

		if ($conflictCount > 0 || count($relinks) > 0) {
			$hasWarn    = true;
			$warnings[] = sprintf('%d identity conflicts and %d open re-link proposals require review.', $conflictCount, count($relinks));
		}

		if ($this->hasUfmFabric($fabrics)) {
			$vaultStatus = $this->checkVaultKey();

			if ($vaultStatus !== null) {
				$hasWarn    = true;
				$warnings[] = 'UFM vault key: ' . $vaultStatus;
			}
		}

		return [
			'status'         => $hasFail ? 'fail' : ($hasWarn ? 'warn' : 'pass'),
			'schema_current' => $schemaCurrent,
			'schema_version' => $schemaVersion,
			'checked_at'     => $now,
			'summary'        => [
				'fabrics'              => count($fabrics),
				'enabled_fabrics'      => $enabledCount,
				'nodes'                => count($nodes),
				'nodes_in_maintenance' => $maintenanceNodes,
				'conflicts'            => $conflictCount,
				'open_relinks'         => count($relinks),
				'degraded_ports'       => $degradedPorts,
			],
			'fabrics'  => $fabricRows,
			'warnings' => $warnings,
		];
	}

	/**
	 * List ports with link degradation, counter sweep errors, or counter
	 * discontinuities, filtered by optional fabric ID and device visibility.
	 *
	 * @param \Closure(int): bool $hostAllowed
	 *
	 * @return list<array{
	 *   fabricId: int,
	 *   fabricName: string,
	 *   nodeGuid: string,
	 *   nodeDescription: string,
	 *   portNumber: int,
	 *   hostId: int,
	 *   inMaintenance: bool,
	 *   linkCondition: string,
	 *   sampleError: string,
	 *   discontinuities: int
	 * }>
	 */
	public function portErrors(?int $fabricId, int $limit, \Closure $hostAllowed, bool $includeUnmapped): array {
		$limit    = max(1, min(100, $limit));
		$fabrics  = $this->fabrics->all() ?? [];
		$linkEval = new LinkStateEvaluator();
		$names    = [];

		foreach ($fabrics as $fabric) {
			$names[$fabric->id] = $fabric->name;
		}

		$nodes = $fabricId !== null ? ($this->nodes->forFabric($fabricId) ?? []) : ($this->nodes->all() ?? []);
		$out   = [];

		foreach ($nodes as $node) {
			if ($node->hostId > 0) {
				if (!$hostAllowed($node->hostId)) {
					continue;
				}
			} elseif (!$includeUnmapped) {
				continue;
			}

			$inMaint = $node->hostId > 0 && $this->maintenance->inMaintenance($node->hostId);
			$ports   = $this->nodes->ports($node->id) ?? [];
			$samples = $this->samples->keyed($node->id) ?? [];

			foreach ($ports as $port) {
				$cond   = $linkEval->evaluate($port->link);
				$basic  = $samples[$port->number . ':' . CounterGroup::Basic->value] ?? [];
				$ext    = $samples[$port->number . ':' . CounterGroup::Extended->value] ?? [];
				$err    = Row::string($basic, 'error') !== '' ? Row::string($basic, 'error') : Row::string($ext, 'error');
				$discon = Row::int($basic, 'discontinuities') + Row::int($ext, 'discontinuities');

				$isIssue = $cond === LinkCondition::Degraded || $cond === LinkCondition::BelowBest || $err !== '' || $discon > 0;

				if (!$isIssue) {
					continue;
				}

				$out[] = [
					'fabricId'        => $node->fabricId,
					'fabricName'      => $names[$node->fabricId] ?? '',
					'nodeGuid'        => $node->guid->text,
					'nodeDescription' => $node->description,
					'portNumber'      => $port->number,
					'hostId'          => $node->hostId,
					'inMaintenance'   => $inMaint,
					'linkCondition'   => $cond->value,
					'sampleError'     => $err,
					'discontinuities' => $discon,
				];

				if (count($out) >= $limit) {
					return $out;
				}
			}
		}

		return $out;
	}

	/**
	 * @param list<Fabric> $fabrics
	 */
	public function hasUfmFabric(array $fabrics): bool {
		foreach ($fabrics as $fabric) {
			if ($fabric->backend === Backend::Ufm) {
				return true;
			}
		}

		return false;
	}

	public function checkVaultKey(): ?string {
		if ($this->settings->vaultKeyFile === '') {
			return 'vault key file setting is empty';
		}

		try {
			VaultKey::fromFile($this->settings->vaultKeyFile);

			return null;
		} catch (CredentialError $e) {
			return $e->getMessage();
		}
	}
}
