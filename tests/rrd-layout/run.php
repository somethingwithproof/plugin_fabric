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
 * Compares two RRD file layouts for research issue #25:
 *   php run.php [ports ...]       (default: 100 1000)
 * Layout A: four files per port (traffic 4, errors 12, congestion 4, state 5
 * data sources). Layout B: one file per data source (25 per port). Files use
 * the "5 Minute Collection" profile of Cacti 1.2.31 (step 300, heartbeat 600,
 * AVERAGE, MIN, MAX, LAST over 600, 700, 775, and 797 rows at 1, 6, 24, and
 * 288 steps; cacti.sql lines 1321, 1339 to 1342, and 1371 to 1374). It creates the files in a
 * temporary directory, measures create time, disk size, and the time rrdtool
 * takes to apply one polling cycle of updates through one persistent pipe (the
 * way Cacti updates), and deletes the directory afterwards. It does
 * not run Cacti, so the figures are rrdtool and file system costs on this host,
 * not poll times. Needs rrdtool on PATH.
 */

const STEP = 300;
const HEARTBEAT = 600;
const CF_ROWS = array(1 => 600, 6 => 700, 24 => 775, 288 => 797);
const CFS = array('AVERAGE', 'MIN', 'MAX', 'LAST');
const GROUPS = array(
	'traffic'    => array('COUNTER', array('xmit_data', 'rcv_data', 'xmit_pkts', 'rcv_pkts')),
	'errors'     => array('COUNTER', array('symbol_err', 'link_recov', 'link_down', 'rcv_err', 'rcv_remote_phys', 'rcv_sw_relay', 'xmit_discards', 'xmit_constraint', 'rcv_constraint', 'local_integrity', 'exc_buf_overrun', 'vl15_dropped')),
	'congestion' => array('COUNTER', array('xmit_wait', 'ucast_xmit_pkts', 'ucast_rcv_pkts', 'mcast_rcv_pkts')),
	'state'      => array('GAUGE', array('active_lanes', 'speed_rank', 'degraded', 'port_state', 'age')),
);

function rrd_rra()
{
	$out = '';

	foreach (CFS as $cf) {
		foreach (CF_ROWS as $steps => $rows) {
			$out .= " RRA:$cf:0.5:$steps:$rows";
		}
	}

	return $out;
}

/** @return array<string, array{0: string, 1: array<int, array{0: string, 1: string}>}> files for one port: name => [type, ds list] */
function layout_files($layout)
{
	$files = array();

	if ($layout === 'A') {
		foreach (GROUPS as $group => $def) {
			$ds = array();
			foreach ($def[1] as $name) {
				$ds[] = array($name, $def[0]);
			}
			$files[$group] = $ds;
		}
	} else {
		foreach (GROUPS as $def) {
			foreach ($def[1] as $name) {
				$files[$name] = array(array($name, $def[0]));
			}
		}
	}

	return $files;
}

/** Starts one persistent rrdtool process reading commands on stdin. */
function rrd_open()
{
	$proc = proc_open(array('rrdtool', '-'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

	if (!is_resource($proc)) {
		throw new RuntimeException('cannot start rrdtool');
	}

	return array($proc, $pipes);
}

function rrd_close(array $handle)
{
	list($proc, $pipes) = $handle;

	foreach ($pipes as $pipe) {
		fclose($pipe);
	}

	proc_close($proc);
}

/** Sends commands through an open rrdtool process and returns the elapsed seconds. */
function rrd_batch(array $handle, array $commands)
{
	$pipes = $handle[1];
	$start = microtime(true);

	foreach ($commands as $command) {
		fwrite($pipes[0], $command . "\n");

		$line = fgets($pipes[1]);

		if ($line === false || strpos($line, 'OK') !== 0) {
			throw new RuntimeException("rrdtool failed on: $command " . (string) $line);
		}
	}

	return microtime(true) - $start;
}

function dir_bytes($dir)
{
	$total = 0;

	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
		$total += $file->getSize();
	}

	return $total;
}

/** Deletes the scratch directory this script created (a few GB at 1,000 ports; not worth sending to a trash folder). */
function discard($dir)
{
	$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

	foreach ($items as $item) {
		$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}

	rmdir($dir);
}

function run_layout($layout, $ports)
{
	$base = sys_get_temp_dir() . '/fabric-rrd-' . bin2hex(random_bytes(8)) . "-$layout-$ports";

	if (!mkdir($base, 0700)) {
		throw new RuntimeException("cannot create scratch directory $base");
	}

	$handle = null;

	try {
		$handle = rrd_open();

		return measure_layout($layout, $ports, $base, $handle);
	} finally {
		if ($handle !== null) {
			rrd_close($handle);
		}

		discard($base);
	}
}

function measure_layout($layout, $ports, $base, array $handle)
{
	$files   = layout_files($layout);
	$t0      = 1700000100 - (1700000100 % STEP);
	$create  = array();
	$paths   = array();
	$dsCount = 0;

	for ($p = 0; $p < $ports; $p++) {
		$dir = $base . '/' . intdiv($p, 100);

		if (!is_dir($dir)) {
			mkdir($dir, 0700);
		}

		foreach ($files as $name => $ds) {
			$path    = "$dir/{$p}_$name.rrd";
			$paths[] = array($path, $ds);
			$line    = 'create ' . $path . ' --start ' . ($t0 - STEP) . ' --step ' . STEP;

			foreach ($ds as $def) {
				$line .= ' DS:' . $def[0] . ':' . $def[1] . ':' . HEARTBEAT . ':0:U';
			}

			$create[] = $line . rrd_rra();
			$dsCount += count($ds);
		}
	}

	$createSeconds = rrd_batch($handle, $create);
	$bytes         = dir_bytes($base);

	// One cycle at t0 (first values are unknown rates), then two timed cycles,
	// all through the same rrdtool process.
	$cycleSeconds = array();

	for ($cycle = 0; $cycle < 3; $cycle++) {
		$updates = array();
		$ts      = $t0 + $cycle * STEP;

		foreach ($paths as $entry) {
			$values = array();

			foreach ($entry[1] as $i => $def) {
				$values[] = (($cycle + 1) * 1000 + $i) * 10;
			}

			$updates[] = 'update ' . $entry[0] . ' ' . $ts . ':' . implode(':', $values);
		}

		$cycleSeconds[] = rrd_batch($handle, $updates);
	}

	$timed = array_slice($cycleSeconds, 1);

	return array(
		'layout'         => $layout,
		'ports'          => $ports,
		'files'          => count($paths),
		'data_sources'   => $dsCount,
		'create_s'       => $createSeconds,
		'bytes'          => $bytes,
		'update_cycle_s' => array_sum($timed) / count($timed),
	);
}

if (!trim((string) shell_exec('command -v rrdtool'))) {
	fwrite(STDERR, "rrdtool not found on PATH\n");
	exit(2);
}

$sizes = array_slice($argv, 1);
$sizes = $sizes ? array_map('intval', $sizes) : array(100, 1000);

printf("%-6s %7s %9s %9s %10s %12s %14s %14s\n", 'layout', 'ports', 'files', 'DS', 'create s', 'bytes/port', 'update cycle s', 'updates/s');

foreach ($sizes as $ports) {
	foreach (array('A', 'B') as $layout) {
		try {
			$r = run_layout($layout, $ports);
		} catch (RuntimeException $e) {
			fwrite(STDERR, $e->getMessage() . "\n");
			exit(2);
		}

		printf(
			"%-6s %7d %9d %9d %10.2f %12d %14.3f %14.0f\n",
			$r['layout'],
			$r['ports'],
			$r['files'],
			$r['data_sources'],
			$r['create_s'],
			intdiv($r['bytes'], $r['ports']),
			$r['update_cycle_s'],
			$r['files'] / $r['update_cycle_s']
		);
	}
}
