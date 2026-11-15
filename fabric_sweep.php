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
 * Manual sweep of one fabric, run on the poller the fabric is assigned to:
 *
 *   php plugins/fabric/fabric_sweep.php --fabric=ID [--node=GUID] [--force]
 *
 * Sweeps start lazily from the script server in normal operation (ADR
 * 0008); this command is for operators and tests. It sweeps only a fabric
 * assigned to this poller, only while the plugin and the fabric are
 * enabled, and with --force only ignores whether the fabric is due. Claims
 * still apply, so it never runs beside another sweep of the same fabric or
 * node. This file must parse on PHP 8.0 and refuses to run below 8.2.
 */

chdir(__DIR__ . '/../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/fabric/setup.php');

ini_set('max_execution_time', '0');

$fabric_id    = 0;
$fabric_guid  = '';
$fabric_force = false;
$fabric_usage = 'usage: fabric_sweep.php --fabric=ID [--node=GUID] [--force]';

foreach (array_slice($_SERVER['argv'], 1) as $fabric_arg) {
	if (strpos($fabric_arg, '--fabric=') === 0) {
		$fabric_id = (int) substr($fabric_arg, 9);
	} elseif (strpos($fabric_arg, '--node=') === 0) {
		$fabric_guid = substr($fabric_arg, 7);
	} elseif ($fabric_arg === '--force') {
		$fabric_force = true;
	} else {
		fwrite(STDERR, 'ERROR: Unknown argument' . PHP_EOL . $fabric_usage . PHP_EOL);
		exit(1);
	}
}

if (fabric_refuse_unsupported()) {
	exit(1);
}

fabric_boot();

if ($fabric_id < 1) {
	fwrite(STDERR, $fabric_usage . PHP_EOL);
	exit(1);
}

$fabric_node = null;

if ($fabric_guid !== '') {
	$fabric_node = \Somethingwithproof\Fabric\Guid::tryFromString($fabric_guid);

	if ($fabric_node === null) {
		fwrite(STDERR, 'ERROR: --node must be a node GUID' . PHP_EOL);
		exit(1);
	}
}

if (!(new \Somethingwithproof\Fabric\Schema(false))->isCurrent()) {
	cacti_log('ERROR: The fabric sweep did not run because the plugin schema is not current; open the Plugins page to finish the upgrade', false, 'FABRIC');
	exit(1);
}

$fabric_swept = fabric_sweep()->forFabric($fabric_id, $fabric_node, $fabric_force);

if ($fabric_swept === null) {
	fwrite(STDERR, 'ERROR: The fabric does not exist, is disabled, is assigned to another poller, or the plugin is disabled' . PHP_EOL);
	exit(1);
}

print 'Swept counters of ' . $fabric_swept . ' nodes' . PHP_EOL;
