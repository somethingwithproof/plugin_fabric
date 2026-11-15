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

use Somethingwithproof\Fabric\AdminState;
use Somethingwithproof\Fabric\Backend;
use Somethingwithproof\Fabric\Counters;
use Somethingwithproof\Fabric\FixedClock;
use Somethingwithproof\Fabric\Fabric;
use Somethingwithproof\Fabric\FabricInput;
use Somethingwithproof\Fabric\Guid;
use Somethingwithproof\Fabric\IdentityState;
use Somethingwithproof\Fabric\LinkSpeed;
use Somethingwithproof\Fabric\LinkState;
use Somethingwithproof\Fabric\LinkWidth;
use Somethingwithproof\Fabric\Node;
use Somethingwithproof\Fabric\NodeIdentity;
use Somethingwithproof\Fabric\NodeType;
use Somethingwithproof\Fabric\Port;
use Somethingwithproof\Fabric\PortRef;
use Somethingwithproof\Fabric\Presence;
use Somethingwithproof\Fabric\ProcRunner;
use Somethingwithproof\Fabric\Settings;
use Somethingwithproof\Fabric\Sweep;

// REQ-CODE-010
it('normalizes every GUID form to one canonical text', function (string $input) {
	expect(Guid::fromString($input)->text)->toBe('0x0002c90300a1b2c3');
})->with(['0x0002c90300a1b2c3', '0X0002C90300A1B2C3', '0002c90300a1b2c3', '2c90300a1b2c3', '0x2c90300a1b2c3']);

it('rejects anything that is not a 64-bit GUID', function (string $input) {
	expect(Guid::tryFromString($input))->toBeNull();
})->with(['', '0x', '0x0', '0x0000000000000000', 'xyz', '0x10002c90300a1b2c3', ' 0x2c9', '0x2c9 ', "0x2c9\n", '-0x2c9', '0x2c9:1', 'H-0002c90300a1b2c3']);

it('compares GUIDs by value', function () {
	expect(Guid::fromString('2c9')->equals(Guid::fromString('0x00000000000002C9')))->toBeTrue();
});

// REQ-CODE-011, and the M3 index delimiter must not be ':'.
it('keys a port by node GUID and port number and parses the key back', function () {
	$ref = new PortRef(Guid::fromString('2c90300a00000'), 17);

	expect($ref->key())->toBe('0x0002c90300a00000:17')
		->and(PortRef::fromKey($ref->key())?->equals($ref))->toBeTrue()
		->and(PortRef::fromKey('0x0002c90300a00000:255'))->toBeNull()
		->and(PortRef::fromKey('0x2c90300a00000:1'))->toBeNull()
		->and(PortRef::fromKey('0x0002c90300a00000-1'))->toBeNull();
});

// REQ-LINK-002
it('decodes link widths with the tools\' encoding and stores lanes', function () {
	expect(LinkWidth::fromEncoded(1))->toBe(LinkWidth::X1)
		->and(LinkWidth::fromEncoded(2))->toBe(LinkWidth::X4)
		->and(LinkWidth::fromEncoded(4))->toBe(LinkWidth::X8)
		->and(LinkWidth::fromEncoded(8))->toBe(LinkWidth::X12)
		->and(LinkWidth::fromEncoded(16))->toBe(LinkWidth::X2)
		->and(LinkWidth::fromEncoded(3))->toBeNull()
		->and(LinkWidth::fromEncoded(0))->toBeNull()
		->and(LinkWidth::X2->lanes())->toBe(2)
		->and(LinkWidth::fromText('4x'))->toBe(LinkWidth::X4)
		->and(LinkWidth::fromText('undefined (3)'))->toBeNull()
		->and(LinkWidth::fromText('??'))->toBeNull();
});

it('reads speeds from iblinkinfo rates and ibnetdiscover names, and orders them', function () {
	expect(LinkSpeed::fromRate('25.78125 Gbps'))->toBe(LinkSpeed::Edr)
		->and(LinkSpeed::fromRate('10.0 Gbps (FDR10)'))->toBe(LinkSpeed::Fdr10)
		->and(LinkSpeed::fromRate('10.0 Gbps'))->toBe(LinkSpeed::Qdr)
		->and(LinkSpeed::fromRate('Extended speed'))->toBeNull()
		->and(LinkSpeed::fromRate('undefined (3)'))->toBeNull()
		->and(LinkSpeed::fromName('FDR10'))->toBe(LinkSpeed::Fdr10)
		->and(LinkSpeed::fromName('???'))->toBeNull()
		->and(LinkSpeed::Qdr->rank())->toBeLessThan(LinkSpeed::Fdr10->rank())
		->and(LinkSpeed::Fdr10->rank())->toBeLessThan(LinkSpeed::Fdr->rank());
});

// REQ-CODE-013: fromRow() on empty strings, NULL, and malformed values.
it('reads no node from a row without valid identity', function (array $row) {
	expect(Node::fromRow($row))->toBeNull();
})->with([
	'empty'       => [[]],
	'null guid'   => [['id' => 1, 'fabric_id' => 1, 'guid' => null, 'node_type' => 'switch']],
	'bad guid'    => [['id' => 1, 'fabric_id' => 1, 'guid' => 'S-0002', 'node_type' => 'switch']],
	'bad type'    => [['id' => 1, 'fabric_id' => 1, 'guid' => '0x0002c90300a00000', 'node_type' => '1']],
	'zero id'     => [['id' => '0', 'fabric_id' => 1, 'guid' => '0x0002c90300a00000', 'node_type' => 'ca']],
	'text id'     => [['id' => 'x', 'fabric_id' => 1, 'guid' => '0x0002c90300a00000', 'node_type' => 'ca']],
	'no fabric'   => [['id' => 1, 'fabric_id' => '', 'guid' => '0x0002c90300a00000', 'node_type' => 'ca']],
]);

it('labels a node with its GUID, whatever its description says', function () {
	$row = ['id' => 1, 'fabric_id' => 1, 'guid' => '0x0002c90300b00010', 'node_type' => 'ca', 'presence' => 'present', 'identity' => 'ok'];

	expect(Node::fromRow(['description' => 'node0001 HCA-1'] + $row)?->label())->toBe('node0001 HCA-1 (0x0002c90300b00010)')
		->and(Node::fromRow(['description' => 'trusted (0x0002c90300b99999)'] + $row)?->label())->toBe('trusted (0x0002c90300b99999) (0x0002c90300b00010)')
		->and(Node::fromRow($row)?->label())->toBe('0x0002c90300b00010');
});

it('reads a damaged node row as not sweepable', function () {
	$node = Node::fromRow(['id' => '7', 'fabric_id' => '2', 'guid' => '2c90300b00000', 'node_type' => 'ca', 'presence' => 'gone', 'identity' => '', 'conflict_seen' => '{bad', 'lid' => '', 'host_id' => null, 'sysimg_guid' => '0x0', 'port_count' => '-3']);

	expect($node)->not->toBeNull()
		->and($node->guid->text)->toBe('0x0002c90300b00000')
		->and($node->presence)->toBe(Presence::Missing)
		->and($node->identity)->toBe(IdentityState::Conflict)
		->and($node->conflictSeen)->toBeNull()
		->and($node->sysimgGuid)->toBeNull()
		->and($node->hostId)->toBe(0)
		->and($node->portCount)->toBe(0)
		->and($node->sweepable())->toBeFalse();
});

it('reads link state columns as unknown, never as defaults', function () {
	$state = LinkState::fromRow(['logical_state' => '', 'phys_state' => null, 'active_width' => '3', 'active_speed' => 'fast', 'best_width' => '', 'best_speed' => null, 'has_peer' => 'x']);

	expect($state)->toEqual(LinkState::unknown());

	$full = LinkState::fromRow(['logical_state' => 'active', 'phys_state' => 'linkup', 'active_width' => '1', 'active_speed' => 'edr', 'best_width' => '4', 'best_speed' => 'edr', 'has_peer' => '1']);

	expect($full->activeWidth)->toBe(LinkWidth::X1)
		->and($full->bestWidth)->toBe(LinkWidth::X4)
		->and($full->hasPeer)->toBeTrue()
		->and(LinkState::fromRow($full->toRow()))->toEqual($full);
});

it('reads no port from a row with a bad port number and drops a bad remote end', function () {
	expect(Port::fromRow(['node_id' => 1, 'port_number' => '255']))->toBeNull()
		->and(Port::fromRow(['node_id' => 1, 'port_number' => '']))->toBeNull()
		->and(Port::fromRow(['node_id' => 1, 'port_number' => '3', 'remote_guid' => 'junk', 'remote_port' => '1'])?->remote)->toBeNull()
		->and(Port::fromRow(['node_id' => 1, 'port_number' => '3', 'remote_guid' => '0x0002c90300a00000', 'remote_port' => '1'])?->remote?->key())->toBe('0x0002c90300a00000:1');
});

it('finds every identity attribute that differs, and never the neighbors', function () {
	$a = new NodeIdentity(NodeType::Adapter, 2, 0x101b, 0x2c9, Guid::fromString('2c90300b00000'));

	expect($a->differences($a))->toBe([])
		->and($a->differences(new NodeIdentity(NodeType::Switch, 36, 0xd2f0, 0x2c9, null)))->toBe(['node_type', 'port_count', 'device_id', 'sysimg_guid'])
		->and(NodeIdentity::fromJson($a->toJson()))->toEqual($a)
		->and(NodeIdentity::fromJson(''))->toBeNull()
		->and(NodeIdentity::fromJson('{"node_type":"hub"}'))->toBeNull();
});

it('reads a fabric only with a valid name, backend, adapter, and port', function () {
	$row = ['id' => '3', 'name' => 'ib0', 'backend' => 'diags', 'poller_id' => '2', 'sweep_interval' => '30', 'adapter' => 'mlx5_0', 'adapter_port' => '1', 'admin_state' => 'enabled'];

	$fabric = Fabric::fromRow($row);

	expect($fabric?->interval)->toBe(Fabric::DEFAULT_INTERVAL)
		->and($fabric?->backend)->toBe(Backend::Diags)
		->and(Fabric::fromRow(['admin_state' => 'on'] + $row)?->adminState)->toBe(AdminState::Disabled)
		->and(Fabric::fromRow(['adapter' => '-P'] + $row))->toBeNull()
		->and(Fabric::fromRow(['adapter' => 'mlx5_0;id'] + $row))->toBeNull()
		->and(Fabric::fromRow(['adapter' => "mlx5_0\n"] + $row)?->adapter)->toBe('mlx5_0')
		->and(Fabric::fromRow(['adapter' => "mlx5_0\n-P"] + $row))->toBeNull()
		->and(Fabric::fromRow(['adapter_port' => '0'] + $row))->toBeNull()
		->and(Fabric::fromRow(['backend' => 'ufm'] + $row))->toBeNull();
});

it('validates the fabric form against its backend', function () {
	$good = ['name' => 'Compute IB', 'backend' => 'diags', 'poller_id' => '1', 'sweep_interval' => '300', 'adapter' => 'mlx5_0', 'adapter_port' => '1', 'enabled' => 'on'];

	expect(FabricInput::parse($good, 0, [1 => 'Main'])->errors)->toBe([])
		->and(array_keys(FabricInput::parse(['adapter' => '--node-name-map', 'adapter_port' => '999', 'poller_id' => '9', 'name' => '', 'sweep_interval' => '5'] + $good, 0, [1 => 'Main'])->errors))
		->toBe(['name', 'poller_id', 'sweep_interval', 'adapter', 'adapter_port']);
});

it('refuses a remote poller until remote poller support is added', function () {
	$good    = ['name' => 'Compute IB', 'backend' => 'diags', 'poller_id' => '2', 'sweep_interval' => '300', 'adapter' => 'mlx5_0', 'adapter_port' => '1', 'enabled' => 'on'];
	$pollers = [1 => 'Main', 2 => 'Remote'];
	$remote  = new Fabric(1, 'ib0', Backend::Diags, 2, 300, 'mlx5_0', 1, AdminState::Enabled);

	expect(array_keys(FabricInput::parse($good, 0, $pollers)->errors))->toBe(['poller_id'])
		->and(FabricInput::parse(['poller_id' => '1'] + $good, 0, $pollers)->errors)->toBe([])
		->and($remote->onMainPoller())->toBeFalse()
		->and((new Sweep(false, new FixedClock(0), new ProcRunner(), Settings::fromRow([]), 2, true))->allowed($remote))->toBeFalse();
});

it('reads settings with defaults, bounds, and safe paths', function () {
	$defaults = Settings::fromRow([]);

	expect($defaults->toolDir)->toBe('/usr/sbin')
		->and($defaults->keepTablesOnUninstall)->toBeTrue()
		->and($defaults->maxOutputBytes())->toBe(8192 * 1024);

	$odd = Settings::fromRow(['fabric_tool_dir' => '/usr/sbin/../tmp', 'fabric_node_name_map' => 'relative/map', 'fabric_mad_timeout' => '99999', 'fabric_failure_budget' => '0', 'fabric_uninstall_keep_tables' => '']);

	expect($odd->toolDir)->toBe('/usr/sbin')
		->and($odd->nodeNameMap)->toBe('')
		->and($odd->madTimeoutMs)->toBe(10000)
		->and($odd->failureBudget)->toBe(Settings::DEFAULT_FAILURES)
		->and($odd->keepTablesOnUninstall)->toBeFalse()
		->and(Settings::validPath('/opt/ib tools'))->toBeFalse()
		->and(Settings::validPath('/opt/./ib'))->toBeFalse()
		->and(Settings::validPath("/usr/sbin\n"))->toBeFalse()
		->and(Settings::validPath('/opt/rdma-core/sbin'))->toBeTrue();
});

it('keeps counter values as decimal strings and rejects damaged stored ones', function () {
	expect(Counters::valuesFromJson('{"PortXmitData":"18446744073709551615","PortXmitWait":null}'))->toBe(['PortXmitData' => '18446744073709551615', 'PortXmitWait' => null])
		->and(Counters::valuesFromJson('{"PortXmitData":0}'))->toBeNull()
		->and(Counters::valuesFromJson('{"PortXmitData":"-1"}'))->toBeNull()
		->and(Counters::valuesFromJson('nope'))->toBeNull();
});
