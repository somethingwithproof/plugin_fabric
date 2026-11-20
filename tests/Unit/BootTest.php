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

use Somethingwithproof\Fabric\Log;

beforeEach(function () {
	$GLOBALS['__fabric_log'] = [];
});

it('reads its metadata from INFO', function () {
	$info = plugin_fabric_version();

	expect($info['name'])->toBe('fabric')
		->and($info['compat'])->toBe('1.2.31')
		// REQ-LIFE-006: remote collectors sweep their own fabrics.
		->and($info['capabilities'])->toContain('remote_collect:1');
});

it('boots and loads plugin classes on a supported runtime', function () {
	expect(fabric_php_supported())->toBeTrue()
		->and(fabric_boot())->toBeTrue()
		->and(class_exists(Log::class))->toBeTrue();
});

it('reserves reply time even when the configured script timeout is short', function (string $timeout, float $budget) {
	$GLOBALS['__fabric_options']['script_timeout'] = $timeout;
	try {
		expect(fabric_script_sweep_seconds())->toBe($budget);
	} finally {
		unset($GLOBALS['__fabric_options']['script_timeout']);
	}
})->with([['', 20.0], ['25', 20.0], ['10', 5.0], ['5', 0.0], ['1', 0.0]]);

it('does not refuse or log on a supported runtime', function () {
	expect(fabric_refuse_unsupported())->toBeFalse()
		->and($GLOBALS['__fabric_log'])->toBe([]);
});

it('ignores classes outside its namespace and nested names', function () {
	expect(class_exists('Somethingwithproof\\Fabric\\Nested\\Log'))->toBeFalse()
		->and(class_exists('Somethingwithproof\\FabricLog'))->toBeFalse();
});

it('logs under the FABRIC tag at the right verbosity', function () {
	Log::debug('d');
	Log::info('i');
	Log::warning('w');
	Log::error('e');

	expect($GLOBALS['__fabric_log'])->toBe([
		['message' => 'DEBUG: d', 'environ' => 'FABRIC', 'level' => POLLER_VERBOSITY_DEBUG],
		['message' => 'NOTE: i', 'environ' => 'FABRIC', 'level' => POLLER_VERBOSITY_MEDIUM],
		['message' => 'WARNING: w', 'environ' => 'FABRIC', 'level' => ''],
		['message' => 'ERROR: e', 'environ' => 'FABRIC', 'level' => ''],
	]);
});

it('cuts untrusted text to one bounded printable line', function () {
	expect(Log::excerpt("ibwarn: \x1b[31mbad\nline\0two"))->toBe('ibwarn: [31mbad line two')
		->and(strlen(Log::excerpt(str_repeat('a', 500))))->toBe(203);
});

it('registers no poller_bottom hook: sweeps start lazily (ADR 0008)', function () {
	expect(Somethingwithproof\Fabric\Schema::HOOKS)->toBe([
		'auth_alternate_realms' => 'fabric_auth_alternate_realms',
		'config_arrays'        => 'fabric_config_arrays',
		'config_settings'      => 'fabric_config_settings',
		'draw_navigation_text' => 'fabric_draw_navigation_text',
		'device_remove'        => 'fabric_device_remove',
	]);
});

// core fires config_arrays before auth.php checks the session
it('repairs the install from config_arrays only for a request that carries a user', function () {
	$saved = $_SESSION ?? null;

	try {
		$_SESSION = [];
		expect(fabric_repair_on_page('plugins.php'))->toBeFalse()
			->and(fabric_repair_on_page('fabric.php'))->toBeFalse();

		$_SESSION = ['sess_user_id' => 3];
		expect(fabric_repair_on_page('plugins.php'))->toBeTrue()
			->and(fabric_repair_on_page('fabric.php'))->toBeTrue()
			->and(fabric_repair_on_page('graphs.php'))->toBeFalse();
	} finally {
		if ($saved === null) {
			unset($_SESSION);
		} else {
			$_SESSION = $saved;
		}
	}
});
