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

use Somethingwithproof\Fabric\ConfigCodec;
use Somethingwithproof\Fabric\ConfigException;
use Somethingwithproof\Fabric\ConfigRepository;
use Somethingwithproof\Fabric\ConfigService;
use Somethingwithproof\Fabric\CredentialRepository;
use Somethingwithproof\Fabric\FabricRepository;
use Somethingwithproof\Fabric\FixedClock;
use Somethingwithproof\Fabric\SettingsRepository;

beforeEach(function () {
	$GLOBALS['__fabric_db_exec'] = [];
	$GLOBALS['__fabric_db_rows'] = [];
	$GLOBALS['__fabric_db_fail'] = [];
});

it('exports YAML and XML with vault keyfile and UFM credential secrets redacted', function () {
	$GLOBALS['__fabric_db_rows'] = [
		'FROM settings'             => [
			['name' => 'fabric_tool_dir', 'value' => '/usr/sbin'],
			['name' => 'fabric_vault_key_file', 'value' => '/etc/cacti/fabric-vault.key'],
		],
		'FROM plugin_fabric_secret' => [
			['id' => 3, 'name' => 'ufm-ro', 'auth_type' => 'token', 'username' => '', 'key_id' => 'abcdef0123456789', 'updated_at' => 1000],
		],
		'FROM plugin_fabric_fabric' => [
			[
				'id'                => 1,
				'name'              => 'ib-ufm',
				'backend'           => 'ufm',
				'poller_id'         => 2,
				'sweep_interval'    => 300,
				'adapter'           => '',
				'adapter_port'      => 0,
				'admin_state'       => 'enabled',
				'ufm_url'           => 'https://ufm.example.org',
				'ufm_ca_file'       => '/etc/ssl/ufm-ca.pem',
				'ufm_spki_pin'      => '',
				'ufm_credential_id' => 3,
			],
		],
	];

	$clock   = new FixedClock(1800000000);
	$service = new ConfigService(
		new SettingsRepository(false),
		new FabricRepository(false, $clock),
		new CredentialRepository(false, $clock),
		new ConfigRepository(false),
	);

	$yaml = $service->export(ConfigCodec::YAML);
	$xml  = $service->export(ConfigCodec::XML);

	expect($yaml)->toContain('schema_version: 1')
		->and($yaml)->toContain('[REDACTED]')
		->and($yaml)->toContain('ufm_credential_ref: ufm-ro')
		->and($yaml)->not->toContain('/etc/cacti/fabric-vault.key')
		->and($xml)->toContain('<fabric-config schema_version="1">')
		->and($xml)->toContain('[REDACTED]')
		->and($xml)->not->toContain('/etc/cacti/fabric-vault.key');

	// Both exported formats round-trip cleanly with zero validation errors.
	expect($service->validate(ConfigCodec::parse(ConfigCodec::YAML, $yaml)))->toBe([])
		->and($service->validate(ConfigCodec::parse(ConfigCodec::XML, $xml)))->toBe([]);
});

it('rejects XXE DOCTYPE declarations in XML and inline secrets in YAML', function () {
	expect(fn () => ConfigCodec::parse(ConfigCodec::XML, '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><fabric-config schema_version="1"/>'))
		->toThrow(ConfigException::class);

	$clock   = new FixedClock(1800000000);
	$service = new ConfigService(
		new SettingsRepository(false),
		new FabricRepository(false, $clock),
		new CredentialRepository(false, $clock),
		new ConfigRepository(false),
	);

	$yaml = "schema_version: 1\nfabrics:\n  - name: bad\n    backend: ufm\n    ufm_url: 'https://ufm.example.org'\n    ufm_token: leaked\n";
	$errs = $service->validate(ConfigCodec::parse(ConfigCodec::YAML, $yaml));

	expect($errs)->not->toBeEmpty()
		->and($errs[0])->toContain('inline secrets are forbidden');
});

it('plans deterministic diffs and enforces confirmation hash on apply', function () {
	$clock   = new FixedClock(1800000000);
	$service = new ConfigService(
		new SettingsRepository(false),
		new FabricRepository(false, $clock),
		new CredentialRepository(false, $clock),
		new ConfigRepository(false),
	);

	$yaml = "schema_version: 1\nsettings:\n  fabric_command_timeout: 90\nfabrics:\n  - name: compute-ib\n    backend: diags\n    poller_id: 2\n    sweep_interval: 300\n    adapter: mlx5_0\n    adapter_port: 1\n    admin_state: enabled\n";
	$tree = ConfigCodec::parse(ConfigCodec::YAML, $yaml);
	$plan = $service->plan($tree);

	expect($plan->hasErrors())->toBeFalse()
		->and($plan->isEmpty())->toBeFalse()
		->and(strlen($plan->id()))->toBe(16)
		->and($plan->settingsChanges)->toBe(['fabric_command_timeout' => '90'])
		->and($plan->fabricChanges)->toHaveCount(1);

	// Wrong confirmation hash refuses without writing.
	expect($service->apply($tree, 'wronghash'))->not->toBeEmpty()
		->and($GLOBALS['__fabric_db_exec'])->toBe([]);

	// Exact confirmation hash applies settings and fabric.
	expect($service->apply($tree, $plan->id()))->toBe([])
		->and($GLOBALS['__fabric_db_exec'])->not->toBeEmpty();
});
