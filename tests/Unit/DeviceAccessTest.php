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
use Somethingwithproof\Fabric\DeviceAccess;
use Somethingwithproof\Fabric\FabricRepository;
use Somethingwithproof\Fabric\FixedClock;
use Somethingwithproof\Fabric\HostRepository;
use Somethingwithproof\Fabric\Node;
use Somethingwithproof\Fabric\NodeRepository;
use Somethingwithproof\Fabric\PollerRepository;
use Somethingwithproof\Fabric\RelinkRepository;

/*
 * The realm is not a device grant: a Fabric Admin user with restricted
 * device permissions changes and sees only records on devices it may see.
 */

function fabric_access_node(int $id, int $fabric, int $host): Node {
	$node = Node::fromRow(['id' => $id, 'fabric_id' => $fabric, 'guid' => sprintf('0x0002c90300b%05x', $id), 'node_type' => 'ca', 'host_id' => $host, 'presence' => 'present', 'identity' => 'ok']);
	assert($node !== null);

	return $node;
}

function fabric_access(): DeviceAccess {
	return new DeviceAccess(static fn (int $host): bool => $host === 10);
}

it('allows unmapped nodes and nodes on permitted devices only', function () {
	$access = fabric_access();

	expect($access->node(fabric_access_node(1, 1, 0)))->toBeTrue()
		->and($access->node(fabric_access_node(2, 1, 10)))->toBeTrue()
		->and($access->node(fabric_access_node(3, 1, 11)))->toBeFalse()
		->and($access->host(0))->toBeTrue()
		->and($access->host(11))->toBeFalse();
});

it('allows a fabric only when every device mapped in it is permitted', function () {
	$nodes = [fabric_access_node(1, 1, 10), fabric_access_node(2, 1, 0), fabric_access_node(3, 2, 11)];

	expect(fabric_access()->fabric(1, $nodes))->toBeTrue()
		->and(fabric_access()->fabric(2, $nodes))->toBeFalse()
		->and(fabric_access()->fabric(3, $nodes))->toBeTrue()
		->and(array_map(static fn (Node $n): int => $n->id, fabric_access()->visible($nodes)))->toBe([1, 2]);
});

it('leaves nodes on hidden devices out of the export', function () {
	$GLOBALS['__fabric_db_rows'] = [
		'FROM plugin_fabric_fabric' => [['id' => 1, 'name' => 'ib0', 'backend' => 'diags', 'poller_id' => 1, 'sweep_interval' => 300, 'adapter' => 'mlx5_0', 'adapter_port' => 1, 'admin_state' => 'enabled']],
		'FROM plugin_fabric_node'   => [
			['id' => 1, 'fabric_id' => 1, 'guid' => '0x0002c90300b00010', 'node_type' => 'ca', 'host_id' => 10, 'presence' => 'present', 'identity' => 'ok'],
			['id' => 2, 'fabric_id' => 1, 'guid' => '0x0002c90300b00020', 'node_type' => 'ca', 'host_id' => 11, 'presence' => 'present', 'identity' => 'ok'],
		],
	];

	$clock = new FixedClock(1_800_000_000);
	$page  = new AdminPage(new FabricRepository(false, $clock), new NodeRepository(false), new RelinkRepository(false, $clock), new PollerRepository(false), new HostRepository(false), fabric_access());
	$json  = (string) $page->export('0.1.0-dev', $clock->now());

	expect($json)->toContain('0x0002c90300b00010')
		->and($json)->not->toContain('0x0002c90300b00020')
		->and(json_decode($json, true)['fabrics'])->toHaveCount(1);

	$GLOBALS['__fabric_db_rows'] = [];
});
