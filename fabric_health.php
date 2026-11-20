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

fabric_require_post('fabric_health.php', array('reinstall_templates'));

// Reinstalling imports templates into Cacti, so it needs Cacti's own
// template import permission as well as Fabric Admin.
$fabric_import    = (bool) api_user_realm_auth('templates_import.php');
$fabric_templates = fabric_templates();

if (get_nfilter_request_var('action') === 'reinstall_templates') {
	$fabric_ok = $fabric_import && $fabric_templates->install();

	raise_message('fabric_result', $fabric_ok ? __('The Fabric templates and data query file were installed.', 'fabric') : __('The Fabric templates were not installed. See the Cacti Objects table and the Cacti log.', 'fabric'), $fabric_ok ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_ERROR);
	header('Location: fabric_health.php');
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
	}),
	$fabric_templates,
	$fabric_import,
	(string) $config['base_path']
);

top_header();
$fabric_page->render();
bottom_footer();
