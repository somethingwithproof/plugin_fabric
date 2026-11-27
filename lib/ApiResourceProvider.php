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
	 * Build read-only Google AIP resource descriptors for Fabric entities.
	 *
	 * Vault entries (plugin_fabric_secret) and external keyfile paths are
	 * deliberately excluded so secret material can never be queried through
	 * resource endpoints.
	 *
	 * @return array<string, array{
	 *   type: string,
	 *   pattern: string,
	 *   singular: string,
	 *   plural: string,
	 *   plugin: string,
	 *   realm: string,
	 *   read_only: bool,
	 *   methods: list<string>,
	 *   fields: list<string>
	 * }>
	 */
	public function resources(): array {
		return [
			'fabric.fabrics' => [
				'type'      => 'fabric.cacti.net/Fabric',
				'pattern'   => 'fabrics/{fabric}',
				'singular'  => 'fabric',
				'plural'    => 'fabrics',
				'plugin'    => 'fabric',
				'realm'     => 'fabric.php',
				'read_only' => true,
				'methods'   => ['get', 'list'],
				'fields'    => ['name', 'backend', 'poller_id', 'sweep_interval', 'admin_state', 'status', 'freshness'],
			],
			'fabric.nodes' => [
				'type'      => 'fabric.cacti.net/Node',
				'pattern'   => 'fabrics/{fabric}/nodes/{node}',
				'singular'  => 'node',
				'plural'    => 'nodes',
				'plugin'    => 'fabric',
				'realm'     => 'fabric.php',
				'read_only' => true,
				'methods'   => ['get', 'list'],
				'fields'    => ['name', 'guid', 'node_type', 'port_count', 'description', 'presence', 'identity', 'host_id'],
			],
			'fabric.ports' => [
				'type'      => 'fabric.cacti.net/Port',
				'pattern'   => 'fabrics/{fabric}/nodes/{node}/ports/{port}',
				'singular'  => 'port',
				'plural'    => 'ports',
				'plugin'    => 'fabric',
				'realm'     => 'fabric.php',
				'read_only' => true,
				'methods'   => ['get', 'list'],
				'fields'    => ['name', 'port_number', 'logical_state', 'phys_state', 'active_width', 'active_speed', 'link_condition'],
			],
		];
	}
}
