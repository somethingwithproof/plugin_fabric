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

use Somethingwithproof\Fabric\AbilitiesProvider;
use Somethingwithproof\Fabric\CascProvider;
use Somethingwithproof\Fabric\ConfigCodec;
use Somethingwithproof\Fabric\ConfigRepository;
use Somethingwithproof\Fabric\ConfigService;
use Somethingwithproof\Fabric\CredentialRepository;
use Somethingwithproof\Fabric\DeviceMaintenance;
use Somethingwithproof\Fabric\DoctorProvider;
use Somethingwithproof\Fabric\FabricHealthRepository;
use Somethingwithproof\Fabric\FabricRepository;
use Somethingwithproof\Fabric\FixedClock;
use Somethingwithproof\Fabric\HealthEvaluator;
use Somethingwithproof\Fabric\NodeRepository;
use Somethingwithproof\Fabric\NodeSweepRepository;
use Somethingwithproof\Fabric\RelinkRepository;
use Somethingwithproof\Fabric\ReplicatorRepository;
use Somethingwithproof\Fabric\SampleRepository;
use Somethingwithproof\Fabric\Schema;
use Somethingwithproof\Fabric\Settings;
use Somethingwithproof\Fabric\SettingsRepository;

beforeEach(function () {
	$GLOBALS['__fabric_db_exec'] = [];
	$GLOBALS['__fabric_db_rows'] = [];
});

it('honors Cacti Maint maintenance windows in health evaluation and port error reporting', function () {
	$now = 1800000000;

	$GLOBALS['__fabric_db_rows'] = [
		'FROM settings'                 => [['value' => (string) Schema::VERSION]],
		'FROM plugin_fabric_fabric'     => [[
			'id' => 1, 'name' => 'ib0', 'backend' => 'diags', 'poller_id' => 1,
			'sweep_interval' => 300, 'adapter' => 'mlx5_0', 'adapter_port' => 1, 'admin_state' => 'enabled',
		]],
		'FROM plugin_fabric_health'     => [[
			'fabric_id' => 1, 'discovery_attempt' => $now - 30, 'discovery_success' => $now - 30,
			'discovery_error' => '', 'links_error' => '',
		]],
		'FROM plugin_fabric_node '      => [[
			'id' => 10, 'fabric_id' => 1, 'guid' => '0x0002c90300a00000', 'node_type' => 'switch',
			'port_count' => 36, 'host_id' => 42, 'presence' => 'present', 'identity' => 'ok', 'description' => 'sw01',
		]],
		'FROM plugin_fabric_node_sweep' => [['node_id' => 10, 'outcome' => 'failed']],
		'FROM plugin_fabric_port'       => [[
			'node_id' => 10, 'port_number' => 1, 'presence' => 'present',
			'logical_state' => 'active', 'phys_state' => 'linkup',
			'active_width' => 1, 'active_speed' => 'edr', 'best_width' => 4, 'best_speed' => 'edr', 'has_peer' => 1,
		]],
		'FROM plugin_fabric_sample'     => [],
		'FROM plugin_fabric_relink'     => [],
	];

	$maint = new class implements DeviceMaintenance {
		public function inMaintenance(int $hostId): bool {
			return $hostId === 42;
		}
	};

	$clock = new FixedClock($now);
	$eval  = new HealthEvaluator(
		new FabricRepository(false, $clock),
		new NodeRepository(false),
		new FabricHealthRepository(false),
		new NodeSweepRepository(false),
		new RelinkRepository(false, $clock),
		new SampleRepository(false),
		$clock,
		$maint,
		Settings::fromRow([]),
		new Schema(false),
	);

	$report = $eval->evaluate();
	expect($report['status'])->toBe('pass')
		->and($report['summary']['nodes_in_maintenance'])->toBe(1)
		->and($report['summary']['degraded_ports'])->toBe(0);

	$errors = $eval->portErrors(null, 50, static fn (int $h): bool => $h === 42, false);
	expect($errors)->toHaveCount(1)
		->and($errors[0]['inMaintenance'])->toBeTrue();
});

it('registers read-only MCP abilities gated by View realm and Doctor + CasC providers', function () {
	$clock = new FixedClock(1800000000);
	$GLOBALS['__fabric_db_rows'] = [
		'FROM settings' => [['value' => (string) Schema::VERSION]],
	];

	$maint = new class implements DeviceMaintenance {
		public function inMaintenance(int $hostId): bool {
			return false;
		}
	};

	$eval = new HealthEvaluator(
		new FabricRepository(false, $clock),
		new NodeRepository(false),
		new FabricHealthRepository(false),
		new NodeSweepRepository(false),
		new RelinkRepository(false, $clock),
		new SampleRepository(false),
		$clock,
		$maint,
		Settings::fromRow([]),
		new Schema(false),
	);

	$registrar = new class {
		/** @var array<string, string> */
		public array $categories = [];
		/** @var array<string, array<string, mixed>> */
		public array $abilities = [];

		public function registerCategory(string $id, string $label): void {
			$this->categories[$id] = $label;
		}

		/** @param array<string, mixed> $def */
		public function register(string $id, array $def): void {
			$this->abilities[$id] = $def;
		}
	};

	$provider = new AbilitiesProvider(
		$eval,
		static fn (int $userId): bool => $userId === 5,
		static fn (int $userId, int $hostId): bool => $userId === 5,
		static fn (int $userId): bool => false,
	);
	$provider->register($registrar);

	expect(array_keys($registrar->abilities))->toBe(['fabric-list-fabrics', 'fabric-get-health', 'fabric-list-port-errors'])
		->and($registrar->categories)->toBe(['fabric' => 'InfiniBand Fabric']);

	foreach ($registrar->abilities as $id => $def) {
		expect(array_keys($def))->toBe(['label', 'description', 'category', 'input_schema', 'output_schema', 'annotations', 'permission', 'execute'])
			->and($def['annotations'])->toBe(['readOnly' => true, 'destructive' => false, 'idempotent' => true])
			->and(($def['permission'])(5))->toBeTrue($id)
			->and(($def['permission'])(99))->toBeFalse($id);
	}

	$healthResult = ($registrar->abilities['fabric-get-health']['execute'])(5, new stdClass());
	expect($healthResult['status'])->toBe('pass')
		->and($healthResult['schemaCurrent'])->toBeTrue();

	// Doctor checks
	$checks = (new DoctorProvider($eval))->checks();
	expect(array_column($checks, 'id'))->toBe(['fabric.schema', 'fabric.poller_freshness', 'fabric.identity_conflicts', 'fabric.ufm_vault']);

	// CasC provider
	$casc = (new CascProvider(new ConfigService(
		new SettingsRepository(false),
		new FabricRepository(false, $clock),
		new CredentialRepository(false, $clock),
		new ConfigRepository(false),
	)))->descriptor();

	expect($casc['plugin'])->toBe('fabric')
		->and($casc['formats'])->toBe([ConfigCodec::YAML, ConfigCodec::XML])
		->and(($casc['export'])(ConfigCodec::YAML))->toContain('schema_version: 1');

	// Replicator never includes vault key file in non-secret settings.
	$rep = new ReplicatorRepository(false);
	expect($rep->fabricsForPoller(1))->toBe([]);
});
