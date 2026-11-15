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
 * Scale measurements at the documented fabric sizes (#49, docs/PLAN.md
 * "Performance"), with the fabric generator and the fake tools, through
 * the production sweep, repositories, data query, and pages:
 *
 *   FABRIC_TEST_DATABASE=disposable FABRIC_E2E_TOOLS=<root-owned tool dir> \
 *     php plugins/fabric/tests/bin/scale.php <state dir> <adapters>...
 *
 * For each size: the topology import, counter sweeps of a sample of nodes,
 * the data query's index and get answers for a switch device, the
 * inventory page. For the first size only, every graph of every port of
 * that switch is created and Cacti's poller timed over them, for measured
 * object counts and poll times. Prints one JSON
 * line per size. The fake tools are PHP scripts, so tool time is PHP start
 * up, not fabric round trips: the sweep figures measure the plugin's own
 * work, never a fabric's. Disposable databases only. Must parse on PHP 8.0.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

if (getenv('FABRIC_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set FABRIC_TEST_DATABASE=disposable\n");
	exit(2);
}

include_once($config['base_path'] . '/plugins/fabric/setup.php');

if (!fabric_boot()) {
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

use Somethingwithproof\Fabric\Tests\FabricGenerator as G;
use Somethingwithproof\Fabric\Tests\FakeTools;

$stateDir = rtrim($argv[1], '/');
$sizes    = array_map('intval', array_slice($argv, 2));
$sample   = 25;

set_config_option('fabric_tool_dir', (string) getenv('FABRIC_E2E_TOOLS'));

foreach ($sizes as $adapters) {
	db_execute('DELETE FROM plugin_fabric_fabric');
	foreach (array('node', 'port', 'sample', 'node_sweep', 'health', 'claim', 'relink') as $t) {
		db_execute("DELETE FROM plugin_fabric_$t");
	}

	$gen = G::forCas($adapters);
	FakeTools::state($stateDir, $gen);
	@unlink($stateDir . '/calls.log');

	db_execute("INSERT INTO plugin_fabric_fabric (name, backend, poller_id, sweep_interval, adapter, adapter_port, admin_state) VALUES ('scale', 'diags', 1, 300, 'mlx5_0', 1, 'enabled')");
	$fabricId = (int) db_fetch_insert_id();
	$clock    = new \Somethingwithproof\Fabric\SystemClock();
	$nodes    = new \Somethingwithproof\Fabric\NodeRepository(false);

	// The first sweep with a node GUID runs the topology (ibnetdiscover,
	// iblinkinfo, and the import) and that node's counters.
	$switch = \Somethingwithproof\Fabric\Guid::fromString(sprintf('0x%016x', G::SWITCH_BASE));
	$t      = microtime(true);
	fabric_sweep()->forFabric($fabricId, $switch, true);
	$first  = microtime(true) - $t;

	$all     = $nodes->forFabric($fabricId) ?? array();
	$ports   = (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_fabric_port');
	$sampled = array_slice(array_values(array_filter($all, static function ($n) {
		return $n->type === \Somethingwithproof\Fabric\NodeType::Adapter;
	})), 0, $sample);

	$t = microtime(true);

	// Not forced, as the poller sweeps: a forced run repeats the topology.
	foreach ($sampled as $node) {
		fabric_sweep()->forFabric($fabricId, $node->guid, false);
	}

	$perNode = $sampled === array() ? 0.0 : (microtime(true) - $t) / count($sampled);

	// One switch mapped to a device: the data query answers from the cache.
	db_execute("INSERT INTO host (description, hostname, poller_id, availability_method, snmp_version, status) VALUES ('scale switch', 'scale.invalid', 1, 0, 0, 3)");
	$host = (int) db_fetch_insert_id();
	db_execute_prepared('UPDATE plugin_fabric_node SET host_id = ? WHERE guid = ?', array($host, $switch->text));

	$query = new \Somethingwithproof\Fabric\PortQuery(false, $clock, false, null);
	$t     = microtime(true);
	$index = $query->answer($host, 'index');
	$tIdx  = microtime(true) - $t;
	$keys  = array_filter(explode("\n", $index));
	$t     = microtime(true);

	foreach ($keys as $key) {
		foreach (\Somethingwithproof\Fabric\PortValues::names() as $name) {
			$query->answer($host, 'get', $name, $key);
		}
	}

	$gets  = count($keys) * count(\Somethingwithproof\Fabric\PortValues::names());
	$tGet  = $gets > 0 ? (microtime(true) - $t) / $gets : 0.0;

	// The inventory overview, rendered as for an administrator.
	$page = new \Somethingwithproof\Fabric\InventoryPage(new \Somethingwithproof\Fabric\FabricRepository(false, $clock), $nodes, new \Somethingwithproof\Fabric\SampleRepository(false), $clock, static function (int $h): bool {
		return true;
	}, true);
	ob_start();
	$t = microtime(true);
	$page->renderList();
	$tPage = microtime(true) - $t;
	$bytes = strlen((string) ob_get_clean());

	// Measured Cacti objects and a poller run, on the first size only.
	$objects = null;

	if ($adapters === $sizes[0]) {
		$queryId = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query WHERE hash = ?', array(\Somethingwithproof\Fabric\TemplateInstaller::hash('data_query', 'Fabric Port')));
		api_device_dq_add($host, $queryId, DATA_QUERY_AUTOINDEX_INDEX_NUM_CHANGE);
		$indexes = array_column(db_fetch_assoc_prepared('SELECT DISTINCT snmp_index FROM host_snmp_cache WHERE host_id = ?', array($host)), 'snmp_index');

		foreach ($indexes as $index) {
			foreach (array_keys(\Somethingwithproof\Fabric\PortValues::templates()) as $name) {
				$gt   = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE hash = ?', array(\Somethingwithproof\Fabric\TemplateInstaller::hash('graph_template', $name)));
				$sqg  = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query_graph WHERE hash = ?', array(\Somethingwithproof\Fabric\TemplateInstaller::hash('data_query_graph', 'Fabric Port:' . $name)));
				$vals = array();
				$made = create_complete_graph_from_template($gt, $host, array('snmp_query_id' => $queryId, 'snmp_index_on' => 'fabricPort', 'snmp_index' => $index, 'snmp_query_graph_id' => $sqg), $vals);

				foreach ($made['local_data_id'] as $localDataId) {
					push_out_host($host, $localDataId);
				}
			}
		}

		// Availability checks are off, as on a created device, so the poller
		// polls every item instead of skipping a host it reads as down.
		db_execute('UPDATE plugin_fabric_node_sweep SET last_attempt = 0');
		$log = $config['base_path'] . '/log/cacti.log';
		$at  = is_file($log) ? (int) filesize($log) : 0;
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($config['base_path'] . '/poller.php') . ' --poller=1 --force 2>&1', $ignored);
		$stats = '';

		foreach (array_reverse(explode("\n", (string) file_get_contents($log, false, null, $at))) as $line) {
			if (strpos($line, 'SYSTEM STATS:') !== false) {
				$stats = trim(substr($line, strpos($line, 'SYSTEM STATS:') + 13));

				break;
			}
		}

		$objects = array(
			'ports'           => count($indexes),
			'graphs'          => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE host_id = ?', array($host)),
			'data_sources'    => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM data_local WHERE host_id = ?', array($host)),
			'rrd_items'       => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM data_template_rrd AS r INNER JOIN data_local AS l ON l.id = r.local_data_id WHERE l.host_id = ?', array($host)),
			'poller_items'    => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM poller_item WHERE host_id = ?', array($host)),
			'poller_stats'    => $stats,
		);

		foreach (db_fetch_assoc_prepared('SELECT id FROM graph_local WHERE host_id = ?', array($host)) as $g) {
			db_execute_prepared('DELETE FROM graph_templates_item WHERE local_graph_id = ?', array($g['id']));
			db_execute_prepared('DELETE FROM graph_templates_graph WHERE local_graph_id = ?', array($g['id']));
		}

		api_device_remove($host);
	}

	$calls = FakeTools::calls($stateDir);
	$tools = array_count_values(array_column($calls, 'tool'));

	print json_encode(array(
		'adapters'              => $adapters,
		'nodes'                 => count($all),
		'ports'                 => $ports,
		'topology_and_one_node' => round($first, 3),
		'counter_sweep_per_node' => round($perNode, 3),
		'sampled_nodes'         => count($sampled),
		'tool_runs'             => $tools,
		'samples'               => (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_fabric_sample'),
		'index_answer'          => round($tIdx, 4),
		'index_rows'            => count($keys),
		'get_answer_ms'         => round($tGet * 1000, 3),
		'overview_render'       => round($tPage, 3),
		'overview_bytes'        => $bytes,
		'memory_peak_mb'        => round(memory_get_peak_usage(true) / 1048576, 1),
		'cacti_objects'         => $objects,
	)) . PHP_EOL;

	db_execute_prepared('DELETE FROM host WHERE id = ?', array($host));
}
