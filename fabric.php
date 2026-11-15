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
 * Fabric inventory (Fabric View realm). This file must parse on PHP 8.0:
 * Cacti may include it on an unsupported runtime, and fabric_page_start()
 * refuses there before any plugin class loads. It shows stored inventory
 * only and never runs a tool (REQ-SWEEP-005).
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/fabric/setup.php');

if (!fabric_page_start()) {
	exit;
}

$fabric_clock = new \Somethingwithproof\Fabric\SystemClock();
$fabric_page  = new \Somethingwithproof\Fabric\InventoryPage(
	new \Somethingwithproof\Fabric\FabricRepository(false, $fabric_clock),
	new \Somethingwithproof\Fabric\NodeRepository(false),
	new \Somethingwithproof\Fabric\SampleRepository(false),
	$fabric_clock,
	\Closure::fromCallable('fabric_device_allowed'),
	fabric_is_admin(),
	new \Somethingwithproof\Fabric\PortReadingRepository(false, $fabric_clock),
	new \Somethingwithproof\Fabric\CactiObjectRepository(false),
	\Closure::fromCallable('fabric_graph_allowed')
);

$fabric_node = (int) get_filter_request_var('node');

top_header();

if ($fabric_node > 0) {
	$fabric_page->renderNode($fabric_node);
} elseif (get_nfilter_request_var('view') === 'congestion') {
	$fabric_page->renderCongestion();
} else {
	$fabric_page->renderList();
}

bottom_footer();
