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

if (PHP_SAPI !== 'cli') {
	exit(1);
}


/*
 * End to end with Cacti's own poller, Thold, and Maint (REQ-ALERT-001 to
 * REQ-ALERT-013, REQ-MAINT-001): fake tools in a root-owned directory, a
 * device created for an adapter node, graphs and data sources from the
 * Fabric templates, and thresholds made with Thold's own functions. The
 * caller runs one phase, then `php poller.php --force`, and so on:
 *
 *   FABRIC_TEST_DATABASE=disposable FABRIC_E2E_TOOLS=<tool dir> php plugins/fabric/tests/bin/e2e.php <phase> <state dir>
 *
 * Phases: tools writes the fake tools to <state dir>/tools, for the caller
 * to install root-owned in <tool dir> (tests/bin/e2e.sh); setup, then
 * degrade, restore, burst, steady, reset, maint, stale, each followed by
 * a poller run and the matching check_<phase>; graph last. Each prints
 * PASS or FAIL lines and exits 1 on a failure. The fake fabric, the tools'
 * call log, and this script's state live in <state dir>. Thold and Maint
 * must be installed and enabled first, unchanged. Disposable databases
 * only. Must parse on PHP 8.0.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

if (getenv('FABRIC_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set FABRIC_TEST_DATABASE=disposable\n");
	exit(2);
}

include_once($config['base_path'] . '/plugins/fabric/setup.php');

if (!fabric_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

require_once($config['base_path'] . '/plugins/fabric/tests/Support/FabricGenerator.php');
require_once($config['base_path'] . '/plugins/fabric/tests/Support/FakeTools.php');
require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/template.php');
require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/api_data_source.php');
require_once($config['base_path'] . '/lib/utility.php');
require_once($config['base_path'] . '/lib/rrd.php');

use Somethingwithproof\Fabric\Tests\FabricGenerator as G;
use Somethingwithproof\Fabric\Tests\FakeTools;
use Somethingwithproof\Fabric\TemplateInstaller as T;

$phase    = isset($argv[1]) ? $argv[1] : '';
$dir      = isset($argv[2]) ? rtrim($argv[2], '/') : '';
$failures = 0;
$file     = $dir . '/e2e.json';
$tools    = (string) getenv('FABRIC_E2E_TOOLS');
$state    = is_file($file) ? json_decode((string) file_get_contents($file), true) : array();
$adapter  = G::CA_BASE;

function e2e_expect($ok, $message) {
	global $failures, $phase;

	print ($ok ? 'PASS ' : 'FAIL ') . $phase . ': ' . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

/** The fabric: one switch, two adapters; the first adapter's port 1 is watched. */
function e2e_fabric(array $state) {
	$gen = G::forCas(2);

	foreach ($state['counters'] ?? array() as $key => $values) {
		$gen->counters[$key] = $values;
	}

	if (!empty($state['degraded'])) {
		$gen->degrade(G::CA_BASE, 1, '1X', 'EDR', '4X', '');
	}

	return $gen;
}

/**
 * Make every sweep due again: Cacti's poller runs here seconds apart, the
 * plugin sweeps at most every half interval.
 */
function e2e_due() {
	db_execute('UPDATE plugin_fabric_node_sweep SET last_attempt = last_attempt - 400');
	db_execute('UPDATE plugin_fabric_health SET discovery_attempt = discovery_attempt - 400');
	db_execute('UPDATE plugin_fabric_claim SET expires_at = 0');
}

function e2e_thold($id) {
	return db_fetch_row_prepared('SELECT id, thold_alert, thold_fail_count, lastread, thold_enabled FROM thold_data WHERE id = ?', array($id));
}

function e2e_logs($id) {
	return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_thold_log WHERE threshold_id = ?', array($id));
}

/** Create a Thold threshold from a Thold template, as Thold's own pages do. */
function e2e_threshold($localGraphId, $localDataId, $dsName, $hi, $low) {
	$rrd = db_fetch_row_prepared('SELECT id, data_template_id FROM data_template_rrd WHERE local_data_id = ? AND data_source_name = ?', array($localDataId, $dsName));

	db_execute_prepared("INSERT INTO thold_template (name, suggested_name, data_template_id, data_template_name, data_source_id, data_source_name, data_source_friendly, thold_type, thold_enabled, thold_hi, thold_low, thold_fail_trigger, repeat_alert, data_type)
		VALUES (?, ?, ?, '', ?, ?, ?, 0, 'on', ?, ?, 1, 0, 0)",
		array('Fabric e2e ' . $dsName, '|data_source_description| ' . $dsName, (int) db_fetch_cell_prepared('SELECT data_template_id FROM data_template_rrd WHERE id = ?', array($rrd['id'])), (int) db_fetch_cell_prepared('SELECT local_data_template_rrd_id FROM data_template_rrd WHERE id = ?', array($rrd['id'])), $dsName, $dsName, $hi, $low));

	$template = db_fetch_row_prepared('SELECT * FROM thold_template WHERE id = ?', array(db_fetch_insert_id()));
	$message  = '';

	thold_create_from_template($localDataId, $localGraphId, $rrd['id'], $template, $message);

	return (int) db_fetch_cell_prepared('SELECT id FROM thold_data WHERE local_data_id = ? AND data_source_name = ?', array($localDataId, $dsName));
}

/** Thold still runs: no TypeError, still enabled. */
function e2e_thold_alive() {
	global $config;

	$log = (string) @file_get_contents($config['base_path'] . '/log/cacti.log');

	e2e_expect(api_plugin_is_enabled('thold') && api_plugin_is_enabled('maint'), 'Thold and Maint are still enabled');
	e2e_expect(preg_match('/TypeError|PHP ERROR|PHP Fatal|Uncaught/', $log) !== 1, 'the Cacti log shows no PHP error');
}

foreach (array('thold/thold_functions.php', 'maint/functions.php') as $include) {
	if (is_file($config['base_path'] . '/plugins/' . $include)) {
		include_once($config['base_path'] . '/plugins/' . $include);
	}
}

switch ($phase) {
	case 'tools':
		if (!is_dir($dir . '/tools')) {
			mkdir($dir . '/tools', 0755);
		}

		FakeTools::install($dir . '/tools', e2e_fabric(array()), array(), $dir);

		break;
	case 'setup':
		e2e_expect(api_plugin_is_enabled('thold') && api_plugin_is_enabled('maint'), 'Thold and Maint are installed and enabled');
		e2e_expect(function_exists('thold_create_from_template') && function_exists('plugin_maint_check_cacti_host'), 'Thold and Maint functions are available');

		// Inline Thold processing, so a poller run checks thresholds.
		set_config_option('thold_daemon_enable', '');
		set_config_option('fabric_tool_dir', $tools);
		$state = array('counters' => array(), 'degraded' => false);
		FakeTools::state($dir, e2e_fabric($state));

		db_execute("INSERT INTO plugin_fabric_fabric (name, backend, poller_id, sweep_interval, adapter, adapter_port, admin_state) VALUES ('e2e', 'diags', 1, 300, 'mlx5_0', 1, 'enabled')");
		$fabricId = (int) db_fetch_insert_id();
		$result   = fabric_sweep()->forFabric($fabricId, null, true);

		e2e_expect($result !== null, 'the first sweep runs against the fake tools');

		$nodes  = new \Somethingwithproof\Fabric\NodeRepository(false);
		$node   = null;

		foreach ($nodes->forFabric($fabricId) ?? array() as $n) {
			if ($n->guid->text === sprintf('0x%016x', $adapter)) {
				$node = $n;
			}
		}

		e2e_expect($node !== null, 'the adapter node is in the inventory');

		$clock   = new \Somethingwithproof\Fabric\SystemClock();
		$access  = new \Somethingwithproof\Fabric\DeviceAccess(static function (int $host): bool {
			return true;
		});
		$devices = new \Somethingwithproof\Fabric\DeviceProvisioner($nodes, new \Somethingwithproof\Fabric\FabricRepository(false, $clock), new \Somethingwithproof\Fabric\CactiObjectRepository(false), $access, $config['base_path']);
		$hostId  = $node !== null ? $devices->create($node) : 0;
		$index   = sprintf('0x%016x', $adapter) . ':1';

		e2e_expect($hostId > 0, 'a device is created for the adapter node');

		$indexes = array_column(db_fetch_assoc_prepared('SELECT DISTINCT snmp_index FROM host_snmp_cache WHERE host_id = ? ORDER BY snmp_index', array($hostId)), 'snmp_index');
		e2e_expect($indexes === array($index), 'the data query lists the linked port only, not the down port 2 (' . implode(', ', $indexes) . ')');

		$queryId = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query WHERE hash = ?', array(T::hash('data_query', T::QUERY)));
		$graphs  = array();

		foreach (array('Fabric Port State', 'Fabric Port Alert Rates', 'Fabric Port Traffic', 'Fabric Port Errors') as $name) {
			$gt   = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE hash = ?', array(T::hash('graph_template', $name)));
			$sqg  = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query_graph WHERE hash = ?', array(T::hash('data_query_graph', T::QUERY . ':' . $name)));
			$vals = array();
			$made = create_complete_graph_from_template($gt, $hostId, array('snmp_query_id' => $queryId, 'snmp_index_on' => 'fabricPort', 'snmp_index' => $index, 'snmp_query_graph_id' => $sqg), $vals);

			// As cli/add_graphs.php does: the poller polls only what is pushed out.
			foreach ($made['local_data_id'] as $localDataId) {
				push_out_host($hostId, $localDataId);
			}

			$graphs[$name] = array('graph' => (int) $made['local_graph_id'], 'data' => (int) reset($made['local_data_id']));
			e2e_expect($graphs[$name]['graph'] > 0 && $graphs[$name]['data'] > 0, "a $name graph and data source are created from the data query");
		}

		$types = array_column(db_fetch_assoc_prepared('SELECT data_source_name, data_source_type_id FROM data_template_rrd WHERE local_data_id IN (?, ?) ORDER BY data_source_name', array($graphs['Fabric Port State']['data'], $graphs['Fabric Port Alert Rates']['data'])), 'data_source_type_id', 'data_source_name');
		e2e_expect(count($types) === 10 && array_values(array_unique(array_map('intval', $types))) === array(1), 'every thresholdable data source is a GAUGE');
		e2e_expect((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM poller_item WHERE host_id = ?', array($hostId)) === 23, 'the 23 data source items of the four graphs are in the poller cache');

		$state['host']       = $hostId;
		$state['fabric']     = $fabricId;
		$state['graphs']     = $graphs;
		$state['lanes']      = e2e_threshold($graphs['Fabric Port State']['graph'], $graphs['Fabric Port State']['data'], 'active_lanes', '', '4');
		$state['age']        = e2e_threshold($graphs['Fabric Port State']['graph'], $graphs['Fabric Port State']['data'], 'age', '900', '');
		$state['symbol']     = e2e_threshold($graphs['Fabric Port Alert Rates']['graph'], $graphs['Fabric Port Alert Rates']['data'], 'symbol_err_rate', '0.5', '');
		$lid                 = sprintf('%d:1', G::forCas(2)->lidOf($adapter, 1));
		$state['lid']        = $lid;
		$state['counters']   = array($lid => array('SymbolErrorCounter' => '10'));

		e2e_expect($state['lanes'] > 0 && $state['age'] > 0 && $state['symbol'] > 0, 'Thold creates the three thresholds from its templates');

		FakeTools::state($dir, e2e_fabric($state));
		e2e_due();

		break;
	case 'check_setup':
		$host = db_fetch_row_prepared('SELECT status, availability_method FROM host WHERE id = ?', array($state['host']));
		e2e_expect((int) $host['status'] === 3, 'Cacti marks the created device up with availability checks off (status ' . $host['status'] . '), which Thold requires');

		$lanes = e2e_thold($state['lanes']);
		e2e_expect($lanes['lastread'] === '4' && (int) $lanes['thold_alert'] === 0, 'a 4X link reads 4 lanes and does not breach (lastread ' . $lanes['lastread'] . ')');
		e2e_thold_alive();

		break;
	case 'degrade':
		$state['degraded'] = true;
		FakeTools::state($dir, e2e_fabric($state));
		e2e_due();

		break;
	case 'check_degrade':
		$lanes = e2e_thold($state['lanes']);
		e2e_expect($lanes['lastread'] === '1' && (int) $lanes['thold_alert'] !== 0 && e2e_logs($state['lanes']) >= 1, 'a 4X link that falls to 1X fires the lanes threshold (lastread ' . $lanes['lastread'] . ', alert ' . $lanes['thold_alert'] . ')');
		$state['lanes_logs'] = e2e_logs($state['lanes']);
		e2e_thold_alive();

		break;
	case 'restore':
		$state['degraded'] = false;
		FakeTools::state($dir, e2e_fabric($state));
		e2e_due();

		break;
	case 'check_restore':
		$lanes = e2e_thold($state['lanes']);
		e2e_expect($lanes['lastread'] === '4' && (int) $lanes['thold_alert'] === 0 && e2e_logs($state['lanes']) > $state['lanes_logs'], 'the restored link clears the alert and logs the restoral');
		e2e_thold_alive();

		break;
	case 'burst':
		$state['counters'][$state['lid']]['SymbolErrorCounter'] = '5010';
		FakeTools::state($dir, e2e_fabric($state));
		e2e_due();

		break;
	case 'check_burst':
		$symbol = e2e_thold($state['symbol']);
		e2e_expect((float) $symbol['lastread'] > 0.5 && (int) $symbol['thold_alert'] !== 0, 'a burst of symbol errors fires the rate threshold (lastread ' . $symbol['lastread'] . ')');
		e2e_thold_alive();

		break;
	case 'steady':
		e2e_due();

		break;
	case 'check_steady':
		$symbol = e2e_thold($state['symbol']);
		e2e_expect($symbol['lastread'] === '0' && (int) $symbol['thold_alert'] === 0, 'an unchanged counter reads a rate of 0 and clears the alert (lastread ' . $symbol['lastread'] . ')');
		$state['symbol_logs'] = e2e_logs($state['symbol']);
		e2e_thold_alive();

		break;
	case 'reset':
		// Another tool resets the counter: it goes down.
		$state['counters'][$state['lid']]['SymbolErrorCounter'] = '3';
		FakeTools::state($dir, e2e_fabric($state));
		e2e_due();

		break;
	case 'check_reset':
		$symbol = e2e_thold($state['symbol']);
		$disc   = (int) db_fetch_cell("SELECT MAX(discontinuities) FROM plugin_fabric_sample WHERE counter_group = 'basic'");

		e2e_expect($disc >= 1, 'the decrease is counted as a discontinuity');
		e2e_expect((int) $symbol['thold_alert'] === 0 && e2e_logs($state['symbol']) === $state['symbol_logs'], 'the reset neither fires nor logs anything for the rate threshold (lastread "' . $symbol['lastread'] . '")');
		e2e_thold_alive();

		break;
	case 'maint':
		db_execute("INSERT INTO plugin_maint_schedules (enabled, name, mtype, stime, etime, minterval) VALUES ('on', 'fabric e2e', 1, " . (time() - 60) . ', ' . (time() + 3600) . ', 0)');
		db_execute_prepared('INSERT INTO plugin_maint_hosts (type, host, schedule) VALUES (1, ?, ?)', array($state['host'], db_fetch_insert_id()));
		$state['degraded']   = true;
		$state['lanes_logs'] = e2e_logs($state['lanes']);
		FakeTools::state($dir, e2e_fabric($state));
		e2e_due();

		break;
	case 'check_maint':
		$lanes = e2e_thold($state['lanes']);
		e2e_expect(plugin_maint_check_cacti_host($state['host']), 'Maint reports the device in maintenance');
		e2e_expect($lanes['lastread'] === '1' && e2e_logs($state['lanes']) === $state['lanes_logs'], 'in maintenance the 1X link is still measured but no alert is logged (lastread ' . $lanes['lastread'] . ')');
		e2e_thold_alive();

		break;
	case 'stale':
		// Maintenance over, the fabric disabled: nothing is swept, and the
		// last sample ages past the age threshold.
		db_execute('DELETE FROM plugin_maint_hosts');
		db_execute('DELETE FROM plugin_maint_schedules');
		db_execute_prepared("UPDATE plugin_fabric_fabric SET admin_state = 'disabled' WHERE id = ?", array($state['fabric']));
		db_execute('UPDATE plugin_fabric_sample SET observed_at = IF(observed_at > 2000, observed_at - 2000, 0), attempted_at = IF(attempted_at > 2000, attempted_at - 2000, 0), previous_at = IF(previous_at > 2000, previous_at - 2000, 0)');
		db_execute('UPDATE plugin_fabric_port SET link_observed_at = IF(link_observed_at > 2000, link_observed_at - 2000, 0)');

		break;
	case 'check_stale':
		$age   = e2e_thold($state['age']);
		$lanes = e2e_thold($state['lanes']);

		e2e_expect((int) $age['lastread'] > 900 && (int) $age['thold_alert'] !== 0, 'a stale port fires the age threshold (lastread ' . $age['lastread'] . ')');
		e2e_expect($lanes['lastread'] === '' || $lanes['lastread'] === null, 'stale link state reads unknown, not a last value (lastread "' . $lanes['lastread'] . '")');
		e2e_thold_alive();

		break;
	case 'leftover':
		// Rows Cacti's poller left in poller_output, next to the poller items
		// of their data sources, for a log check that fails.
		foreach (db_fetch_assoc('SELECT po.local_data_id, po.rrd_name, po.time, po.output, pi.rrd_num, pi.rrd_name AS item FROM poller_output AS po LEFT JOIN poller_item AS pi ON pi.local_data_id = po.local_data_id AND pi.rrd_name = po.rrd_name ORDER BY po.local_data_id, po.time') as $row) {
			print 'leftover ' . json_encode($row) . PHP_EOL;
		}

		break;
	case 'graph':
		// The Traffic graph against hand-computed values: rebuild its RRD
		// file with the data source definitions Cacti created, play known
		// counter values into it, and read the graph back through Cacti.
		$graph = $state['graphs']['Fabric Port Traffic'];
		$path  = (string) db_fetch_cell_prepared('SELECT data_source_path FROM data_template_data WHERE local_data_id = ?', array($graph['data']));
		$path  = str_replace('<path_rra>', $config['rra_path'], $path);
		$tool  = read_config_option('path_rrdtool');
		$info  = shell_exec(escapeshellarg($tool) . ' info ' . escapeshellarg($path));
		$ds    = array();

		preg_match_all('/^ds\[(\w+)\]\.(type|minimal_heartbeat|min|max) = "?([^"\n]*)"?$/m', (string) $info, $m, PREG_SET_ORDER);

		foreach ($m as $line) {
			$ds[$line[1]][$line[2]] = $line[3];
		}

		e2e_expect(isset($ds['rcv_data']) && $ds['rcv_data']['type'] === 'DERIVE' && (float) $ds['rcv_data']['min'] === 0.0, 'Cacti created the data counters as DERIVE with minimum 0');

		$t0   = (int) (floor(time() / 300) * 300) - 30 * 300;
		$args = array('create', $path . '.e2e', '--start', (string) ($t0 - 300), '--step', '300');

		foreach (array('xmit_data', 'rcv_data', 'xmit_pkts', 'rcv_pkts') as $name) {
			$args[] = sprintf('DS:%s:%s:%s:%s:%s', $name, $ds[$name]['type'], $ds[$name]['minimal_heartbeat'], $ds[$name]['min'] === 'NaN' ? 'U' : $ds[$name]['min'], $ds[$name]['max'] === 'NaN' ? 'U' : $ds[$name]['max']);
		}

		$args[] = 'RRA:AVERAGE:0.5:1:100';
		exec(escapeshellarg($tool) . ' ' . implode(' ', array_map('escapeshellarg', $args)), $out, $rc);

		// rcv_data grows by 300000 words per step: 1000 words, 4000 octets
		// per second. Step 6 goes down, as a reset does: unknown, no spike.
		$value = 1000000;

		for ($i = 0; $i <= 10; $i++) {
			$value = $i === 6 ? 5 : $value + 300000;
			exec(escapeshellarg($tool) . ' update ' . escapeshellarg($path . '.e2e') . ' ' . escapeshellarg(($t0 + $i * 300) . ':0:' . $value . ':0:0'));
		}

		rename($path . '.e2e', $path);

		$meta   = array();
		// export_csv selects rrdtool xport; without it core draws the graph.
		$xport  = rrdtool_function_xport($graph['graph'], 0, array('graph_start' => $t0 + 300, 'graph_end' => $t0 + 3000, 'export_csv' => true), $meta);
		$xport  = is_array($xport) ? $xport : array();
		$column = null;

		foreach ($xport['meta']['legend'] ?? array() as $key => $legend) {
			if (strpos($legend, 'Received') === 0) {
				$column = $key;
			}
		}

		$rows = array();

		foreach ($xport['data'] ?? array() as $row) {
			$rows[] = $column !== null && isset($row[$column]) ? $row[$column] : null;
		}

		$numbers = array_values(array_filter($rows, 'is_numeric'));
		$spikes  = array_filter($numbers, static function ($v) {
			return abs((float) $v - 4000) > 0.01;
		});

		e2e_expect($column !== null && count($numbers) >= 5, 'the Traffic graph exports a Received column (' . count($numbers) . ' values, legend ' . json_encode($xport['meta']['legend'] ?? null) . ')');
		e2e_expect($numbers !== array() && $spikes === array(), 'every Received value is 4000 bytes per second, the counter times 4 octets, and the reset leaves a gap, not a spike (' . implode(', ', array_slice($rows, 0, 12)) . ')');

		break;
	default:
		fwrite(STDERR, "Unknown phase\n");
		exit(2);
}

file_put_contents($file, json_encode($state));

exit($failures > 0 ? 1 : 0);
