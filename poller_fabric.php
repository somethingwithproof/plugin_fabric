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
 * Background poller runner for one InfiniBand fabric (or all due fabrics on
 * this poller), launched by fabric_poller_bottom() or directly from CLI:
 *
 *   php plugins/fabric/poller_fabric.php [--fabric=ID] [--shard=K/N] [--force] [--debug]
 *
 * Lease-locked through ClaimRepository and Cacti's process registry, scoped
 * to this poller_id, with deadline/budget controls. Must parse on PHP 8.0
 * and refuses runtimes below PHP 8.2.
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}

chdir(__DIR__ . '/../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/plugins/fabric/setup.php');

ini_set('max_execution_time', '0');

$fabric_id     = 0;
$fabric_force  = false;
$fabric_shard  = 0;
$fabric_shards = 1;
$fabric_usage  = 'usage: poller_fabric.php [--fabric=ID] [--shard=K/N] [--force] [--debug]';

foreach (array_slice($_SERVER['argv'], 1) as $fabric_arg) {
	if (strpos($fabric_arg, '--fabric=') === 0) {
		$fabric_id = (int) substr($fabric_arg, 9);
	} elseif (strpos($fabric_arg, '--shard=') === 0) {
		$parts = explode('/', substr($fabric_arg, 8), 2);

		if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || (int) $parts[1] < 1 || (int) $parts[0] >= (int) $parts[1]) {
			fwrite(STDERR, 'ERROR: --shard must be K/N with 0 <= K < N' . PHP_EOL . $fabric_usage . PHP_EOL);
			exit(1);
		}

		$fabric_shard  = (int) $parts[0];
		$fabric_shards = (int) $parts[1];
	} elseif ($fabric_arg === '--force') {
		$fabric_force = true;
	} elseif ($fabric_arg === '--debug') {
		$debug = true;
	} else {
		fwrite(STDERR, 'ERROR: Unknown argument' . PHP_EOL . $fabric_usage . PHP_EOL);
		exit(1);
	}
}

if (fabric_refuse_unsupported()) {
	exit(1);
}

fabric_boot();

if (!$fabric_force && !api_plugin_is_enabled('fabric')) {
	exit(0);
}

if (!(new \Somethingwithproof\Fabric\Schema(false))->isCurrent()) {
	cacti_log('ERROR: The fabric poller did not run because the plugin schema is not current; open the Plugins page to finish the upgrade', false, 'FABRIC');
	exit(1);
}

$fabric_poller = (int) ($config['poller_id'] ?? 1);
$fabric_clock  = new \Somethingwithproof\Fabric\SystemClock();
$fabric_repo   = new \Somethingwithproof\Fabric\FabricRepository(false, $fabric_clock);

if ($fabric_id > 0) {
	$fabric_ids = array($fabric_id);
} else {
	$fabric_ids = $fabric_repo->dueForPoller($fabric_poller);
}

foreach ($fabric_ids as $target_id) {
	$record = $fabric_repo->find($target_id);

	if ($record === null || !$record->enabled() || $record->pollerId !== $fabric_poller) {
		continue;
	}

	$budget = max(30, intdiv($record->interval, 2));
	$slot   = ($target_id * 1000) + $fabric_shard;

	if (!register_process_start('fabric', 'poller', $slot, $budget + 120)) {
		cacti_log(sprintf('NOTE: A poller runner for fabric %d shard %d/%d is already running', $target_id, $fabric_shard, $fabric_shards), false, 'FABRIC', POLLER_VERBOSITY_MEDIUM);

		continue;
	}

	$started = microtime(true);

	try {
		$swept = fabric_sweep(null, $started + $budget)->forFabric($target_id, null, $fabric_force, $fabric_shard, $fabric_shards);

		if ($swept !== null) {
			cacti_log(sprintf('STATS: Fabric:%d Poller:%d Shard:%d/%d Nodes:%d Time:%.2f', $target_id, $fabric_poller, $fabric_shard, $fabric_shards, $swept, microtime(true) - $started), false, 'FABRIC', POLLER_VERBOSITY_MEDIUM);
		}
	} catch (\Throwable $e) {
		cacti_log(sprintf('ERROR: Poller sweep for fabric %d failed: %s', $target_id, \Somethingwithproof\Fabric\Log::excerpt($e->getMessage())), false, 'FABRIC');
	} finally {
		unregister_process('fabric', 'poller', $slot);
	}
}

exit(0);
