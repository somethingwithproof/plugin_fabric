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

// The version INFO declares, which Cacti records as the installed version.
define('FABRIC_VERSION', (string) (parse_ini_file(dirname(__DIR__, 2) . '/INFO', true)['info']['version'] ?? ''));

const FABRIC_TABLES = ['plugin_fabric_claim', 'plugin_fabric_fabric', 'plugin_fabric_health', 'plugin_fabric_node', 'plugin_fabric_node_sweep', 'plugin_fabric_port', 'plugin_fabric_relink', 'plugin_fabric_sample'];

// What the seed action leaves: one fabric and one node.
const FABRIC_SEEDED = ['plugin_fabric_claim' => 0, 'plugin_fabric_fabric' => 1, 'plugin_fabric_health' => 0, 'plugin_fabric_node' => 1, 'plugin_fabric_node_sweep' => 0, 'plugin_fabric_port' => 0, 'plugin_fabric_relink' => 0, 'plugin_fabric_sample' => 0];

const FABRIC_HOOKS = ['config_arrays', 'config_settings', 'draw_navigation_text'];

beforeEach(function () {
	lifecycle('reset');
});

afterAll(function () {
	lifecycle('reset');
});

it('installs fresh with tracked tables, hooks, and both realms, leaving the plugin disabled', function () {
	$state = lifecycle('install');

	expect($state['plugin']['status'])->toEqual('4')
		->and($state['plugin']['version'])->toEqual(FABRIC_VERSION)
		->and($state['schema'])->toEqual('1')
		->and(array_keys($state['tables']))->toBe(FABRIC_TABLES)
		->and($state['tracked'])->toBe(FABRIC_TABLES)
		->and(array_keys($state['hooks']))->toBe(FABRIC_HOOKS)
		->and($state['realms'])->toEqual([
			'fabric.php'                         => 'Fabric View',
			'fabric_admin.php,fabric_health.php' => 'Fabric Admin',
		])
		->and($state['admin'])->toBe(2);
});

it('enables and disables through core, keeping records on disable', function () {
	lifecycle('install');
	lifecycle('seed');

	$enabled = lifecycle('enable');
	expect($enabled['plugin']['status'])->toEqual('1')
		->and(array_values(array_unique(array_map('strval', $enabled['hooks']))))->toBe(['1']);

	$disabled = lifecycle('disable');
	expect($disabled['plugin']['status'])->toEqual('4')
		->and($disabled['tables'])->toBe(FABRIC_SEEDED);
});

it('upgrades from an older recorded version and is idempotent on repeated runs', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('set_version', '0.0');

	$first = lifecycle('upgrade');
	expect($first['result'])->toBeTrue()
		->and($first['plugin']['version'])->toEqual(FABRIC_VERSION);

	foreach ([lifecycle('upgrade'), lifecycle('upgrade')] as $run) {
		expect($run['result'])->toBeTrue()
			->and($run['tables'])->toBe($first['tables'])
			->and($run['hooks'])->toBe($first['hooks'])
			->and($run['realms'])->toBe($first['realms'])
			->and($run['tracked'])->toBe(FABRIC_TABLES)
			->and($run['plugin'])->toBe($first['plugin']);
	}

	expect($first['tables'])->toBe(FABRIC_SEEDED);
});

it('re-registers hooks and realms that went missing', function () {
	lifecycle('install');

	$missing = lifecycle('drop_registrations');
	expect($missing['hooks'])->toBe([]);

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and(array_keys($state['hooks']))->toBe(FABRIC_HOOKS)
		->and($state['realms'])->toHaveCount(2);
});

it('makes a hook repaired while the plugin is enabled active, and one repaired while disabled not', function () {
	lifecycle('install');
	lifecycle('enable');
	lifecycle('drop_hook', 'draw_navigation_text');

	$enabled = lifecycle('upgrade');
	expect($enabled['hooks'])->toEqual(array_fill_keys(FABRIC_HOOKS, '1'));

	lifecycle('disable');
	lifecycle('drop_hook', 'draw_navigation_text');

	$disabled = lifecycle('upgrade');
	expect($disabled['hooks']['draw_navigation_text'])->toEqual('0');
});

it('finishes an upgrade that was interrupted after creating only some tables', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('drop_table', 'plugin_fabric_sample');
	lifecycle('set_schema', '0');
	lifecycle('set_version', '0.0');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and(array_keys($state['tables']))->toBe(FABRIC_TABLES)
		->and($state['tables']['plugin_fabric_node'])->toBe(1)
		->and($state['schema'])->toEqual('1')
		->and($state['plugin']['version'])->toEqual(FABRIC_VERSION);
});

it('does not advance past a migration that did not run', function () {
	lifecycle('install');
	lifecycle('set_schema', '0');

	$stopped = lifecycle('migrate_through', '0');
	expect($stopped['result'])->toBeTrue()
		->and($stopped['schema'])->toEqual('0');

	$finished = lifecycle('upgrade');
	expect($finished['schema'])->toEqual('1');
});

it('refuses a schema newer than the code and leaves the recorded version alone', function () {
	lifecycle('install');
	lifecycle('set_schema', '99');
	lifecycle('set_version', '0.0');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeFalse()
		->and($state['plugin']['version'])->toEqual('0.0')
		->and($state['schema'])->toEqual('99');
});

it('keeps tables on uninstall by default, and drops them only when the box was cleared', function () {
	lifecycle('install');
	lifecycle('seed');

	$kept = lifecycle('uninstall');
	expect(array_keys($kept['tables']))->toBe(FABRIC_TABLES)
		->and($kept['tables']['plugin_fabric_fabric'])->toBe(1)
		->and($kept['tracked'])->toBe([])
		->and($kept['hooks'])->toBe([])
		->and($kept['realms'])->toBe([]);

	// A reinstall reuses the kept tables and their rows.
	$reinstalled = lifecycle('install');
	expect($reinstalled['plugin']['status'])->toEqual('4')
		->and($reinstalled['tables']['plugin_fabric_fabric'])->toBe(1)
		->and($reinstalled['tracked'])->toBe(FABRIC_TABLES);

	lifecycle('set_setting', 'fabric_uninstall_keep_tables=');

	$dropped = lifecycle('uninstall');
	expect($dropped['plugin'])->toBeEmpty()
		->and($dropped['tables'])->toBe([])
		->and($dropped['schema'])->toBeEmpty();
});

it('honours keep tables on uninstall when the runtime is refused', function () {
	lifecycle('install');
	lifecycle('seed');

	$kept = lifecycle('uninstall_unbooted');
	expect($kept['tables']['plugin_fabric_fabric'])->toBe(1)
		->and($kept['tracked'])->toBe([]);

	lifecycle('reset');
	lifecycle('install');
	lifecycle('set_setting', 'fabric_uninstall_keep_tables=');

	$dropped = lifecycle('uninstall_unbooted');
	expect($dropped['tables'])->toBe([])
		->and($dropped['schema'])->toBeEmpty();
});

it('grants realms on install but never on a repair a viewer triggers', function () {
	expect(lifecycle('install')['admin'])->toBe(2);

	$repair = lifecycle('repair_as_viewer');
	expect($repair['realms'])->toHaveCount(2)
		->and($repair['result'])->toBe(['viewer_realms' => 0]);
});

it('refuses to sweep a fabric assigned to a remote poller, with a logged reason', function () {
	lifecycle('install');
	lifecycle('enable');

	$r = lifecycle('remote_poller')['result'];

	expect($r['status'])->toBe(1)
		->and($r['stderr'])->toContain('Only the main poller sweeps a fabric')
		->and($r['stdout'])->toBe('')
		->and($r['logged'])->toBeTrue()
		->and($r['health'])->toBe(0);
});

it('sweeps a fake fabric into the real schema and keeps the last good inventory on broken output', function () {
	lifecycle('install');

	$r      = lifecycle('sweep_flow')['result'];
	$import = $r['steps']['import'];

	// 4 adapters, one switch: 5 nodes. Ports: the switch's 36, which
	// iblinkinfo reports under its port 0 GUID, and each adapter's linked
	// port. An adapter port without a link has no port GUID in the
	// discovery, so its link record stays unmatched.
	expect($import['error'])->toBe('')
		->and($import['nodes'])->toHaveCount(5)
		->and(array_unique(array_values($import['nodes'])))->toBe(['present/ok'])
		->and($import['ports'])->toBe(36 + 4)
		->and($import['below_best'])->toBe(2)
		->and($import['swept'])->toBe(5)
		->and($import['samples'])->toBeGreaterThan(0)
		->and($r['tools'])->toBe(['ibnetdiscover', 'iblinkinfo', 'perfquery'])
		->and($r['resets'])->toBe(0);

	// REQ-INV-002: an identity attribute changed, so a conflict, never a silent update.
	expect($r['steps']['conflict']['nodes'][$r['conflicted']])->toBe('present/conflict')
		->and($r['steps']['conflict']['error'])->toBe('');

	// REQ-INV-003: a new adapter on the vacated switch port is proposed, never merged.
	$replaced = $r['steps']['replaced'];

	expect($replaced['nodes'][$r['old']])->toBe('missing/ok')
		->and($replaced['nodes'][$r['new']])->toBe('present/ok')
		->and($replaced['proposals'])->toBe(1);

	// REQ-INV-004: output cut short, garbage, or a failed run changes nothing.
	foreach (['truncate' => 'parse', 'garbage' => 'parse', 'exit' => 'exit_status'] as $mode => $error) {
		expect($r['steps'][$mode]['error'])->toBe($error, $mode)
			->and($r['steps'][$mode]['nodes'])->toBe($replaced['nodes'], $mode)
			->and($r['steps'][$mode]['ports'])->toBe($replaced['ports'], $mode)
			->and($r['steps'][$mode]['proposals'])->toBe(1, $mode);
	}

	// A description that imitates the tool output skips only its own lines.
	expect($r['steps']['hostile']['error'])->toBe('')
		->and($r['steps']['hostile']['links_error'])->toBe('')
		->and($r['steps']['hostile']['links_skipped'])->toBe(2)
		->and($r['steps']['hostile']['kept'])->toBeTrue();
});
