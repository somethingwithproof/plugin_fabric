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
 * Provides read-only diagnostic checks for Cacti Doctor via the
 * doctor_register_checks hook.
 */
final class DoctorProvider {
	/**
	 * Construct a Cacti Doctor provider backed by a read-only health evaluator.
	 *
	 * @param HealthEvaluator $evaluator read-only fabric health evaluator
	 */
	public function __construct(private readonly HealthEvaluator $evaluator) {
	}

	/**
	 * Build a redacted diagnostic debug-info section for the optional
	 * doctor_register_debug_info hook. Excludes all vault key material,
	 * credentials, and secret file paths.
	 *
	 * @return array{
	 *   label: string,
	 *   description: string,
	 *   private: bool,
	 *   fields: array<string, array{label: string, value: string, private: bool}>
	 * }
	 */
	public function debugInfo(): array {
		$report  = $this->evaluator->evaluate();
		$summary = $report['summary'];
		$ufmUsed = false;

		foreach ($report['fabrics'] as $row) {
			if (Row::string($row, 'backend') === Backend::Ufm->value) {
				$ufmUsed = true;

				break;
			}
		}

		$vaultStatus = !$ufmUsed
			? 'not_required'
			: ($this->evaluator->checkVaultKey() === null ? 'valid' : 'attention_required');

		return [
			'label'       => 'InfiniBand Fabric',
			'description' => 'Read-only health and inventory summary for plugin_fabric. Vault keys and credentials are redacted.',
			'private'     => false,
			'fields'      => [
				'schema_version' => [
					'label'   => 'Schema Version',
					'value'   => sprintf('v%d (%s)', $report['schema_version'], $report['schema_current'] ? 'current' : 'upgrade_required'),
					'private' => false,
				],
				'health_status' => [
					'label'   => 'Health Status',
					'value'   => $report['status'],
					'private' => false,
				],
				'fabrics_configured' => [
					'label'   => 'Configured Fabrics',
					'value'   => sprintf('%d total (%d enabled)', $summary['fabrics'], $summary['enabled_fabrics']),
					'private' => false,
				],
				'nodes_tracked' => [
					'label'   => 'Tracked Nodes',
					'value'   => sprintf('%d total (%d in maintenance)', $summary['nodes'], $summary['nodes_in_maintenance']),
					'private' => false,
				],
				'degraded_ports' => [
					'label'   => 'Degraded Ports',
					'value'   => (string) $summary['degraded_ports'],
					'private' => false,
				],
				'identity_conflicts' => [
					'label'   => 'Identity Conflicts and Open Relinks',
					'value'   => sprintf('%d conflicts, %d relinks', $summary['conflicts'], $summary['open_relinks']),
					'private' => false,
				],
				'ufm_vault_status' => [
					'label'   => 'UFM Vault Key Status',
					'value'   => $vaultStatus,
					'private' => true,
				],
			],
		];
	}

	/**
	 * Build structured diagnostic check results matching Cacti Doctor's check
	 * contract (id, category, status, summary, detail).
	 *
	 * @return list<array{id: string, category: string, status: string, summary: string, detail: string}>
	 */
	public function checks(): array {
		$report  = $this->evaluator->evaluate();
		$summary = $report['summary'];
		$checks  = [];

		$checks[] = [
			'id'       => 'fabric.schema',
			'category' => 'fabric',
			'status'   => $report['schema_current'] ? 'pass' : 'fail',
			'summary'  => $report['schema_current']
				? sprintf('Fabric schema is current (v%d)', $report['schema_version'])
				: sprintf('Fabric schema is at v%d (expected v%d)', $report['schema_version'], Schema::VERSION),
			'detail'   => sprintf('%d fabrics configured (%d enabled), %d nodes tracked.', $summary['fabrics'], $summary['enabled_fabrics'], $summary['nodes']),
		];

		$staleFabrics  = 0;
		$failedFabrics = 0;

		foreach ($report['fabrics'] as $row) {
			if (Row::string($row, 'admin_state') !== AdminState::Enabled->value) {
				continue;
			}

			$status = Row::string($row, 'status');

			if ($status === 'fail') {
				$failedFabrics++;
			} elseif ($status === 'warn') {
				$staleFabrics++;
			}
		}

		$checks[] = [
			'id'       => 'fabric.poller_freshness',
			'category' => 'fabric',
			'status'   => $failedFabrics > 0 ? 'fail' : ($staleFabrics > 0 ? 'warn' : 'pass'),
			'summary'  => $failedFabrics > 0
				? sprintf('%d enabled fabrics have discovery failures', $failedFabrics)
				: ($staleFabrics > 0
					? sprintf('%d enabled fabrics have stale or degraded observations', $staleFabrics)
					: 'All enabled fabrics have current discovery and link state'),
			'detail'   => sprintf('%d degraded ports, %d nodes in Cacti maintenance.', $summary['degraded_ports'], $summary['nodes_in_maintenance']),
		];

		$conflictWarn = $summary['conflicts'] > 0 || $summary['open_relinks'] > 0;
		$checks[]     = [
			'id'       => 'fabric.identity_conflicts',
			'category' => 'fabric',
			'status'   => $conflictWarn ? 'warn' : 'pass',
			'summary'  => $conflictWarn
				? sprintf('%d identity conflicts and %d open re-link proposals', $summary['conflicts'], $summary['open_relinks'])
				: 'No unresolved fabric identity conflicts or re-link proposals',
			'detail'   => 'Review identity conflicts and re-link proposals on Fabric Admin.',
		];

		$ufmUsed = false;

		foreach ($report['fabrics'] as $row) {
			if (Row::string($row, 'backend') === Backend::Ufm->value) {
				$ufmUsed = true;

				break;
			}
		}

		$vaultErr = $ufmUsed ? $this->evaluator->checkVaultKey() : null;
		$checks[] = [
			'id'       => 'fabric.ufm_vault',
			'category' => 'fabric',
			'status'   => $vaultErr !== null ? 'warn' : 'pass',
			'summary'  => !$ufmUsed
				? 'No UFM fabrics configured; vault key not required'
				: ($vaultErr === null ? 'UFM credential vault key is readable and valid' : 'UFM credential vault key problem'),
			'detail'   => $vaultErr ?? 'External vault keyfile verified without disclosing key material.',
		];

		return $checks;
	}
}
