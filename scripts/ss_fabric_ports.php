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
 * Script server data query for the ports of a fabric node's device
 * (resource/script_server/fabric_ports.xml). Cacti runs this file as a PHP
 * CLI process for the index and query steps of a reindex, which read the
 * cache only, and calls the function through the script server for each
 * polled value, where the first call for a device starts its due sweep
 * (ADR 0008).
 *
 * This file must parse on PHP 8.0: the script server includes it on
 * whatever PHP the site runs, and fabric_boot() refuses before any plugin
 * class loads, answering U.
 */

if (!isset($called_by_script_server)) {
	include_once(__DIR__ . '/../../../include/cli_check.php');

	$fabric_args = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? array_values(array_filter(array_slice($_SERVER['argv'], 1), 'is_string')) : array();

	// A reindex never sweeps.
	if (!in_array($fabric_args[1] ?? '', array('index', 'num_indexes', 'query'), true)) {
		exit(1);
	}

	print ss_fabric_ports(...$fabric_args);
}

/**
 * @param int|string $host_id the Cacti device the nodes are mapped to
 * @param string     $cmd     index, num_indexes, query, or get
 * @param string     $arg1    the field for query and get
 * @param string     $arg2    the index for get
 */
function ss_fabric_ports($host_id = 0, $cmd = 'index', $arg1 = '', $arg2 = ''): string {
	global $called_by_script_server;

	static $query = null;

	include_once(dirname(__DIR__) . '/setup.php');

	if (!fabric_boot()) {
		return $cmd === 'get' ? 'U' : '';
	}

	if ($query === null) {
		$query = fabric_port_query(!empty($called_by_script_server));
	}

	return $query->answer((int) $host_id, (string) $cmd, (string) $arg1, (string) $arg2);
}
