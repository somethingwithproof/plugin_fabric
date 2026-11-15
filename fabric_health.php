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

/*
 * Fabric Health (Fabric Admin realm, REQ-HEALTH-004). Parses on PHP 8.0;
 * fabric_page_start() refuses an unsupported runtime before any plugin
 * class loads. Reads recorded results only and never runs a tool.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/fabric/setup.php');

if (!fabric_page_start()) {
	exit;
}

$fabric_clock = new \Somethingwithproof\Fabric\SystemClock();
$fabric_hosts = new \Somethingwithproof\Fabric\HostRepository(false);
$fabric_page  = new \Somethingwithproof\Fabric\HealthPage(
	new \Somethingwithproof\Fabric\FabricRepository(false, $fabric_clock),
	new \Somethingwithproof\Fabric\NodeRepository(false),
	new \Somethingwithproof\Fabric\FabricHealthRepository(false),
	new \Somethingwithproof\Fabric\NodeSweepRepository(false),
	new \Somethingwithproof\Fabric\RelinkRepository(false, $fabric_clock),
	$fabric_clock,
	new \Somethingwithproof\Fabric\DeviceAccess(static function (int $host) use ($fabric_hosts): bool {
		// As on the admin page: a deleted device protects nothing.
		return fabric_device_allowed($host) || $fabric_hosts->gone($host);
	})
);

top_header();
$fabric_page->render();
bottom_footer();
