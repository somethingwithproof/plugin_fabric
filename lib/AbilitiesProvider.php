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
 * Registers read-only InfiniBand Fabric abilities for Cacti MCP / Abilities
 * API v2 via the abilities_register hook. Every ability is read-only,
 * non-destructive, idempotent, View-realm gated, and never reveals vault
 * credentials or triggers live sweeps.
 */
final class AbilitiesProvider {
	/** Annotations constant for AbilitiesProvider. */
	private const ANNOTATIONS = [
		'readOnly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	];

	/**
	 * @param \Closure(int): bool      $canView       returns true when user holds Fabric View (or higher) realm
	 * @param \Closure(int, int): bool $deviceAllowed returns true when user may view the given Cacti host ID
	 * @param \Closure(int): bool      $isAdmin       returns true when user holds Fabric Admin realm
	 */
	public function __construct(
		private readonly HealthEvaluator $evaluator,
		private readonly \Closure $canView,
		private readonly \Closure $deviceAllowed,
		private readonly \Closure $isAdmin,
	) {
	}

	/**
	 * Register.
	 *
	 * @param object $registrar
	 * @return void
	 */
	public function register(object $registrar): void {
		if (!method_exists($registrar, 'register')) {
			return;
		}

		if (method_exists($registrar, 'registerCategory')) {
			try {
				$registrar->registerCategory('fabric', 'InfiniBand Fabric');
			} catch (\Throwable) {
				// Category may already be registered by a previous call.
			}
		}

		$permission = fn (int $userId): bool => ($this->canView)($userId);

		$registrar->register('fabric-list-fabrics', [
			'label'         => 'List InfiniBand fabrics',
			'description'   => 'List configured InfiniBand fabrics with backend, poller, freshness, and maintenance-aware node counts. Vault credentials are excluded.',
			'category'      => 'fabric',
			'input_schema'  => [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'fabrics' => [
						'type'     => 'array',
						'maxItems' => 256,
						'items'    => [
							'type'                 => 'object',
							'properties'           => [
								'id'                   => ['type' => 'integer'],
								'name'                 => ['type' => 'string'],
								'backend'              => ['type' => 'string'],
								'pollerId'             => ['type' => 'integer'],
								'adminState'           => ['type' => 'string'],
								'status'               => ['type' => 'string'],
								'freshness'            => ['type' => 'string'],
								'nodesTotal'           => ['type' => 'integer'],
								'nodesInMaintenance'   => ['type' => 'integer'],
								'degradedPorts'        => ['type' => 'integer'],
							],
							'required'             => ['id', 'name', 'backend', 'pollerId', 'adminState', 'status', 'freshness', 'nodesTotal', 'nodesInMaintenance', 'degradedPorts'],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => ['fabrics'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permission,
			'execute'     => function (): array {
				$report = $this->evaluator->evaluate();
				$items  = [];

				foreach (array_slice($report['fabrics'], 0, 256) as $row) {
					$items[] = [
						'id'                 => Row::int($row, 'id'),
						'name'               => Row::string($row, 'name'),
						'backend'            => Row::string($row, 'backend'),
						'pollerId'           => Row::int($row, 'poller_id'),
						'adminState'         => Row::string($row, 'admin_state'),
						'status'             => Row::string($row, 'status'),
						'freshness'          => Row::string($row, 'freshness'),
						'nodesTotal'         => Row::int($row, 'nodes_total'),
						'nodesInMaintenance' => Row::int($row, 'nodes_in_maintenance'),
						'degradedPorts'      => Row::int($row, 'degraded_ports'),
					];
				}

				return ['fabrics' => $items];
			},
		]);

		$registrar->register('fabric-get-health', [
			'label'         => 'Get InfiniBand fabric health',
			'description'   => 'Read overall and per-fabric health status, freshness, maintenance-aware node counts, conflicts, and warnings.',
			'category'      => 'fabric',
			'input_schema'  => [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'status'             => ['type' => 'string', 'enum' => ['pass', 'warn', 'fail']],
					'schemaCurrent'      => ['type' => 'boolean'],
					'fabricsTotal'       => ['type' => 'integer'],
					'enabledFabrics'     => ['type' => 'integer'],
					'nodesTotal'         => ['type' => 'integer'],
					'nodesInMaintenance' => ['type' => 'integer'],
					'conflicts'          => ['type' => 'integer'],
					'openRelinks'        => ['type' => 'integer'],
					'degradedPorts'      => ['type' => 'integer'],
					'warnings'           => [
						'type'     => 'array',
						'maxItems' => 100,
						'items'    => ['type' => 'string'],
					],
				],
				'required'             => ['status', 'schemaCurrent', 'fabricsTotal', 'enabledFabrics', 'nodesTotal', 'nodesInMaintenance', 'conflicts', 'openRelinks', 'degradedPorts', 'warnings'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permission,
			'execute'     => function (): array {
				$report = $this->evaluator->evaluate();

				return [
					'status'             => $report['status'],
					'schemaCurrent'      => $report['schema_current'],
					'fabricsTotal'       => $report['summary']['fabrics'],
					'enabledFabrics'     => $report['summary']['enabled_fabrics'],
					'nodesTotal'         => $report['summary']['nodes'],
					'nodesInMaintenance' => $report['summary']['nodes_in_maintenance'],
					'conflicts'          => $report['summary']['conflicts'],
					'openRelinks'        => $report['summary']['open_relinks'],
					'degradedPorts'      => $report['summary']['degraded_ports'],
					'warnings'           => array_slice($report['warnings'], 0, 100),
				];
			},
		]);

		$registrar->register('fabric-list-port-errors', [
			'label'         => 'List fabric port errors',
			'description'   => 'List InfiniBand ports with link degradation, counter sweep failures, or discontinuities for permitted devices.',
			'category'      => 'fabric',
			'input_schema'  => [
				'type'                 => 'object',
				'properties'           => [
					'fabricId' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647],
					'limit'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
				],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'ports' => [
						'type'     => 'array',
						'maxItems' => 100,
						'items'    => [
							'type'                 => 'object',
							'properties'           => [
								'fabricId'        => ['type' => 'integer'],
								'fabricName'      => ['type' => 'string'],
								'nodeGuid'        => ['type' => 'string'],
								'nodeDescription' => ['type' => 'string'],
								'portNumber'      => ['type' => 'integer'],
								'hostId'          => ['type' => 'integer'],
								'inMaintenance'   => ['type' => 'boolean'],
								'linkCondition'   => ['type' => 'string'],
								'sampleError'     => ['type' => 'string'],
								'discontinuities' => ['type' => 'integer'],
							],
							'required'             => ['fabricId', 'fabricName', 'nodeGuid', 'nodeDescription', 'portNumber', 'hostId', 'inMaintenance', 'linkCondition', 'sampleError', 'discontinuities'],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => ['ports'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permission,
			'execute'     => function (int $userId, \stdClass $args): array {
				$fabricId = isset($args->fabricId) && is_int($args->fabricId) ? $args->fabricId : null;
				$limit    = isset($args->limit) && is_int($args->limit) ? $args->limit : 50;
				$allowed  = fn (int $hostId): bool => ($this->deviceAllowed)($userId, $hostId);
				$isAdmin  = ($this->isAdmin)($userId);

				return [
					'ports' => $this->evaluator->portErrors($fabricId, $limit, $allowed, $isAdmin),
				];
			},
		]);
	}
}
