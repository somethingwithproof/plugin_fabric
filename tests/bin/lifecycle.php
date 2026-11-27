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
 * Drive the plugin lifecycle through Cacti's own plugin API in a fresh PHP
 * process, the way plugins.php and cli/plugin_manage.php do, and print the
 * resulting state as JSON. Used by tests/Integration. Disposable databases
 * only.
 *
 *   php plugins/fabric/tests/bin/lifecycle.php <action> [argument]
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/plugins.php');
require_once($config['base_path'] . '/lib/poller.php');

if (getenv('FABRIC_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set FABRIC_TEST_DATABASE=disposable\n");
	exit(2);
}

$action   = isset($argv[1]) ? $argv[1] : 'state';
$argument = isset($argv[2]) ? $argv[2] : '';
$result   = null;

ob_start();

switch ($action) {
	case 'reset':
		api_plugin_uninstall('fabric');

		foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_fabric\\_%'") as $row) {
			db_execute('DROP TABLE `' . $row['t'] . '`');
		}

		db_execute("DELETE FROM settings WHERE name LIKE 'fabric\\_%'");
		db_execute("DELETE FROM plugin_db_changes WHERE plugin = 'fabric'");

		// The settings that record the query file as the plugin's are gone,
		// so the file goes too; the templates stay, as on a real site.
		if (is_file($config['base_path'] . '/resource/script_server/fabric_ports.xml')) {
			unlink($config['base_path'] . '/resource/script_server/fabric_ports.xml');
		}

		break;
	case 'install':
		api_plugin_install('fabric');

		break;
	case 'enable':
		api_plugin_enable('fabric');

		break;
	case 'disable':
		api_plugin_disable('fabric');

		break;
	case 'uninstall':
		api_plugin_uninstall('fabric');

		break;
	case 'upgrade':
		include_once($config['base_path'] . '/plugins/fabric/setup.php');
		$result = plugin_fabric_upgrade();

		break;
	case 'migrate_through':
		// Stop after the given migration, as an upgrade killed part way would.
		include_once($config['base_path'] . '/plugins/fabric/setup.php');
		fabric_boot();
		$result = (new \Somethingwithproof\Fabric\Schema(false))->migrate((int) $argument);

		break;
	case 'set_version':
		db_execute_prepared("UPDATE plugin_config SET version = ? WHERE directory = 'fabric'", array($argument));

		break;
	case 'set_schema':
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('fabric_schema_version', ?)", array($argument));

		break;
	case 'set_setting':
		list($name, $value) = explode('=', $argument, 2);
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array($name, $value));

		break;
	case 'drop_registrations':
		// What a failed hook registration on an earlier request leaves.
		db_execute("DELETE FROM plugin_hooks WHERE name = 'fabric'");
		db_execute("DELETE FROM plugin_realms WHERE plugin = 'fabric'");

		break;
	case 'drop_hook':
		db_execute_prepared("DELETE FROM plugin_hooks WHERE name = 'fabric' AND hook = ?", array($argument));

		break;
	case 'drop_v2_columns':
		// A version 1 database: the sample table before migration 2.
		foreach (array('previous_at', 'rates', 'reset', 'discontinuities', 'discontinuity_at') as $column) {
			db_execute('ALTER TABLE plugin_fabric_sample DROP COLUMN `' . $column . '`');
		}

		break;
	case 'drop_table':
		db_execute('DROP TABLE IF EXISTS `' . preg_replace('/[^a-z_]/', '', $argument) . '`');

		break;
	case 'seed':
		// A fabric and a node, so table drops and keeps are visible.
		db_execute("INSERT INTO plugin_fabric_fabric (name, backend, poller_id, sweep_interval, adapter, adapter_port, admin_state) VALUES ('seed', 'diags', 1, 300, 'mlx5_0', 1, 'enabled')");
		db_execute_prepared("INSERT INTO plugin_fabric_node (fabric_id, guid, node_type, presence, identity) VALUES (?, '0x0002c90300a00000', 'switch', 'present', 'ok')", array(db_fetch_insert_id()));

		break;
	case 'seed_sample':
		$nodeId = (int) db_fetch_cell_prepared("SELECT id FROM plugin_fabric_node WHERE guid = ?", array('0x0002c90300a00000'));
		db_execute_prepared("INSERT INTO plugin_fabric_sample (node_id, port_number, counter_group, observed_at, attempted_at, backend, cap_mask, cap_mask2, counters, error) VALUES (?, 1, 'basic', 100, 110, 'diags', 7, 8, ?, '')", array($nodeId, '{"PortXmitData":"123","PortRcvData":"456"}'));

		break;
	case 'sample':
		$result = db_fetch_row_prepared("SELECT node_id, port_number, counter_group, observed_at, attempted_at, backend, cap_mask, cap_mask2, counters, error, previous_at, rates, reset, discontinuities, discontinuity_at FROM plugin_fabric_sample WHERE port_number = 1 LIMIT 1", array());

		break;
	case 'sweep_flow':
		// A whole sweep against MariaDB through the real repositories and
		// the production process runner, with fake tools in a scratch
		// directory (REQ-TEST-002): import, a below-best link, an identity
		// conflict, a replaced adapter, and broken output that must leave
		// the inventory as it was (REQ-INV-001 to REQ-INV-004).
		include_once($config['base_path'] . '/plugins/fabric/setup.php');
		fabric_boot();
		require_once($config['base_path'] . '/plugins/fabric/tests/Support/FabricGenerator.php');
		require_once($config['base_path'] . '/plugins/fabric/tests/Support/FakeTools.php');
		require_once($config['base_path'] . '/plugins/fabric/tests/Support/Scratch.php');

		$scratch = new \Somethingwithproof\Fabric\Tests\Scratch();

		try {
			$result = fabric_sweep_flow($scratch->dir);
		} finally {
			$scratch->remove();
		}

		break;
	case 'remote_poller':
		// A fabric left on a remote poller, as an older row or a direct
		// write could hold: the manual sweep refuses it and logs why.
		db_execute("INSERT INTO plugin_fabric_fabric (name, backend, poller_id, sweep_interval, adapter, adapter_port, admin_state) VALUES ('remote', 'diags', 2, 300, 'mlx5_0', 1, 'enabled')");
		$remote  = (int) db_fetch_insert_id();
		$logfile = read_config_option('path_cactilog');
		$logfile = $logfile !== '' ? $logfile : $config['base_path'] . '/log/cacti.log';
		$before  = is_file($logfile) ? (int) filesize($logfile) : 0;
		$process = proc_open(array(PHP_BINARY, $config['base_path'] . '/plugins/fabric/fabric_sweep.php', '--fabric=' . $remote, '--force'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $config['base_path']);
		$stdout  = stream_get_contents($pipes[1]);
		$stderr  = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status  = proc_close($process);
		$logged  = is_file($logfile) ? (string) file_get_contents($logfile, false, null, $before) : '';
		$result  = array(
			'status' => $status,
			'stderr' => trim((string) $stderr),
			'stdout' => trim((string) $stdout),
			'logged' => strpos($logged, 'Fabric ' . $remote . ' was not swept: assigned to poller 2, not this poller (1)') !== false,
			'health' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_fabric_health WHERE fabric_id = ?', array($remote)),
		);

		break;
	case 'uninstall_unbooted':
		// What core does on uninstall when fabric_boot() refuses: the PHP 8.0
		// fallback, then core drops whatever is still tracked.
		include_once($config['base_path'] . '/plugins/fabric/include/unbooted-uninstall.php');
		fabric_uninstall_unbooted();
		api_plugin_db_changes_remove('fabric');
		api_plugin_remove_hooks('fabric');
		api_plugin_remove_realms('fabric');
		db_execute("DELETE FROM plugin_config WHERE directory = 'fabric'");

		break;
	case 'repair_as_viewer':
		// A viewer opens a plugin page after the realm rows went missing.
		db_execute("DELETE FROM user_auth WHERE username = 'fabricviewer'");
		db_execute("INSERT INTO user_auth (username, password, realm, full_name, enabled) VALUES ('fabricviewer', '', 0, 'viewer', 'on')");
		$viewer = (int) db_fetch_insert_id();
		db_execute("DELETE FROM plugin_realms WHERE plugin = 'fabric'");
		db_execute("DELETE FROM plugin_hooks WHERE name = 'fabric'");
		$_SESSION['sess_user_id'] = $viewer;
		db_execute("REPLACE INTO settings (name, value) VALUES ('admin_user', '0')");
		include_once($config['base_path'] . '/plugins/fabric/setup.php');
		plugin_fabric_upgrade();
		$result = array('viewer_realms' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM user_auth_realm WHERE user_id = ?', array($viewer)));
		db_execute("REPLACE INTO settings (name, value) VALUES ('admin_user', '1')");

		break;
	case 'operator_roles':
		include(__DIR__ . '/operator-roles.php');

		break;
	case 'state':
		break;
	default:
		fwrite(STDERR, "Unknown action $action\n");
		exit(2);
}

$output = ob_get_clean();

/**
 * @return array<string, mixed>
 */
function fabric_sweep_flow(string $dir): array {
	$G       = \Somethingwithproof\Fabric\Tests\FabricGenerator::class;
	$clock   = new \Somethingwithproof\Fabric\FixedClock(1800000000);
	$fabrics = new \Somethingwithproof\Fabric\FabricRepository(false, $clock);
	$nodes   = new \Somethingwithproof\Fabric\NodeRepository(false);
	$health  = new \Somethingwithproof\Fabric\FabricHealthRepository(false);

	db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array('fabric_tool_dir', $dir));
	$settings = (new \Somethingwithproof\Fabric\SettingsRepository(false))->load();
	$id       = $fabrics->save(new \Somethingwithproof\Fabric\Fabric(0, 'flow', \Somethingwithproof\Fabric\Backend::Diags, 1, 300, 'mlx5_0', 1, \Somethingwithproof\Fabric\AdminState::Enabled));
	// The scratch tool directory belongs to the test account, not root.
	$sweep    = new \Somethingwithproof\Fabric\Sweep(false, $clock, new \Somethingwithproof\Fabric\ProcRunner(), $settings, 1, true, posix_geteuid());

	$gen = $G::forCas(4);
	$gen->degrade($G::CA_BASE, 1, '1X', 'EDR', '4X', '');

	$state = function () use ($id, $nodes, $health) {
		$rows = array();

		foreach ($nodes->forFabric($id) ?? array() as $node) {
			$rows[$node->guid->text] = $node->presence->value . '/' . $node->identity->value;
		}

		ksort($rows);
		$h = $health->find($id) ?? array();

		return array(
			'nodes'     => $rows,
			'ports'     => (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_fabric_port'),
			'error'     => (string) ($h['discovery_error'] ?? ''),
			'links'     => array_column(db_fetch_assoc('SELECT link_condition, COUNT(*) AS n FROM (SELECT IF(logical_state = \'\', \'unknown\', logical_state) AS link_condition FROM plugin_fabric_port) AS t GROUP BY link_condition ORDER BY link_condition'), 'n', 'link_condition'),
			'samples'   => (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_fabric_sample WHERE error = ''"),
			'proposals' => (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_fabric_relink WHERE state = 'proposed'"),
		);
	};

	$steps = array();

	// 1. First import.
	\Somethingwithproof\Fabric\Tests\FakeTools::install($dir, $gen);
	$swept           = $sweep->forFabric($id, null, true);
	$steps['import'] = $state() + array('swept' => $swept);

	$below = 0;

	foreach ($nodes->forFabric($id) ?? array() as $node) {
		foreach ($nodes->ports($node->id) ?? array() as $port) {
			if ((new \Somethingwithproof\Fabric\LinkStateEvaluator())->evaluate($port->link) === \Somethingwithproof\Fabric\LinkCondition::BelowBest) {
				$below++;
			}
		}
	}

	$steps['import']['below_best'] = $below;

	// 2. The third adapter reports another device ID: a conflict.
	$clock->advance(600);
	$conflicted                         = $G::CA_BASE + (2 << 4);
	$gen->nodes[$conflicted]['devid']   = 0x1021;
	\Somethingwithproof\Fabric\Tests\FakeTools::install($dir, $gen);
	$sweep->forFabric($id, null, true);
	$steps['conflict'] = $state();

	// 3. The second adapter is replaced by a new card on the same switch port.
	$clock->advance(600);
	$old = $G::CA_BASE + (1 << 4);
	$new = $G::CA_BASE + (9 << 4);
	$gen->nodes[$new]     = array('desc' => 'node0001 HCA-1 new') + $gen->nodes[$old];
	$gen->nodes[$new]['sysimg'] = $new;
	$gen->portGuids[$new] = array(1 => $new + 1, 2 => $new + 2);
	$gen->portLids[$new]  = $gen->portLids[$old];
	[$sg, $sp]            = $gen->links[$old . ':1'];
	unset($gen->nodes[$old], $gen->portGuids[$old], $gen->portLids[$old], $gen->links[$old . ':1'], $gen->state[$old . ':1'], $gen->state[$old . ':2']);
	$gen->link($new, 1, $sg, $sp);
	$gen->state[$new . ':2'] = array('width' => '', 'speed' => '', 'logical' => 'Down', 'phys' => 'Polling', 'couldWidth' => '', 'couldSpeed' => '');
	\Somethingwithproof\Fabric\Tests\FakeTools::install($dir, $gen);
	$sweep->forFabric($id, null, true);
	$steps['replaced'] = $state();

	// 4. Broken outputs change nothing.
	foreach (array('truncate', 'garbage', 'exit') as $mode) {
		$clock->advance(600);
		\Somethingwithproof\Fabric\Tests\FakeTools::install($dir, $gen, array('ibnetdiscover' => $mode));
		$sweep->forFabric($id, null, true);
		$steps[$mode] = $state();
	}

	// 5. An adapter description imitates a whole iblinkinfo port line. Its
	// own lines are skipped and counted; the rest of the fabric is applied
	// and that adapter keeps its last link states.
	$clock->advance(600);
	$hostile    = $G::CA_BASE + (3 << 4);
	$hostileRow = $nodes->find((int) db_fetch_cell_prepared('SELECT id FROM plugin_fabric_node WHERE fabric_id = ? AND guid = ?', array($id, sprintf('0x%016x', $hostile))));
	$keptBefore = db_fetch_assoc_prepared('SELECT port_number, presence, logical_state FROM plugin_fabric_port WHERE node_id = ? ORDER BY port_number', array($hostileRow !== null ? $hostileRow->id : 0));
	$gen->nodes[$hostile]['desc'] = 'x" 9    9[  ] ==( 4X      25.78125 Gbps Active/  LinkUp)==>  0x0002c90300b99999      9    9[  ] "y';
	\Somethingwithproof\Fabric\Tests\FakeTools::install($dir, $gen);
	$sweep->forFabric($id, null, true);
	$h                = $health->find($id) ?? array();
	$steps['hostile'] = $state() + array(
		'links_error'   => (string) ($h['links_error'] ?? ''),
		'links_skipped' => (int) ($h['links_skipped'] ?? -1),
		'kept'          => db_fetch_assoc_prepared('SELECT port_number, presence, logical_state FROM plugin_fabric_port WHERE node_id = ? ORDER BY port_number', array($hostileRow !== null ? $hostileRow->id : 0)) === $keptBefore && $keptBefore !== array(),
	);

	$calls = \Somethingwithproof\Fabric\Tests\FakeTools::calls($dir);

	return array(
		'steps'        => $steps,
		'tools'        => array_values(array_unique(array_column($calls, 'tool'))),
		'resets'       => count(array_filter($calls, static fn (array $c): bool => array_intersect($c['argv'], array('-r', '-R', '-k', '-K')) !== array())),
		'conflicted'   => sprintf('0x%016x', $conflicted),
		'old'          => sprintf('0x%016x', $old),
		'new'          => sprintf('0x%016x', $new),
	);
}

$tables = array();

foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_fabric\\_%' ORDER BY TABLE_NAME") as $row) {
	$tables[$row['t']] = (int) db_fetch_cell('SELECT COUNT(*) FROM `' . $row['t'] . '`');
}

print json_encode(array(
	'result'  => $result,
	'output'  => $output,
	'plugin'  => db_fetch_row("SELECT status, version FROM plugin_config WHERE directory = 'fabric'"),
	'schema'  => db_fetch_cell("SELECT value FROM settings WHERE name = 'fabric_schema_version'"),
	'hooks'   => array_column(db_fetch_assoc("SELECT hook, status FROM plugin_hooks WHERE name = 'fabric' ORDER BY hook"), 'status', 'hook'),
	'realms'  => array_column(db_fetch_assoc("SELECT file, display FROM plugin_realms WHERE plugin = 'fabric' ORDER BY file"), 'display', 'file'),
	'admin'   => (int) db_fetch_cell("SELECT COUNT(*) FROM user_auth_realm AS uar INNER JOIN plugin_realms AS pr ON pr.id + 100 = uar.realm_id WHERE pr.plugin = 'fabric' AND uar.user_id = 1"),
	'tracked' => array_column(db_fetch_assoc("SELECT `table` FROM plugin_db_changes WHERE plugin = 'fabric' AND method = 'create' ORDER BY `table`"), 'table'),
	'tables'  => $tables,
	'columns' => array_column(db_fetch_assoc("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_fabric_sample' ORDER BY ORDINAL_POSITION"), 'c'),
	// The shipped Cacti objects, counted by name, so a duplicate shows.
	'objects' => array(
		'host_template'  => (int) db_fetch_cell("SELECT COUNT(*) FROM host_template WHERE name = 'Fabric Node'"),
		'snmp_query'     => (int) db_fetch_cell("SELECT COUNT(*) FROM snmp_query WHERE name = 'Fabric Port'"),
		'data_template'  => (int) db_fetch_cell("SELECT COUNT(*) FROM data_template WHERE name LIKE 'Fabric Port %'"),
		'graph_template' => (int) db_fetch_cell("SELECT COUNT(*) FROM graph_templates WHERE name LIKE 'Fabric Port %'"),
		'rrd_items'      => (int) db_fetch_cell("SELECT COUNT(*) FROM data_template_rrd AS r INNER JOIN data_template AS t ON t.id = r.data_template_id WHERE t.name LIKE 'Fabric Port %' AND r.local_data_id = 0"),
		'query_file'     => is_file($config['base_path'] . '/resource/script_server/fabric_ports.xml'),
		'version'        => db_fetch_cell("SELECT value FROM settings WHERE name = 'fabric_templates_version'"),
	),
), JSON_PRETTY_PRINT) . PHP_EOL;
