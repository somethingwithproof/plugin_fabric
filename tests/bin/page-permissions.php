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
 * Page permissions and POST-only actions over HTTP (REQ-UI-001 to
 * REQ-UI-005). Requests each plugin page as four users:
 *
 *   anonymous  not logged in: every page shows the login form
 *   admin      Fabric View and Fabric Admin: every page loads
 *   viewer     Console and Fabric View: the inventory loads, admin and
 *              health are denied, and nodes of a device the viewer may
 *              not see, or mapped to no device, are left out
 *   norealm    Console only: every page is denied
 *   restricted Fabric View and Fabric Admin, but device permissions for
 *              only one device: pages load, and no action changes a
 *              record on a device it may not see
 *
 * Then shows that fabric-supplied descriptions are escaped, that a delete
 * or re-link confirmation sent as GET, or as POST without the csrf-magic
 * token, has no effect, and that the same action as a proper POST works. Disposable databases only: it resets the admin password and adds
 * users and devices. Must parse on PHP 8.0.
 *
 *   FABRIC_TEST_DATABASE=disposable php plugins/fabric/tests/bin/page-permissions.php http://127.0.0.1:8080/cacti/
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

if (getenv('FABRIC_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set FABRIC_TEST_DATABASE=disposable\n");
	exit(2);
}

$base     = rtrim($argv[1], '/') . '/plugins/fabric/';
$root     = rtrim($argv[1], '/') . '/';
$password = 'Fabric-' . bin2hex(random_bytes(8));
$failures = 0;

$view  = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'fabric' AND file = 'fabric.php'");
$admin = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'fabric' AND file = 'fabric_admin.php,fabric_health.php'");

db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = '', password_change = '', enabled = 'on', locked = '', failed_attempts = 0 WHERE id = 1", array(password_hash($password, PASSWORD_DEFAULT)));

$users = array();

// Realm 8 is Console access. The viewer's policies deny by default (2), so
// only the devices granted to it below are visible, as Cacti's permissive
// model evaluates them; an allow policy with no tree exception is treated by
// core as access to every device.
foreach (array('viewer' => array(8, $view), 'norealm' => array(8), 'restricted' => array(8, $view, $admin)) as $name => $realms) {
	$policy = $name === 'norealm' ? 1 : 2;

	db_execute_prepared('DELETE FROM user_auth WHERE username = ?', array($name));
	db_execute_prepared("INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled) VALUES (?, ?, 0, ?, '', '', ?, 1, ?, ?, 'on')", array($name, password_hash($password, PASSWORD_DEFAULT), $name, $policy, $policy, $policy));
	$users[$name] = (int) db_fetch_insert_id();

	foreach ($realms as $realm) {
		db_execute_prepared('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (?, ?)', array($realm, $users[$name]));
	}
}

// A fabric with three adapters: one mapped to a device the viewer may see,
// one to a device it may not, and one unmapped. The viewer is granted only
// the first device, through a device permission (user_auth_perms type 3
// under a deny policy). Descriptions carry markup that must stay text.
db_execute("INSERT INTO plugin_fabric_fabric (name, backend, poller_id, sweep_interval, adapter, adapter_port, admin_state) VALUES ('perm', 'diags', 1, 300, 'mlx5_0', 1, 'enabled')");
$fabric  = (int) db_fetch_insert_id();
$devices = array();
$nodes   = array();
$guids   = array('open' => '0x0002c90300b00010', 'hidden' => '0x0002c90300b00020', 'unmapped' => '0x0002c90300b00030', 'new' => '0x0002c90300b00040', 'fresh' => '0x0002c90300b00050');

foreach ($guids as $key => $guid) {
	$host = 0;

	if ($key === 'open' || $key === 'hidden') {
		db_execute_prepared('INSERT INTO host (description, hostname) VALUES (?, ?)', array($key === 'open' ? '<b id="fabricprobe">Open node</b>' : 'Hidden node', $key . '.invalid'));
		$host = $devices[$key] = (int) db_fetch_insert_id();
	}

	// The unmapped node went missing; "new" replaced it on the same switch
	// port. "fresh" is present and unmapped, for device creation.
	db_execute_prepared("INSERT INTO plugin_fabric_node (fabric_id, guid, node_type, port_count, description, host_id, presence, identity) VALUES (?, ?, 'ca', 1, ?, ?, ?, 'ok')", array($fabric, $guid, '<img src=x id="nodename' . $key . '">', $host, $key === 'unmapped' ? 'missing' : 'present'));
	$nodes[$key] = (int) db_fetch_insert_id();
	// Active ports with a remote end, so a mapped device has indexes.
	db_execute_prepared("INSERT INTO plugin_fabric_port (node_id, port_number, presence, logical_state, remote_guid, remote_port) VALUES (?, 1, 'present', 'active', '0x0002c90300a00000', 1)", array($nodes[$key]));
}

// A Fabric Port Traffic graph on the open device's port 1, as the New
// Graphs page would make it, for the graph link checks.
$traffic = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE hash = ?', array(md5('plugin_fabric:graph_template:Fabric Port Traffic')));
$query   = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query WHERE hash = ?', array(md5('plugin_fabric:data_query:Fabric Port')));
db_execute_prepared('INSERT INTO graph_local (host_id, graph_template_id, snmp_query_id, snmp_index) VALUES (?, ?, ?, ?)', array($devices['open'], $traffic, $query, $guids['open'] . ':1'));
$graph = (int) db_fetch_insert_id();
db_execute_prepared("INSERT INTO graph_templates_graph (local_graph_id, graph_template_id, title_cache) VALUES (?, ?, 'fabric probe graph')", array($graph, $traffic));
$graphLink = 'graph.php?local_graph_id=' . $graph . '&amp;rra_id=all';

foreach (array('viewer', 'restricted') as $name) {
	db_execute_prepared('INSERT INTO user_auth_perms (user_id, item_id, type) VALUES (?, ?, 3)', array($users[$name], $devices['open']));
}

// The hidden node also has an identity conflict and a proposal of its own.
db_execute_prepared("UPDATE plugin_fabric_node SET identity = 'conflict', device_id = 4123, conflict_seen = ? WHERE id = ?", array(json_encode(array('node_type' => 'ca', 'port_count' => 1, 'device_id' => 4125, 'vendor_id' => 0, 'sysimg_guid' => '')), $nodes['hidden']));
db_execute_prepared("INSERT INTO plugin_fabric_relink (fabric_id, old_node_id, new_node_id, neighbor_guid, neighbor_port, state, proposed_at, decided_at) VALUES (?, ?, ?, '0x0002c90300a00000', 3, 'proposed', 1, 0)", array($fabric, $nodes['unmapped'], $nodes['new']));
$relink = (int) db_fetch_insert_id();
db_execute_prepared("INSERT INTO plugin_fabric_relink (fabric_id, old_node_id, new_node_id, neighbor_guid, neighbor_port, state, proposed_at, decided_at) VALUES (?, ?, ?, '0x0002c90300a00000', 4, 'proposed', 1, 0)", array($fabric, $nodes['hidden'], $nodes['new']));
$hiddenRelink = (int) db_fetch_insert_id();

function perm_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function perm_http($url, $jar, $post = null) {
	$ch = curl_init($url);

	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60));

	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}

	$body = (string) curl_exec($ch);

	return array((int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body);
}

function perm_token($body) {
	return preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/', $body, $m) ? $m[1] : '';
}

function perm_login($user) {
	global $root, $password;

	$jar = tempnam(sys_get_temp_dir(), 'fabricperm');

	if ($user === 'anonymous') {
		return $jar;
	}

	list(, $login) = perm_http($root . 'index.php', $jar);
	perm_http($root . 'index.php', $jar, array('__csrf_magic' => perm_token($login), 'action' => 'login', 'login_username' => $user, 'login_password' => $password));

	return $jar;
}

// Markers that only the rendered plugin page contains.
$pages = array('fabric_admin.php' => 'Re-link Proposals', 'fabric_health.php' => 'Counter Sweeps Needing Attention', 'fabric.php' => '<th>Last Seen</th>');
$plans = array(
	'anonymous' => array('fabric_admin.php' => false, 'fabric_health.php' => false, 'fabric.php' => false),
	'admin'     => array('fabric_admin.php' => true, 'fabric_health.php' => true, 'fabric.php' => true),
	'viewer'    => array('fabric_admin.php' => false, 'fabric_health.php' => false, 'fabric.php' => true),
	'norealm'   => array('fabric_admin.php' => false, 'fabric_health.php' => false, 'fabric.php' => false),
	'restricted' => array('fabric_admin.php' => true, 'fabric_health.php' => true, 'fabric.php' => true),
);

foreach ($plans as $user => $expect) {
	$jar = perm_login($user);

	foreach ($expect as $page => $allowed) {
		list($code, $body) = perm_http($base . $page, $jar);
		$shown = strpos($body, $pages[$page]) !== false;

		perm_expect($shown === $allowed && preg_match('/(Fatal error|Parse error|Warning:|Uncaught)/', $body) !== 1, "$user " . ($allowed ? 'sees' : 'is denied') . " $page (HTTP $code)");

		if ($user === 'anonymous') {
			perm_expect(strpos($body, 'login_username') !== false, "anonymous is sent to the login form from $page");
		}

		if ($page === 'fabric.php' && $allowed && $user !== 'anonymous') {
			list(, $nodePage) = perm_http($base . 'fabric.php?node=' . $nodes['open'], $jar);
			perm_expect($traffic > 0 && strpos($nodePage, $graphLink) !== false, "$user sees the link to a Traffic graph it may view (permissive graph permissions)");

			list(, $ranking) = perm_http($base . 'fabric.php?view=congestion', $jar);
			perm_expect(strpos($ranking, 'Ports with the Highest Transmit Wait') !== false && strpos($ranking, $guids['hidden']) === false, "$user's congestion ranking loads and leaves out what it may not see");
		}

		if ($page === 'fabric.php' && $allowed) {
			$all   = $user === 'admin';
			$realm = $user !== 'viewer';

			perm_expect(strpos($body, $guids['open']) !== false, "$user sees the node of a device it may view");
			perm_expect((strpos($body, $guids['hidden']) !== false) === $all, "$user " . ($all ? 'sees' : 'does not see') . ' the node of a device it ' . ($all ? 'may' : 'may not') . ' view');
			perm_expect((strpos($body, $guids['unmapped']) !== false) === $realm, "$user " . ($realm ? 'sees' : 'does not see') . ' an unmapped node');

			// Fabric-supplied descriptions are text.
			perm_expect(strpos($body, '<img src=x id="nodenameopen">') === false && strpos($body, '&lt;img src=x id=&quot;nodenameopen&quot;&gt;') !== false, "$user's inventory escapes the node description");
		}
	}

	unlink($jar);
}

// Fabric Admin with device permissions for one device only: nothing on
// another device may be shown or changed.
$jar     = perm_login('restricted');
$host_of = function ($id) {
	return (int) db_fetch_cell_prepared('SELECT host_id FROM plugin_fabric_node WHERE id = ?', array($id));
};
$node_row = function ($id) {
	return db_fetch_row_prepared('SELECT host_id, identity, revision FROM plugin_fabric_node WHERE id = ?', array($id));
};

list(, $list) = perm_http($base . 'fabric_admin.php', $jar);
$token        = perm_token($list);
perm_expect(strpos($list, $guids['hidden']) === false, 'restricted admin: conflicts and proposals leave out the node of a hidden device');
perm_expect(strpos($list, 'action=delete_fabric&amp;id=' . $fabric) === false, 'restricted admin: no delete action for a fabric that holds a hidden device');

list(, $edit) = perm_http($base . 'fabric_admin.php?action=edit_node&id=' . $nodes['hidden'], $jar);
perm_expect(strpos($edit, 'name="host_id"') === false, 'restricted admin: the node of a hidden device cannot be opened for editing');

list(, $edit) = perm_http($base . 'fabric_admin.php?action=edit_node&id=' . $nodes['open'], $jar);
perm_expect(strpos($edit, 'name="host_id"') !== false && strpos($edit, '>Hidden node<') === false, 'restricted admin: the device list leaves out a hidden device');

$hiddenBefore = $node_row($nodes['hidden']);
perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => $token, 'action' => 'save_node', 'id' => $nodes['hidden'], 'revision' => $hiddenBefore['revision'], 'host_id' => 0));
perm_expect($host_of($nodes['hidden']) === $devices['hidden'], 'restricted admin: unmapping the node of a hidden device is refused');

$openBefore = $node_row($nodes['open']);
perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => $token, 'action' => 'save_node', 'id' => $nodes['open'], 'revision' => $openBefore['revision'], 'host_id' => $devices['hidden']));
perm_expect($host_of($nodes['open']) === $devices['open'], 'restricted admin: mapping a node to a hidden device is refused');

perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => $token, 'action' => 'accept_conflict', 'id' => $nodes['hidden'], 'revision' => $hiddenBefore['revision']));
perm_expect($node_row($nodes['hidden']) == $hiddenBefore, 'restricted admin: accepting the conflict of a hidden device node is refused');

perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => $token, 'action' => 'reject_relink', 'id' => $hiddenRelink));
perm_expect((string) db_fetch_cell_prepared('SELECT state FROM plugin_fabric_relink WHERE id = ?', array($hiddenRelink)) === 'proposed', 'restricted admin: rejecting a proposal for a hidden device node is refused');

perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => $token, 'action' => 'save_fabric', 'id' => $fabric, 'name' => 'renamed', 'backend' => 'diags', 'poller_id' => 1, 'sweep_interval' => 300, 'adapter' => 'mlx5_0', 'adapter_port' => 1, 'enabled' => 'on'));
perm_expect((string) db_fetch_cell_prepared('SELECT name FROM plugin_fabric_fabric WHERE id = ?', array($fabric)) === 'perm', 'restricted admin: changing a fabric that holds a hidden device is refused');

perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => $token, 'action' => 'delete_fabric_confirm', 'id' => $fabric));
perm_expect((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_fabric_fabric WHERE id = ?', array($fabric)) === 1, 'restricted admin: deleting a fabric that holds a hidden device is refused');

list($code, $export) = perm_http($base . 'fabric_admin.php?action=export', $jar);
$document = json_decode($export, true);
perm_expect($code === 200 && is_array($document) && strpos($export, $guids['open']) !== false && strpos($export, $guids['hidden']) === false, 'restricted admin: the export leaves out the node of a hidden device');

list(, $health) = perm_http($base . 'fabric_health.php', $jar);
perm_expect(strpos($health, '0 identity conflicts and 1 open re-link proposals') !== false, 'restricted admin: health counts leave out the hidden device node');

// Fabric Admin alone does not grant Cacti's device management or template
// import: no device is created and no template reinstalled.
$hostCount = function () {
	return (int) db_fetch_cell('SELECT COUNT(*) FROM host');
};
$before = $hostCount();
list(, $edit) = perm_http($base . 'fabric_admin.php?action=edit_node&id=' . $nodes['fresh'], $jar);
perm_expect(strpos($edit, 'name="host_id"') !== false && strpos($edit, 'create_device') === false, 'restricted admin without device management: no create action on an unmapped node');
list(, $confirm) = perm_http($base . 'fabric_admin.php?action=create_device&id=' . $nodes['fresh'], $jar);
perm_expect(strpos($confirm, 'Creating the device adds') === false, 'restricted admin without device management: the creation page is denied');
perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => $token, 'action' => 'create_device_confirm', 'id' => $nodes['fresh'], 'revision' => $node_row($nodes['fresh'])['revision']));
perm_expect($hostCount() === $before && $host_of($nodes['fresh']) === 0, 'restricted admin without device management: a creation POST creates nothing');
// The query file goes missing, as when an operator removes it; only the
// health page notices, and only a user who may import templates can put
// it back.
$queryFile = $config['base_path'] . '/resource/script_server/fabric_ports.xml';
unlink($queryFile);
list(, $health) = perm_http($base . 'fabric_health.php', $jar);
perm_expect(strpos($health, 'is missing or differs') !== false && strpos($health, 'reinstall_templates') === false, 'restricted admin without template import: the missing query file is shown, with no reinstall action');
perm_http($base . 'fabric_health.php', $jar, array('__csrf_magic' => perm_token($health), 'action' => 'reinstall_templates'));
clearstatcache();
perm_expect(!is_file($queryFile), 'restricted admin without template import: a reinstall POST installs nothing');

unlink($jar);

// Graph links follow core's graph permissions, not the plugin's: under
// restrictive graph permissions the viewer, who has no grant on the graph
// template, gets no link to the graph.
db_execute("REPLACE INTO settings (name, value) VALUES ('graph_auth_method', '2')");
// Core caches allowed-graph counts per user for a day.
// A second graph on the device, from a template the viewer is granted, so
// the device stays visible under the restrictive rule.
$errorsTemplate = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE hash = ?', array(md5('plugin_fabric:graph_template:Fabric Port Errors')));
db_execute_prepared('INSERT INTO graph_local (host_id, graph_template_id, snmp_query_id, snmp_index) VALUES (?, ?, ?, ?)', array($devices['open'], $errorsTemplate, $query, $guids['open'] . ':1'));
$errorsGraph = (int) db_fetch_insert_id();
db_execute_prepared("INSERT INTO graph_templates_graph (local_graph_id, graph_template_id, title_cache) VALUES (?, ?, 'fabric probe errors graph')", array($errorsGraph, $errorsTemplate));
db_execute_prepared('INSERT INTO user_auth_perms (user_id, item_id, type) VALUES (?, ?, 4)', array($users['viewer'], $errorsTemplate));
db_execute('DELETE FROM user_auth_row_cache');
$jar = perm_login('viewer');
list(, $nodePage) = perm_http($base . 'fabric.php?node=' . $nodes['open'], $jar);
include_once('./lib/auth.php');
set_config_option('graph_auth_method', '2');
$errorsLink = 'graph.php?local_graph_id=' . $errorsGraph . '&amp;rra_id=all';
perm_expect(is_device_allowed($devices['open'], $users['viewer']) && strpos($nodePage, $guids['open']) !== false, 'viewer: the node of a device core allows under restrictive graph permissions is shown');
perm_expect(!is_graph_allowed($graph, $users['viewer']) && strpos($nodePage, $graphLink) === false, 'viewer: no link to a graph whose template restrictive graph permissions deny, as core decides');
perm_expect(is_graph_allowed($errorsGraph, $users['viewer']) && strpos($nodePage, $errorsLink) !== false, 'viewer: a link to the graph whose template is granted, as core decides');
unlink($jar);
db_execute("REPLACE INTO settings (name, value) VALUES ('graph_auth_method', '1')");
db_execute_prepared('DELETE FROM user_auth_perms WHERE user_id = ? AND type = 4', array($users['viewer']));
db_execute_prepared('DELETE FROM graph_templates_graph WHERE local_graph_id = ?', array($errorsGraph));
db_execute_prepared('DELETE FROM graph_local WHERE id = ?', array($errorsGraph));

// A full administrator creates a device for the fresh node: a GET shows
// what will be added and changes nothing; the POST creates the device on
// the fabric's poller, maps the node, and adds the data query.
$jar             = perm_login('admin');

// The template reinstall needs template import, which the admin holds.
list(, $health) = perm_http($base . 'fabric_health.php', $jar);
perm_expect(strpos($health, 'reinstall_templates') !== false && strpos($health, 'Cacti Objects') !== false, 'admin: the health page offers the reinstall while the query file is missing');
perm_http($base . 'fabric_health.php?action=reinstall_templates', $jar);
clearstatcache();
perm_expect(!is_file($queryFile), 'admin: a reinstall sent as GET has no effect');
perm_http($base . 'fabric_health.php', $jar, array('__csrf_magic' => perm_token($health), 'action' => 'reinstall_templates'));
clearstatcache();
perm_expect(is_file($queryFile) && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates WHERE hash = ?', array(md5('plugin_fabric:graph_template:Fabric Port Traffic'))) === 1, 'admin: a reinstall POST restores the query file without duplicating the templates');


$before          = $hostCount();
list(, $confirm) = perm_http($base . 'fabric_admin.php?action=create_device&id=' . $nodes['fresh'], $jar);
perm_expect(strpos($confirm, 'Creating the device adds') !== false && $hostCount() === $before, 'admin: the creation page shows the plan and creates nothing');
perm_http($base . 'fabric_admin.php?action=create_device_confirm&id=' . $nodes['fresh'], $jar);
perm_expect($hostCount() === $before, 'admin: a creation sent as GET has no effect');
perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => perm_token($confirm), 'action' => 'create_device_confirm', 'id' => $nodes['fresh'], 'revision' => $node_row($nodes['fresh'])['revision']));
$created = $host_of($nodes['fresh']);
$row     = db_fetch_row_prepared('SELECT hostname, poller_id, availability_method, snmp_version FROM host WHERE id = ?', array($created));
perm_expect($created > 0 && $hostCount() === $before + 1 && is_array($row) && $row['hostname'] === '0002c90300b00050.fabric.invalid' && (int) $row['poller_id'] === 1 && (int) $row['availability_method'] === 0 && (int) $row['snmp_version'] === 0, 'admin: a creation POST makes one device with a reserved host name, no checks, and the fabric poller');
perm_expect((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_snmp_query WHERE host_id = ? AND snmp_query_id = ? AND reindex_method = 2', array($created, $query)) === 1, 'admin: the created device has the Fabric Port data query with Index Count Changed, whatever the site default');
$cached = function () use ($created, $query) {
	return (int) db_fetch_cell_prepared('SELECT COUNT(DISTINCT snmp_index) FROM host_snmp_cache WHERE host_id = ? AND snmp_query_id = ?', array($created, $query));
};
perm_expect($cached() === 1, 'admin: the created device lists the node\'s port (' . $cached() . ' indexes)');
list(, $again) = perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => perm_token($confirm), 'action' => 'create_device_confirm', 'id' => $nodes['fresh'], 'revision' => $node_row($nodes['fresh'])['revision']));
perm_expect($hostCount() === $before + 1, 'admin: a second creation for a mapped node creates nothing');

// Mapping to a device another poller polls is refused.
db_execute("REPLACE INTO poller (id, name, hostname, disabled) VALUES (3, 'fabric-other', 'other.invalid', 'on')");
db_execute("INSERT INTO host (description, hostname, poller_id) VALUES ('Other poller', 'other.invalid', 3)");
$other = (int) db_fetch_insert_id();
list(, $list) = perm_http($base . 'fabric_admin.php', $jar);
perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => perm_token($list), 'action' => 'save_node', 'id' => $nodes['fresh'], 'revision' => $node_row($nodes['fresh'])['revision'], 'host_id' => $other));
perm_expect($host_of($nodes['fresh']) === $created, 'admin: mapping a node to a device on another poller is refused');
db_execute_prepared('DELETE FROM host WHERE id = ?', array($other));
db_execute('DELETE FROM poller WHERE id = 3');

// Unmapping the last node empties the device's port list, which an empty
// script query would not do on Cacti 1.2.31; the device itself stays.
list(, $list) = perm_http($base . 'fabric_admin.php', $jar);
perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => perm_token($list), 'action' => 'save_node', 'id' => $nodes['fresh'], 'revision' => $node_row($nodes['fresh'])['revision'], 'host_id' => 0));
perm_expect($host_of($nodes['fresh']) === 0 && $cached() === 0 && $hostCount() === $before + 1, 'admin: unmapping the last node of a device clears its port list and keeps the device (' . $cached() . ' indexes)');
list(, $list) = perm_http($base . 'fabric_admin.php', $jar);
perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => perm_token($list), 'action' => 'save_node', 'id' => $nodes['fresh'], 'revision' => $node_row($nodes['fresh'])['revision'], 'host_id' => $created));
perm_expect($host_of($nodes['fresh']) === $created && $cached() === 1, 'admin: mapping the node again lists its port again');

// Deleting the device in Cacti clears the mapping through the device_remove hook.
include_once('./lib/api_device.php');
api_device_remove($created);
perm_expect($host_of($nodes['fresh']) === 0, 'deleting a mapped device in Cacti unmaps its node');


unlink($jar);

$jar = perm_login('admin');

foreach (array('fabric_admin.php', 'fabric_admin.php?action=edit_node&id=' . $nodes['open'], 'fabric.php?node=' . $nodes['open'], 'fabric_health.php') as $page) {
	list(, $body) = perm_http($base . $page, $jar);
	perm_expect(strpos($body, '<img src=x id="nodename') === false && strpos($body, '<b id="fabricprobe">') === false && preg_match('/(Fatal error|Parse error|Warning:|Uncaught)/', $body) !== 1, "$page loads and prints no stored markup");
}

// Descriptions are fabric-supplied, so every node label carries its GUID.
list(, $inventory) = perm_http($base . 'fabric.php', $jar);
list(, $list)      = perm_http($base . 'fabric_admin.php', $jar);
perm_expect(strpos($inventory, 'nodenameopen&quot;&gt; (' . $guids['open'] . ')') !== false, 'the inventory labels a node with its description and GUID');
perm_expect(strpos($list, 'nodenamenew&quot;&gt; (' . $guids['new'] . ')') !== false && strpos($list, 'nodenameunmapped&quot;&gt; (' . $guids['unmapped'] . ')') !== false, 'a re-link proposal labels both nodes with their description and GUID');

// A tool directory that fails the checks is not saved; a sound one is.
$tool_dir = function () {
	return (string) db_fetch_cell("SELECT value FROM settings WHERE name = 'fabric_tool_dir'");
};
list(, $form)  = perm_http($root . 'settings.php?tab=fabric', $jar);
list(, $saved) = perm_http($root . 'settings.php', $jar, array('__csrf_magic' => perm_token($form), 'action' => 'save', 'tab' => 'fabric', 'fabric_tool_dir' => '/tmp'));
perm_expect(strpos($saved, 'The Tool Directory was not saved') !== false && $tool_dir() !== '/tmp', 'a group- or world-writable tool directory is refused on save');
list(, $saved) = perm_http($root . 'settings.php', $jar, array('__csrf_magic' => perm_token($form), 'action' => 'save', 'tab' => 'fabric', 'fabric_tool_dir' => '/usr/sbin'));
perm_expect(strpos($saved, 'The Tool Directory was not saved') === false && $tool_dir() === '/usr/sbin', 'a root-owned tool directory closed to group and others is saved');

// A fabric left on a remote poller is flagged on the health page, and a
// save that keeps the remote poller is refused with a form error.
// A disabled remote poller, so the form refuses it for being remote rather
// than for being unknown.
db_execute("REPLACE INTO poller (id, name, hostname, disabled) VALUES (2, 'fabric-remote', 'remote.invalid', 'on')");
db_execute("INSERT INTO plugin_fabric_fabric (name, backend, poller_id, sweep_interval, adapter, adapter_port, admin_state) VALUES ('remote', 'diags', 2, 300, 'mlx5_1', 1, 'enabled')");
$remote = (int) db_fetch_insert_id();

list(, $health) = perm_http($base . 'fabric_health.php', $jar);
perm_expect(strpos($health, 'Fabric remote: it is assigned to poller 2') !== false, 'the health page warns about a fabric assigned to a remote poller');

list(, $list) = perm_http($base . 'fabric_admin.php', $jar);
list(, $saved) = perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => perm_token($list), 'action' => 'save_fabric', 'id' => $remote, 'name' => 'remote2', 'backend' => 'diags', 'poller_id' => 2, 'sweep_interval' => 300, 'adapter' => 'mlx5_1', 'adapter_port' => 1, 'enabled' => 'on'));
perm_expect(strpos($saved, 'only the main poller can sweep a fabric') !== false && (string) db_fetch_cell_prepared('SELECT name FROM plugin_fabric_fabric WHERE id = ?', array($remote)) === 'remote', 'saving a fabric on a remote poller is refused with a form error');

db_execute_prepared('DELETE FROM plugin_fabric_fabric WHERE id = ?', array($remote));
db_execute('DELETE FROM poller WHERE id = 2');

// A second fabric on the adapter port another fabric sweeps is refused by
// the form, and the table holds a unique key behind it.
list(, $list)  = perm_http($base . 'fabric_admin.php', $jar);
list(, $saved) = perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => perm_token($list), 'action' => 'save_fabric', 'id' => 0, 'name' => 'twin', 'backend' => 'diags', 'poller_id' => 1, 'sweep_interval' => 300, 'adapter' => 'mlx5_0', 'adapter_port' => 1, 'enabled' => 'on'));
perm_expect(strpos($saved, 'another fabric already sweeps this adapter port') !== false && (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_fabric_fabric WHERE name = 'twin'") === 0, 'a second fabric on the same adapter port is refused with a form error');
perm_expect((int) db_fetch_cell("SELECT COUNT(DISTINCT COLUMN_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_fabric_fabric' AND INDEX_NAME = 'poller_adapter_port' AND NON_UNIQUE = 0") === 3, 'the fabric table has a unique key on poller, adapter, and adapter port');

$state = function () use ($relink) {
	return (string) db_fetch_cell_prepared('SELECT state FROM plugin_fabric_relink WHERE id = ?', array($relink));
};

perm_http($base . 'fabric_admin.php?action=confirm_relink&id=' . $relink, $jar);
perm_expect($state() === 'proposed', 'a re-link confirmation sent as GET has no effect');

perm_http($base . 'fabric_admin.php', $jar, array('action' => 'confirm_relink', 'id' => $relink));
perm_expect($state() === 'proposed', 'a re-link confirmation POST without the csrf-magic token has no effect');

list(, $list) = perm_http($base . 'fabric_admin.php', $jar);
perm_expect(strpos($list, $guids['new']) !== false, 'the proposal is listed for confirmation');
// Map the missing node, then confirm: the mapping moves to the new node.
db_execute_prepared('UPDATE plugin_fabric_node SET host_id = ? WHERE id = ?', array($devices['hidden'], $nodes['unmapped']));
list(, $list) = perm_http($base . 'fabric_admin.php', $jar);
perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => perm_token($list), 'action' => 'confirm_relink', 'id' => $relink));
perm_expect($state() === 'confirmed' && (int) db_fetch_cell_prepared('SELECT host_id FROM plugin_fabric_node WHERE id = ?', array($nodes['new'])) === $devices['hidden'] && (int) db_fetch_cell_prepared('SELECT host_id FROM plugin_fabric_node WHERE id = ?', array($nodes['unmapped'])) === 0, 'the same confirmation as a POST with the token moves the device mapping');

$exists = function () use ($fabric) {
	return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_fabric_fabric WHERE id = ?', array($fabric)) === 1;
};

perm_http($base . 'fabric_admin.php?action=delete_fabric_confirm&id=' . $fabric, $jar);
perm_expect($exists(), 'a fabric delete sent as GET has no effect');

list(, $confirm) = perm_http($base . 'fabric_admin.php?action=delete_fabric&id=' . $fabric, $jar);
perm_expect(strpos($confirm, 'those devices, their data sources, and RRD files are kept') !== false && $exists(), 'the delete asks for confirmation first and deletes nothing');

// The export is Fabric Admin only and holds every record.
list($code, $export) = perm_http($base . 'fabric_admin.php?action=export', $jar);
$document = json_decode($export, true);
perm_expect($code === 200 && is_array($document) && count($document['fabrics']) === 1 && count($document['nodes']) === 5 && strpos($export, '<img') === false, 'Fabric Admin downloads every fabric and node record, with markup encoded');

// A running sweep's claim holds the delete off.
db_execute_prepared("REPLACE INTO plugin_fabric_claim (scope, claim_key, holder, expires_at) VALUES ('topology', ?, 'perm-check', ?)", array($fabric, time() + 600));
list(, $busy) = perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => perm_token($confirm), 'action' => 'delete_fabric_confirm', 'id' => $fabric));
perm_expect($exists() && strpos($busy, 'A sweep of this fabric is running') !== false && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_fabric_node WHERE fabric_id = ?', array($fabric)) === 5, 'a fabric delete is refused while a sweep of it holds a claim, and nothing is removed');
db_execute_prepared("UPDATE plugin_fabric_claim SET expires_at = 0 WHERE scope = 'topology' AND claim_key = ?", array($fabric));

perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => perm_token($confirm), 'action' => 'delete_fabric_confirm', 'id' => $fabric));
perm_expect(!$exists() && (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_fabric_node') === 0 && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host WHERE id IN (?, ?)', array($devices['open'], $devices['hidden'])) === 2, 'a confirmed delete removes the fabric and its nodes, and keeps the devices');

unlink($jar);

$jar = perm_login('viewer');
list(, $export) = perm_http($base . 'fabric_admin.php?action=export', $jar);
perm_expect(strpos($export, $guids['open']) === false, 'a Fabric View user cannot download the export');
unlink($jar);

exit($failures > 0 ? 1 : 0);
