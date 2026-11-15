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
$guids   = array('open' => '0x0002c90300b00010', 'hidden' => '0x0002c90300b00020', 'unmapped' => '0x0002c90300b00030', 'new' => '0x0002c90300b00040');

foreach ($guids as $key => $guid) {
	$host = 0;

	if ($key === 'open' || $key === 'hidden') {
		db_execute_prepared('INSERT INTO host (description, hostname) VALUES (?, ?)', array($key === 'open' ? '<b id="fabricprobe">Open node</b>' : 'Hidden node', $key . '.invalid'));
		$host = $devices[$key] = (int) db_fetch_insert_id();
	}

	// The unmapped node went missing; "new" replaced it on the same switch port.
	db_execute_prepared("INSERT INTO plugin_fabric_node (fabric_id, guid, node_type, port_count, description, host_id, presence, identity) VALUES (?, ?, 'ca', 1, ?, ?, ?, 'ok')", array($fabric, $guid, '<img src=x id="nodename' . $key . '">', $host, $key === 'unmapped' ? 'missing' : 'present'));
	$nodes[$key] = (int) db_fetch_insert_id();
}

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

unlink($jar);

$jar = perm_login('admin');

foreach (array('fabric_admin.php', 'fabric_admin.php?action=edit_node&id=' . $nodes['open'], 'fabric.php?node=' . $nodes['open'], 'fabric_health.php') as $page) {
	list(, $body) = perm_http($base . $page, $jar);
	perm_expect(strpos($body, '<img src=x id="nodename') === false && strpos($body, '<b id="fabricprobe">') === false && preg_match('/(Fatal error|Parse error|Warning:|Uncaught)/', $body) !== 1, "$page loads and prints no stored markup");
}

// A fabric left on a remote poller is flagged on the health page, and a
// save that keeps the remote poller is refused with a form error.
db_execute("INSERT INTO plugin_fabric_fabric (name, backend, poller_id, sweep_interval, adapter, adapter_port, admin_state) VALUES ('remote', 'diags', 2, 300, 'mlx5_1', 1, 'enabled')");
$remote = (int) db_fetch_insert_id();

list(, $health) = perm_http($base . 'fabric_health.php', $jar);
perm_expect(strpos($health, 'Fabric remote: it is assigned to poller 2') !== false, 'the health page warns about a fabric assigned to a remote poller');

list(, $list) = perm_http($base . 'fabric_admin.php', $jar);
list(, $saved) = perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => perm_token($list), 'action' => 'save_fabric', 'id' => $remote, 'name' => 'remote2', 'backend' => 'diags', 'poller_id' => 2, 'sweep_interval' => 300, 'adapter' => 'mlx5_1', 'adapter_port' => 1, 'enabled' => 'on'));
perm_expect(strpos($saved, 'only the main poller can sweep a fabric') !== false && (string) db_fetch_cell_prepared('SELECT name FROM plugin_fabric_fabric WHERE id = ?', array($remote)) === 'remote', 'saving a fabric on a remote poller is refused with a form error');

db_execute_prepared('DELETE FROM plugin_fabric_fabric WHERE id = ?', array($remote));

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
perm_expect($code === 200 && is_array($document) && count($document['fabrics']) === 1 && count($document['nodes']) === 4 && strpos($export, '<img') === false, 'Fabric Admin downloads every fabric and node record, with markup encoded');

perm_http($base . 'fabric_admin.php', $jar, array('__csrf_magic' => perm_token($confirm), 'action' => 'delete_fabric_confirm', 'id' => $fabric));
perm_expect(!$exists() && (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_fabric_node') === 0 && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host WHERE id IN (?, ?)', array($devices['open'], $devices['hidden'])) === 2, 'a confirmed delete removes the fabric and its nodes, and keeps the devices');

unlink($jar);

$jar = perm_login('viewer');
list(, $export) = perm_http($base . 'fabric_admin.php?action=export', $jar);
perm_expect(strpos($export, $guids['open']) === false, 'a Fabric View user cannot download the export');
unlink($jar);

exit($failures > 0 ? 1 : 0);
