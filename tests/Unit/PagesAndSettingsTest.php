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

use Somethingwithproof\Fabric\AdminPage;
use Somethingwithproof\Fabric\AdminState;
use Somethingwithproof\Fabric\Backend;
use Somethingwithproof\Fabric\CactiObjectRepository;
use Somethingwithproof\Fabric\DeviceAccess;
use Somethingwithproof\Fabric\DeviceProvisioner;
use Somethingwithproof\Fabric\Fabric;
use Somethingwithproof\Fabric\FabricHealthRepository;
use Somethingwithproof\Fabric\FabricRepository;
use Somethingwithproof\Fabric\FixedClock;
use Somethingwithproof\Fabric\Guid;
use Somethingwithproof\Fabric\HealthPage;
use Somethingwithproof\Fabric\HostRepository;
use Somethingwithproof\Fabric\IdentityState;
use Somethingwithproof\Fabric\InventoryPage;
use Somethingwithproof\Fabric\Node;
use Somethingwithproof\Fabric\NodeIdentity;
use Somethingwithproof\Fabric\NodeRepository;
use Somethingwithproof\Fabric\NodeSweepRepository;
use Somethingwithproof\Fabric\NodeType;
use Somethingwithproof\Fabric\OperatorPage;
use Somethingwithproof\Fabric\PollerRepository;
use Somethingwithproof\Fabric\PortReadingRepository;
use Somethingwithproof\Fabric\Presence;
use Somethingwithproof\Fabric\RelinkRepository;
use Somethingwithproof\Fabric\SampleRepository;
use Somethingwithproof\Fabric\Settings;
use Somethingwithproof\Fabric\TemplateInstaller;

beforeEach(function () {
	$GLOBALS['__fabric_db_exec']    = [];
	$GLOBALS['__fabric_db_rows']    = [];
	$GLOBALS['__fabric_db_fail']    = [];
	$GLOBALS['__fabric_log']        = [];
	$GLOBALS['__fabric_options']    = [];
	$GLOBALS['__fabric_insert_id']  = 0;
});

it('gives every fabric setting in Settings::form() a default that Settings::fromRow() agrees with', function () {
	$form = Settings::form();
	$row  = [];

	foreach (Settings::NAMES as $name) {
		expect($form)->toHaveKey($name);
		$row[$name] = $form[$name]['default'];
	}

	expect(Settings::fromRow($row))->toEqual(Settings::fromRow([]));
});

it('renders AdminPage list, forms, confirmations, conflicts, proposals, and JSON export safely', function () {
	$now   = 1800000000;
	$clock = new FixedClock($now);

	$fabricRow = [
		'id' => 1, 'name' => 'ib-core', 'backend' => 'diags', 'poller_id' => 1,
		'sweep_interval' => 300, 'adapter' => 'mlx5_0', 'adapter_port' => 1, 'admin_state' => 'enabled',
	];
	$nodeRow = [
		'id' => 10, 'fabric_id' => 1, 'guid' => '0x0002c90300a00000', 'node_type' => 'switch',
		'port_count' => 36, 'device_id' => 1, 'vendor_id' => 2, 'sysimg_guid' => '0x0002c90300a00000',
		'description' => 'sw01<script>', 'lid' => 5, 'host_id' => 42, 'presence' => 'present',
		'identity' => 'conflict', 'conflict_seen' => '{"node_type":"ca","port_count":2,"device_id":9,"vendor_id":2,"sysimg_guid":"0x0002c90300a00000"}',
		'first_seen' => $now - 600, 'last_seen' => $now - 10, 'revision' => 3,
	];
	$proposalRow = [
		'id' => 7, 'fabric_id' => 1, 'old_node_id' => 10, 'new_node_id' => 11,
		'neighbor_guid' => '0x0002c90300a00000', 'neighbor_port' => 2, 'proposed_at' => $now - 60,
		'old_guid' => '0x0002c90300b00001', 'old_description' => 'old-hca', 'old_host_id' => 42,
		'new_guid' => '0x0002c90300b00002', 'new_description' => 'new-hca', 'new_host_id' => 0,
	];

	$GLOBALS['__fabric_db_rows'] = [
		'FROM plugin_fabric_fabric' => [$fabricRow],
		'FROM plugin_fabric_node'   => [$nodeRow],
		'FROM plugin_fabric_relink' => [$proposalRow],
		'FROM poller'               => [['id' => 1, 'name' => 'Main Poller']],
		'FROM host'                 => [['id' => 42, 'description' => 'sw01-host', 'hostname' => 'sw01.example', 'poller_id' => 1, 'host_template_id' => 5]],
		'FROM `host_template`'      => [['id' => 5]],
		'FROM `snmp_query`'         => [['id' => 9]],
	];

	$fabrics     = new FabricRepository(false, $clock);
	$nodes       = new NodeRepository(false);
	$relinks     = new RelinkRepository(false, $clock);
	$pollers     = new PollerRepository(false);
	$hosts       = new HostRepository(false);
	$objects     = new CactiObjectRepository(false);
	$access      = new DeviceAccess(static fn (int $h): bool => $h === 42);
	$provisioner = new DeviceProvisioner($nodes, $fabrics, $objects, $access, '/var/www/cacti');
	$page        = new AdminPage($fabrics, $nodes, $relinks, $pollers, $hosts, $access, $provisioner, $objects, true);

	ob_start();
	$page->renderList();
	$listHtml = (string) ob_get_clean();

	expect($listHtml)->toContain('ib-core')
		->and($listHtml)->toContain('sw01&lt;script&gt;')
		->and($listHtml)->toContain('accept_conflict')
		->and($listHtml)->toContain('confirm_relink');

	$fabric = new Fabric(1, 'ib-core', Backend::Diags, 1, 300, 'mlx5_0', 1, AdminState::Enabled);
	$node   = new Node(
		10,
		1,
		Guid::fromString('0x0002c90300a00000'),
		NodeType::Switch,
		36,
		1,
		2,
		Guid::fromString('0x0002c90300a00000'),
		null,
		'sw01<script>',
		5,
		0,
		Presence::Present,
		IdentityState::Conflict,
		new NodeIdentity(NodeType::Switch, 2, 9, 2, Guid::fromString('0x0002c90300a00000')),
		$now - 600,
		$now - 10,
		3,
	);

	ob_start();
	$page->renderFabricEdit($fabric, ['name' => 'bad'], ['name' => 'Invalid name']);
	$page->renderFabricEdit(null);
	$page->renderDeleteConfirm($fabric);
	$page->renderNodeEdit($node);
	$page->renderCreateConfirm($node);
	$formsHtml = (string) ob_get_clean();

	expect($formsHtml)->toContain('Invalid name')
		->and($formsHtml)->toContain('delete_fabric_confirm')
		->and($formsHtml)->toContain('create_device_confirm');

	// Refusal path on create confirm when node is already mapped.
	$mappedNode = new Node(10, 1, Guid::fromString('0x0002c90300a00000'), NodeType::Switch, 36, 1, 2, null, null, 'sw01', 5, 42, Presence::Present, IdentityState::Ok, null, 0, 0, 1);
	ob_start();
	$page->renderCreateConfirm($mappedNode);
	expect((string) ob_get_clean())->toContain('already mapped');

	// Export escapes HTML tags safely.
	$json = $page->export('0.2.0', $now);
	expect($json)->not->toBeNull()
		->and((string) $json)->not->toContain('<script>')
		->and((string) $json)->toContain('sw01\u003Cscript\u003E');

	// Empty state renderList.
	$GLOBALS['__fabric_db_rows'] = [
		'FROM plugin_fabric_fabric' => [],
		'FROM plugin_fabric_node'   => [],
		'FROM plugin_fabric_relink' => [],
	];
	ob_start();
	$page->renderList();
	expect((string) ob_get_clean())->toContain('No fabrics defined yet.');

	// DB read failure renders error box and returns null export.
	$GLOBALS['__fabric_db_rows'] = ['FROM plugin_fabric_fabric' => false];
	ob_start();
	$page->renderList();
	expect((string) ob_get_clean())->toContain('could not be read')
		->and($page->export('0.2.0', $now))->toBeNull();
});

it('renders InventoryPage list, congestion ranking, node detail, graphs, and empty/error states', function () {
	$now   = 1800000000;
	$clock = new FixedClock($now);

	$GLOBALS['__fabric_db_rows'] = [
		'FROM plugin_fabric_fabric' => [[
			'id' => 1, 'name' => 'ib0', 'backend' => 'diags', 'poller_id' => 1,
			'sweep_interval' => 300, 'adapter' => 'mlx5_0', 'adapter_port' => 1, 'admin_state' => 'enabled',
		]],
		'FROM plugin_fabric_node' => [[
			'id' => 10, 'fabric_id' => 1, 'guid' => '0x0002c90300a00000', 'node_type' => 'switch',
			'port_count' => 36, 'device_id' => 1, 'vendor_id' => 2, 'sysimg_guid' => '0x0002c90300a00000',
			'description' => 'sw01', 'lid' => 5, 'host_id' => 42, 'presence' => 'present',
			'identity' => 'ok', 'first_seen' => $now - 600, 'last_seen' => $now - 10, 'revision' => 1,
		]],
		'FROM plugin_fabric_port' => [[
			'node_id' => 10, 'port_number' => 1, 'port_guid' => '0x0002c90300a00000', 'lid' => 5,
			'remote_guid' => '0x0002c90300b00010', 'remote_port' => 1, 'presence' => 'present',
			'logical_state' => 'active', 'phys_state' => 'linkup',
			'active_width' => 4, 'active_speed' => 'edr', 'best_width' => 4, 'best_speed' => 'edr', 'has_peer' => 1,
		]],
		'FROM plugin_fabric_sample' => [[
			'node_id' => 10, 'port_number' => 1, 'counter_group' => 'basic',
			'observed_at' => $now - 15, 'attempted_at' => $now - 15, 'backend' => 'diags',
			'cap_mask' => 0x1200, 'cap_mask2' => 0,
			'counters' => '{"PortXmitWait":"4294967295","PortXmitDiscards":"10"}',
			'rates' => '{"PortXmitWait":250.5,"PortXmitDiscards":1.25}',
			'reset' => '[]', 'discontinuities' => 2, 'discontinuity_at' => $now - 120, 'error' => '',
		]],
		'FROM `graph_templates`' => [['id' => 101]],
		'FROM graph_local'       => [['id' => 501, 'graph_template_id' => 101, 'snmp_index' => '0x0002c90300a00000:1']],
	];

	$page = new InventoryPage(
		new FabricRepository(false, $clock),
		new NodeRepository(false),
		new SampleRepository(false),
		$clock,
		static fn (int $h): bool => $h === 42,
		true,
		new PortReadingRepository(false, $clock),
		new CactiObjectRepository(false),
		static fn (int $g): bool => $g === 501,
	);

	ob_start();
	$page->renderList();
	$page->renderCongestion();
	$page->renderNode(10);
	$html = (string) ob_get_clean();

	expect($html)->toContain('sw01')
		->and($html)->toContain('251')
		->and($html)->toContain('discontinuities')
		->and($html)->toContain('local_graph_id=501');

	// Missing node / denied node path.
	$GLOBALS['__fabric_db_rows'] = ['FROM plugin_fabric_node' => []];
	ob_start();
	$page->renderNode(999);
	expect((string) ob_get_clean())->toContain('does not exist');

	// Empty fabrics & failure path.
	$GLOBALS['__fabric_db_rows'] = ['FROM plugin_fabric_fabric' => [], 'FROM plugin_fabric_node' => []];
	ob_start();
	$page->renderList();
	$page->renderCongestion();
	$emptyHtml = (string) ob_get_clean();
	expect($emptyHtml)->toContain('No fabrics are defined yet.')
		->and($emptyHtml)->toContain('No port has a current transmit wait rate.');

	$GLOBALS['__fabric_db_rows'] = ['FROM plugin_fabric_fabric' => false, 'FROM plugin_fabric_node' => false];
	ob_start();
	$page->renderList();
	$page->renderCongestion();
	expect((string) ob_get_clean())->toContain('could not be read');
});

it('renders HealthPage with objects, warnings, sweep failures, and error states', function () {
	$now   = 1800000000;
	$clock = new FixedClock($now);

	$GLOBALS['__fabric_db_rows'] = [
		'FROM plugin_fabric_fabric' => [
			['id' => 1, 'name' => 'ib-main', 'backend' => 'diags', 'poller_id' => 1, 'sweep_interval' => 300, 'adapter' => 'mlx5_0', 'adapter_port' => 1, 'admin_state' => 'enabled'],
			['id' => 2, 'name' => 'ib-remote', 'backend' => 'diags', 'poller_id' => 2, 'sweep_interval' => 300, 'adapter' => 'mlx5_1', 'adapter_port' => 1, 'admin_state' => 'disabled'],
		],
		'FROM plugin_fabric_health' => [[
			'fabric_id' => 1, 'discovery_attempt' => $now - 5000, 'discovery_success' => $now - 5000,
			'discovery_duration' => 1.5, 'discovery_error' => '', 'nodes_seen' => 4, 'ports_seen' => 16,
			'links_attempt' => $now - 5000, 'links_success' => $now - 5000, 'links_duration' => 0.4,
			'links_error' => '', 'links_unmatched' => 1, 'links_skipped' => 2, 'privilege' => 'not_root',
		]],
		'FROM plugin_fabric_node ' => [[
			'id' => 10, 'fabric_id' => 1, 'guid' => '0x0002c90300a00000', 'node_type' => 'switch',
			'port_count' => 36, 'host_id' => 0, 'presence' => 'present', 'identity' => 'conflict', 'description' => 'sw01',
		]],
		'FROM plugin_fabric_node_sweep' => [[
			'node_id' => 10, 'last_attempt' => $now - 30, 'last_success' => $now - 600,
			'duration' => 2.5, 'ports_total' => 36, 'ports_read' => 30, 'ports_failed' => 6,
			'ports_skipped' => 0, 'outcome' => 'partial', 'error' => 'timeout',
		]],
		'FROM plugin_fabric_relink' => [],
	];

	$templates = new TemplateInstaller(dirname(__DIR__, 2), '/nonexistent-cacti', new CactiObjectRepository(false));
	$page      = new HealthPage(
		new FabricRepository(false, $clock),
		new NodeRepository(false),
		new FabricHealthRepository(false),
		new NodeSweepRepository(false),
		new RelinkRepository(false, $clock),
		$clock,
		new DeviceAccess(static fn (int $h): bool => true),
		$templates,
		true,
		'/var/www/cacti',
		false,
	);

	ob_start();
	$page->render();
	$html = (string) ob_get_clean();

	expect($html)->toContain('ib-main')
		->and($html)->toContain('iblinkinfo lines could not be read')
		->and($html)->toContain('reinstall_templates')
		->and($html)->toContain('30 / 6 / 0')
		->and($html)->toContain('partial');

	// DB failure path.
	$GLOBALS['__fabric_db_rows'] = ['FROM plugin_fabric_fabric' => false];
	ob_start();
	$page->render();
	expect((string) ob_get_clean())->toContain('health records could not be read');
});

it('renders OperatorPage rows across fresh, acknowledged, stale, and empty states', function () {
	if (!function_exists('form_start')) {
		function form_start(string $action): void {
			print '<form action="' . $action . '">';
		}

		function form_end(bool $trailing = true): void {
			print '</form>';
		}
	}

	$now     = 1800000000;
	$row1    = ['id' => 'f:1', 'name' => 'ib0', 'state' => 'warn', 'observed' => $now - 10, 'interval' => 300, 'detail' => 'degraded'];
	$row2    = ['id' => 'f:2', 'name' => 'ib1', 'state' => 'warn', 'observed' => $now - 10, 'interval' => 300, 'detail' => 'stale'];
	$row3    = ['id' => 'f:3', 'name' => 'ib2', 'state' => 'unknown', 'observed' => 0, 'interval' => 300, 'detail' => 'never'];
	$session = [];

	OperatorPage::acknowledge($session, $row1, OperatorPage::fingerprint($row1), 5, $now);

	ob_start();
	OperatorPage::render([$row1, $row2, $row3], $session, 5, $now, ['refresh' => 'Refresh']);
	$html = (string) ob_get_clean();

	expect($html)->toContain('Acknowledged; health unchanged.')
		->and($html)->toContain('Acknowledge')
		->and($html)->toContain('Unknown or stale')
		->and($html)->toContain('Refresh');

	ob_start();
	OperatorPage::render([], [], 5, $now, []);
	expect((string) ob_get_clean())->toContain('No observations are available');
});

it('exercises Pages navigation, MaintMaintenance, ConfigCodec, NodeRepository, and HealthEvaluator/DoctorProvider UFM branches', function () {
	// Pages realms, menu, navigation, upgrade triggers.
	expect(Somethingwithproof\Fabric\Pages::realms())->toHaveCount(3)
		->and(Somethingwithproof\Fabric\Pages::menu())->toHaveKeys(['fabric.php', 'fabric_admin.php', 'fabric_health.php', 'fabric_operator.php'])
		->and(Somethingwithproof\Fabric\Pages::navigation())->toHaveKey('fabric.php:')
		->and(Somethingwithproof\Fabric\Pages::upgradeTriggers())->toContain('plugins.php', 'fabric.php');

	// MaintMaintenance handles invalid host IDs and absent Maint plugin cleanly.
	$maint = new Somethingwithproof\Fabric\MaintMaintenance();
	expect($maint->inMaintenance(0))->toBeFalse()
		->and($maint->inMaintenance(42))->toBeFalse()
		->and($maint->inMaintenance(42))->toBeFalse();

	// ConfigCodec format validation, file reading, stream reading, and error paths.
	expect(Somethingwithproof\Fabric\ConfigCodec::isFormat('yaml'))->toBeTrue()
		->and(Somethingwithproof\Fabric\ConfigCodec::isFormat('xml'))->toBeTrue()
		->and(Somethingwithproof\Fabric\ConfigCodec::isFormat('json'))->toBeFalse()
		->and(fn () => Somethingwithproof\Fabric\ConfigCodec::parse('json', '{}'))->toThrow(Somethingwithproof\Fabric\ConfigException::class)
		->and(fn () => Somethingwithproof\Fabric\ConfigCodec::write('json', []))->toThrow(Somethingwithproof\Fabric\ConfigException::class)
		->and(fn () => Somethingwithproof\Fabric\ConfigCodec::readFile('/nonexistent/fabric-config.yaml'))->toThrow(Somethingwithproof\Fabric\ConfigException::class);

	$stream = fopen('php://memory', 'r+');
	assert(is_resource($stream));
	fwrite($stream, "schema_version: 1\n");
	rewind($stream);
	expect(Somethingwithproof\Fabric\ConfigCodec::readStream($stream))->toBe("schema_version: 1\n");
	fclose($stream);

	$schemaPath = dirname(__DIR__, 2) . '/schema/fabric.schema.yaml';
	expect(Somethingwithproof\Fabric\ConfigCodec::readFile($schemaPath))->toContain('plugin: fabric');

	// NodeRepository helpers.
	$now   = 1800000000;
	$nodes = new NodeRepository(false);
	$node  = new Node(
		10, 1, Guid::fromString('0x0002c90300a00000'), NodeType::Switch, 36, 1, 2, null, null,
		'sw01', 5, 42, Presence::Present, IdentityState::Conflict,
		new NodeIdentity(NodeType::Switch, 36, 2, 2, null), $now - 600, $now - 10, 2, 'sig1',
	);
	$GLOBALS['__fabric_db_rows'] = [
		'FROM plugin_fabric_node' => [[
			'id' => 10, 'fabric_id' => 1, 'guid' => '0x0002c90300a00000', 'node_type' => 'switch',
			'port_count' => 36, 'host_id' => 42, 'presence' => 'present', 'identity' => 'ok', 'description' => 'sw01',
		]],
	];

	expect($nodes->forHost(42))->toHaveCount(1)
		->and($nodes->observations())->toHaveCount(1)
		->and($nodes->acceptConflict($node))->toBeTrue()
		->and($nodes->setHost($node, 99))->toBeTrue()
		->and($nodes->unmapHosts([]))->toBeTrue()
		->and($nodes->unmapHosts([42, 99]))->toBeTrue();

	// HealthEvaluator + DoctorProvider + AbilitiesProvider with degraded UFM fabric, errors, and conflicts.
	$clock                       = new FixedClock($now);
	$GLOBALS['__fabric_db_rows'] = [
		'FROM settings'                 => [['value' => '2']],
		'FROM plugin_fabric_fabric'     => [
			['id' => 1, 'name' => 'ufm-fail', 'backend' => 'ufm', 'poller_id' => 1, 'sweep_interval' => 300, 'adapter' => '', 'adapter_port' => 0, 'admin_state' => 'enabled', 'ufm_url' => 'https://ufm.example.org'],
			['id' => 2, 'name' => 'ib-stale', 'backend' => 'diags', 'poller_id' => 1, 'sweep_interval' => 300, 'adapter' => 'mlx5_0', 'adapter_port' => 1, 'admin_state' => 'enabled'],
		],
		'FROM plugin_fabric_health' => [[
			'fabric_id' => 1, 'discovery_attempt' => $now - 30, 'discovery_success' => 0,
			'discovery_error' => 'timeout', 'links_error' => 'parse',
		]],
		'FROM plugin_fabric_node ' => [[
			'id' => 10, 'fabric_id' => 1, 'guid' => '0x0002c90300a00000', 'node_type' => 'switch',
			'port_count' => 36, 'host_id' => 42, 'presence' => 'present', 'identity' => 'conflict', 'description' => 'sw01',
		]],
		'FROM plugin_fabric_node_sweep' => [['node_id' => 10, 'outcome' => 'failed']],
		'FROM plugin_fabric_port'       => [[
			'node_id' => 10, 'port_number' => 1, 'presence' => 'present',
			'logical_state' => 'active', 'phys_state' => 'linkup',
			'active_width' => 1, 'active_speed' => 'edr', 'best_width' => 4, 'best_speed' => 'edr', 'has_peer' => 1,
		]],
		'FROM plugin_fabric_sample' => [],
		'FROM plugin_fabric_relink' => [['id' => 1]],
	];

	$eval = new Somethingwithproof\Fabric\HealthEvaluator(
		new FabricRepository(false, $clock),
		new NodeRepository(false),
		new FabricHealthRepository(false),
		new NodeSweepRepository(false),
		new RelinkRepository(false, $clock),
		new SampleRepository(false),
		$clock,
		$maint,
		Settings::fromRow(['fabric_vault_key_file' => '/nonexistent/vault.key']),
		new Somethingwithproof\Fabric\Schema(false),
	);

	$report = $eval->evaluate();
	expect($report['status'])->toBe('fail')
		->and($report['warnings'])->not->toBeEmpty();

	$doctor = new Somethingwithproof\Fabric\DoctorProvider($eval);
	$checks = $doctor->checks();
	$debug  = $doctor->debugInfo();
	expect($checks[0]['status'])->toBe('fail')
		->and($checks[3]['status'])->toBe('warn')
		->and($debug['fields']['ufm_vault_status']['value'])->toBe('attention_required');

	// Execute fabric-list-fabrics and fabric-list-port-errors abilities.
	$reg = new class {
		/** @var array<string, array<string, mixed>> */
		public array $abilities = [];

		/** @param array<string, mixed> $def */
		public function register(string $id, array $def): void {
			$this->abilities[$id] = $def;
		}
	};
	(new Somethingwithproof\Fabric\AbilitiesProvider(
		$eval,
		static fn (int $u): bool => true,
		static fn (int $u, int $h): bool => $h === 42,
		static fn (int $u): bool => true,
	))->register($reg);

	$fabList = ($reg->abilities['fabric-list-fabrics']['execute'])(1, new stdClass());
	$errList = ($reg->abilities['fabric-list-port-errors']['execute'])(1, (object) ['fabricId' => 1, 'limit' => 10]);

	expect($fabList['fabrics'])->toHaveCount(2)
		->and($errList['ports'])->toHaveCount(1);
});
