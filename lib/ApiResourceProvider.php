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
 * Provides plain-array Google AIP resource descriptors for the optional
 * api_register_resources hook. Imports no external plugin classes and stays
 * standalone when no API plugin is installed.
 */
final class ApiResourceProvider {
	/**
	 * Construct a read-only API resource provider backed by a HealthEvaluator
	 * and user-scoped permission callbacks.
	 *
	 * @param HealthEvaluator          $evaluator     read-only fabric health evaluator
	 * @param \Closure(int): bool      $canView       returns true when user holds Fabric View realm
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
	 * Build read-only Google AIP resource descriptors for Fabric entities.
	 *
	 * Vault entries (plugin_fabric_secret) and external keyfile paths are
	 * deliberately excluded so secret material can never be queried through
	 * resource endpoints.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function resources(): array {
		return [
			[
				'plugin'        => 'fabric',
				'collection'    => 'fabricFabrics',
				'singleton'     => 'fabricFabric',
				'description'   => 'Read-only configured InfiniBand fabrics with freshness and node counts.',
				'realm_read'    => 'fabric.php',
				'read_only'     => true,
				'schema'        => 'schema/fabric.schema.yaml',
				'filter_fields' => ['id', 'name', 'display_name', 'backend', 'poller_id', 'admin_state', 'status'],
				'order_fields'  => ['id', 'name', 'display_name', 'backend', 'poller_id'],
				'handlers'      => [
					/**
					 * @param array<string, mixed> $context
					 * @return list<array<string, mixed>>
					 */
					'list' => function (array $context = []): array {
						$userId = isset($context['user_id']) && is_int($context['user_id']) ? $context['user_id'] : 0;

						if ($userId < 1 || !($this->canView)($userId)) {
							return [];
						}

						$report = $this->evaluator->evaluate();
						$items  = [];

						foreach ($report['fabrics'] as $row) {
							$items[] = self::serializeFabric($row);
						}

						return $items;
					},
					/**
					 * @param string               $resourceId
					 * @param array<string, mixed> $context
					 * @return array<string, mixed>|null
					 */
					'get' => function (string $resourceId, array $context = []): ?array {
						$userId = isset($context['user_id']) && is_int($context['user_id']) ? $context['user_id'] : 0;

						if ($userId < 1 || !($this->canView)($userId)) {
							return null;
						}

						$rawId = str_starts_with($resourceId, 'fabricFabrics/') ? substr($resourceId, strlen('fabricFabrics/')) : $resourceId;

						if ($rawId === '' || !ctype_digit($rawId)) {
							return null;
						}

						$targetId = (int) $rawId;
						$report   = $this->evaluator->evaluate();

						foreach ($report['fabrics'] as $row) {
							if (Row::int($row, 'id') === $targetId) {
								return self::serializeFabric($row);
							}
						}

						return null;
					},
				],
			],
			[
				'plugin'        => 'fabric',
				'collection'    => 'fabricPorts',
				'singleton'     => 'fabricPort',
				'description'   => 'Read-only InfiniBand ports reporting link degradation or counter errors.',
				'realm_read'    => 'fabric.php',
				'read_only'     => true,
				'schema'        => 'schema/fabric.schema.yaml',
				'filter_fields' => ['id', 'name', 'fabric_id', 'node_guid', 'port_number', 'link_condition'],
				'order_fields'  => ['id', 'name', 'fabric_id', 'port_number'],
				'handlers'      => [
					/**
					 * @param array<string, mixed> $context
					 * @return list<array<string, mixed>>
					 */
					'list' => function (array $context = []): array {
						$userId = isset($context['user_id']) && is_int($context['user_id']) ? $context['user_id'] : 0;

						if ($userId < 1 || !($this->canView)($userId)) {
							return [];
						}

						$query    = isset($context['query']) && is_array($context['query']) ? $context['query'] : [];
						$fabricId = isset($query['fabric_id']) && is_numeric($query['fabric_id']) ? (int) $query['fabric_id'] : null;
						$allowed  = fn (int $hostId): bool => ($this->deviceAllowed)($userId, $hostId);
						$isAdmin  = ($this->isAdmin)($userId);
						$ports    = $this->evaluator->portErrors($fabricId, 100, $allowed, $isAdmin);
						$items    = [];

						foreach ($ports as $port) {
							$items[] = self::serializePort($port);
						}

						return $items;
					},
					/**
					 * @param string               $resourceId
					 * @param array<string, mixed> $context
					 * @return array<string, mixed>|null
					 */
					'get' => function (string $resourceId, array $context = []): ?array {
						$userId = isset($context['user_id']) && is_int($context['user_id']) ? $context['user_id'] : 0;

						if ($userId < 1 || !($this->canView)($userId)) {
							return null;
						}

						$rawId   = str_starts_with($resourceId, 'fabricPorts/') ? substr($resourceId, strlen('fabricPorts/')) : $resourceId;
						$allowed = fn (int $hostId): bool => ($this->deviceAllowed)($userId, $hostId);
						$isAdmin = ($this->isAdmin)($userId);

						foreach ($this->evaluator->portErrors(null, 100, $allowed, $isAdmin) as $port) {
							$item = self::serializePort($port);

							if ($item['id'] === $rawId) {
								return $item;
							}
						}

						return null;
					},
				],
			],
		];
	}

	/**
	 * Serialize a fabric health row into a canonical API resource record.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function serializeFabric(array $row): array {
		$id = Row::int($row, 'id');

		return [
			'name'                 => 'fabricFabrics/' . $id,
			'id'                   => $id,
			'display_name'         => Row::string($row, 'name'),
			'backend'              => Row::string($row, 'backend'),
			'poller_id'            => Row::int($row, 'poller_id'),
			'admin_state'          => Row::string($row, 'admin_state'),
			'status'               => Row::string($row, 'status'),
			'freshness'            => Row::string($row, 'freshness'),
			'nodes_total'          => Row::int($row, 'nodes_total'),
			'nodes_in_maintenance' => Row::int($row, 'nodes_in_maintenance'),
			'degraded_ports'       => Row::int($row, 'degraded_ports'),
		];
	}

	/**
	 * Serialize a port error row into a canonical API resource record.
	 *
	 * @param array{
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
	 * } $port
	 * @return array<string, mixed>
	 */
	private static function serializePort(array $port): array {
		$id = $port['nodeGuid'] . ':' . $port['portNumber'];

		return [
			'name'             => 'fabricPorts/' . $id,
			'id'               => $id,
			'fabric_id'        => $port['fabricId'],
			'fabric_name'      => $port['fabricName'],
			'node_guid'        => $port['nodeGuid'],
			'node_description' => $port['nodeDescription'],
			'port_number'      => $port['portNumber'],
			'host_id'          => $port['hostId'],
			'in_maintenance'   => $port['inMaintenance'],
			'link_condition'   => $port['linkCondition'],
			'sample_error'     => $port['sampleError'],
			'discontinuities'  => $port['discontinuities'],
		];
	}
}
