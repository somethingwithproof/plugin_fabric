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

use Somethingwithproof\Fabric\CounterGroup;
use Somethingwithproof\Fabric\Guid;
use Somethingwithproof\Fabric\IblinkinfoParser;
use Somethingwithproof\Fabric\IbnetdiscoverParser;
use Somethingwithproof\Fabric\LinkSpeed;
use Somethingwithproof\Fabric\LinkWidth;
use Somethingwithproof\Fabric\LogicalState;
use Somethingwithproof\Fabric\NodeType;
use Somethingwithproof\Fabric\ParseFailure;
use Somethingwithproof\Fabric\PerfqueryParser;
use Somethingwithproof\Fabric\PhysState;
use Somethingwithproof\Fabric\Tests\FabricGenerator;

require_once dirname(__DIR__) . '/Support/FabricGenerator.php';

/*
 * Fixtures are generated from the rdma-core v66.0 printf formats by
 * FabricGenerator, plus the hand-written lines in tests/fixtures. None is a
 * capture from real hardware; recorded captures are issue #14 and remain a
 * gap for REQ-TEST-001.
 */

function fabric_fixture(string $name): string {
	return (string) file_get_contents(dirname(__DIR__) . '/fixtures/' . $name);
}

// REQ-TEST-004
it('parses generated fabrics of 100, 1,000, and 10,000 ports whole', function (int $cas) {
	$fabric   = FabricGenerator::forCas($cas);
	$topology = (new IbnetdiscoverParser())->parse($fabric->ibnetdiscover());
	$links    = (new IblinkinfoParser())->parse($fabric->iblinkinfo());

	expect(count($topology->nodes))->toBe(count($fabric->nodes))
		->and($topology->portCount())->toBe(count($fabric->links))
		->and($topology->duplicates)->toBe([])
		->and(count($links->records))->toBe(array_sum(array_column($fabric->nodes, 'ports')))
		->and($links->skipped)->toBe([]);
})->with(['100 ports' => [44], '1,000 ports' => [470], '10,000 ports' => [4850]]);

it('counts the generated fabric sizes as their names say', function (int $cas, int $ports) {
	expect(FabricGenerator::forCas($cas)->portCount())->toBeGreaterThanOrEqual($ports);
})->with([[44, 100], [470, 1000], [4850, 10000]]);

it('takes identity from node GUIDs and records type, ports, and neighbors', function () {
	$topology = (new IbnetdiscoverParser())->parse(fabric_fixture('ibnetdiscover-small.txt'));
	$switch   = $topology->nodes['0x0002c90300a00000'];
	$adapter  = $topology->nodes['0x0002c90300b00000'];
	$router   = $topology->nodes['0x0002c90300c00000'];

	expect($topology->local?->text)->toBe('0x0002c90300b00000')
		->and($switch->type)->toBe(NodeType::Switch)
		->and($switch->portCount)->toBe(36)
		->and($switch->port0Guid?->text)->toBe('0x0002c90300a00000')
		->and($switch->lid)->toBe(1)
		->and($switch->deviceId)->toBe(0xd2f0)
		->and($switch->ports[1]->remote->key())->toBe('0x0002c90300b00000:1')
		->and($switch->ports[1]->portGuid)->toBeNull()
		->and($switch->ports[1]->width)->toBe(LinkWidth::X4)
		->and($switch->ports[1]->speed)->toBe(LinkSpeed::Edr)
		->and($adapter->type)->toBe(NodeType::Adapter)
		->and($adapter->sysimgGuid?->text)->toBe('0x0002c90300b00000')
		->and($adapter->ports[1]->portGuid?->text)->toBe('0x0002c90300b00001')
		->and($adapter->ports[1]->lid)->toBe(3)
		->and($adapter->ports[1]->remote->key())->toBe('0x0002c90300a00000:1')
		->and($router->type)->toBe(NodeType::Router)
		->and($router->sysimgGuid)->toBeNull()
		->and($router->ports[1]->remote->key())->toBe('0x0002c90300a00000:2');
});

it('cleans and shortens fabric-supplied descriptions', function () {
	$topology = (new IbnetdiscoverParser())->parse(fabric_fixture('ibnetdiscover-small.txt'));

	expect($topology->nodes['0x0002c90300c00000']->description)->toBe('rtr <b>01</b> " ; $(id)')
		->and(IbnetdiscoverParser::description("a\x1b[31m\tb" . str_repeat('x', 100)))->toHaveLength(64)
		->and(IbnetdiscoverParser::description("a\x00b\x7f c"))->toBe('a b c');
});

it('records a GUID printed in two node blocks as a duplicate', function () {
	$output   = fabric_fixture('ibnetdiscover-small.txt');
	$block    = "\nvendid=0x2c9\ndevid=0x1017\ncaguid=0x2c90300b00000\nCa\t1 \"H-0002c90300b00000\"\t\t# \"twin\"\n";
	$topology = (new IbnetdiscoverParser())->parse($output . $block);

	expect($topology->duplicates)->toBe(['0x0002c90300b00000']);
});

// REQ-INV-004: output that is not whole imports nothing.
it('rejects output that is cut short, altered, or not topology output', function (string $output) {
	expect(fn () => (new IbnetdiscoverParser())->parse($output))->toThrow(ParseFailure::class);
})->with([
	'empty'            => [''],
	'html'             => ["<html>\n<body>"],
	'cut mid block'    => [substr(FabricGenerator::forCas(3)->ibnetdiscover(), 0, 400)],
	'one-sided link'   => [preg_replace('/^\[1\]\(2c90300b00001\).*\n/m', '', FabricGenerator::forCas(3)->ibnetdiscover())],
	'port past count'  => [str_replace("Ca\t2 ", "Ca\t0 ", FabricGenerator::forCas(1)->ibnetdiscover())],
	'grouped output'   => ["\nChassis 1 (guid 0x2c90300a00000)\n"],
	'list output'      => ["Switch\t : 0x0002c90300a00000 ports 36 devid 0xd2f0 vendid 0x2c9 \"leaf\"\n"],
	'stray line'       => [FabricGenerator::forCas(2)->ibnetdiscover() . "ibwarn: something\n"],
	'zero GUID'        => [str_replace('caguid=0x2c90300b00000', 'caguid=0x0', FabricGenerator::forCas(1)->ibnetdiscover())],
]);

it('reads port state, active values, and the supported-intersection hint', function () {
	$records = (new IblinkinfoParser())->parse(fabric_fixture('iblinkinfo-line.txt'))->records;
	$byPort  = [];

	foreach ($records as $record) {
		$byPort[$record->portGuid->text . ':' . $record->port] = $record;
	}

	$up       = $byPort['0x0002c90300a00000:1']->link;
	$narrow   = $byPort['0x0002c90300a00000:2']->link;
	$slow     = $byPort['0x0002c90300a00000:3']->link;
	$fdr10    = $byPort['0x0002c90300a00000:4']->link;
	$down     = $byPort['0x0002c90300a00000:5']->link;
	$nopeer   = $byPort['0x0002c90300a00000:6']->link;
	$weird    = $byPort['0x0002c90300a00000:7']->link;

	expect($up->logical)->toBe(LogicalState::Active)
		->and($up->physical)->toBe(PhysState::LinkUp)
		->and($up->bestWidth)->toBe(LinkWidth::X4)
		->and($up->hasPeer)->toBeTrue()
		->and($byPort['0x0002c90300a00000:1']->remotePortGuid?->text)->toBe('0x0002c90300b00001')
		->and($narrow->activeWidth)->toBe(LinkWidth::X1)
		->and($narrow->bestWidth)->toBe(LinkWidth::X4)
		->and($slow->activeSpeed)->toBe(LinkSpeed::Fdr)
		->and($slow->bestSpeed)->toBe(LinkSpeed::Edr)
		->and($fdr10->activeSpeed)->toBe(LinkSpeed::Qdr)
		->and($fdr10->bestSpeed)->toBe(LinkSpeed::Fdr10)
		->and($down->logical)->toBe(LogicalState::Down)
		->and($down->activeWidth)->toBeNull()
		->and($down->hasPeer)->toBeFalse()
		->and($nopeer->logical)->toBe(LogicalState::Active)
		->and($nopeer->hasPeer)->toBeFalse()
		->and($nopeer->bestWidth)->toBeNull()
		->and($weird->activeWidth)->toBeNull()
		->and($weird->activeSpeed)->toBeNull()
		->and($weird->bestWidth)->toBe(LinkWidth::X4)
		->and((new Somethingwithproof\Fabric\LinkStateEvaluator())->evaluate($weird))->toBe(Somethingwithproof\Fabric\LinkCondition::Unknown);
});

it('rejects link output with any line it does not know', function (string $output) {
	expect(fn () => (new IblinkinfoParser())->parse($output))->toThrow(ParseFailure::class);
})->with([
	'stray'          => [fabric_fixture('iblinkinfo-line.txt') . "Switch: 0x0002c90300a00000 leaf:\n"],
	'repeated port'  => [fabric_fixture('iblinkinfo-line.txt') . strtok(fabric_fixture('iblinkinfo-line.txt'), "\n") . "\n"],
	'down with rate' => ["0x0002c90300a00000 \"                          leaf\"      1    5[  ] ==( 4X      25.78125 Gbps   Down/ Polling)==>             [  ] \"\" ( )\n"],
	'cut short'      => [substr(fabric_fixture('iblinkinfo-line.txt'), 0, -40)],
	'not line mode'  => ["CA: node0000 HCA-1:\n      0x0002c90300b00001      3    1[  ] ==( 4X      25.78125 Gbps Active/  LinkUp)==>       1    1[  ] \"leaf\" ( )\n"],
]);

it('reads node descriptions that hold quotes, link markers, and GUIDs without taking fields from them', function () {
	$report = (new IblinkinfoParser())->parse(fabric_fixture('iblinkinfo-hostile.txt'));
	$byPort = [];

	foreach ($report->records as $record) {
		$byPort[$record->portGuid->text . ':' . $record->port] = $record;
	}

	expect(array_keys($byPort))->toBe(['0x0002c90300b00001:1', '0x0002c90300b00011:1', '0x0002c90300a00000:3', '0x0002c90300a00000:5'])
		->and($byPort['0x0002c90300b00001:1']->lid)->toBe(3)
		->and($byPort['0x0002c90300b00001:1']->remotePortGuid?->text)->toBe('0x0002c90300a00000')
		->and($byPort['0x0002c90300b00001:1']->remotePort)->toBe(1)
		->and($byPort['0x0002c90300b00011:1']->remotePortGuid?->text)->toBe('0x0002c90300a00000')
		->and($byPort['0x0002c90300b00011:1']->link->activeWidth)->toBe(LinkWidth::X1)
		->and($byPort['0x0002c90300b00011:1']->link->bestWidth)->toBe(LinkWidth::X4)
		->and($byPort['0x0002c90300a00000:3']->remotePortGuid?->text)->toBe('0x0002c90300b00021')
		->and($byPort['0x0002c90300a00000:3']->remotePort)->toBe(1)
		->and($byPort['0x0002c90300a00000:3']->link->bestWidth)->toBe(LinkWidth::X4)
		->and($byPort['0x0002c90300a00000:5']->remotePortGuid?->text)->toBe('0x0002c90300b00041')
		// A line whose local or remote description imitates a whole port
		// line reads two ways, so it is skipped, never read as the
		// imitated port.
		->and(array_map(static fn (Guid $g): string => $g->text, $report->skipped))->toBe(['0x0002c90300b00031', '0x0002c90300a00000']);
});

it('skips only a line that starts like a port line and cannot be read in one way', function () {
	$good = fabric_fixture('iblinkinfo-line.txt');
	$odd  = "0x0002c90300b00051 \"node0006\" not a port line\n";

	$report = (new IblinkinfoParser())->parse($good . $odd);

	expect($report->records)->toHaveCount(7)
		->and(array_map(static fn (Guid $g): string => $g->text, $report->skipped))->toBe(['0x0002c90300b00051'])
		->and(fn () => (new IblinkinfoParser())->parse($good . "0x0000000000000000 \"zero\" x\n"))->toThrow(ParseFailure::class);
});

it('reads perfquery blocks as decimal strings with widths checked', function () {
	$counters = (new PerfqueryParser())->parse(fabric_fixture('perfquery-basic.txt') . fabric_fixture('perfquery-extended.txt'));

	expect($counters)->toHaveCount(3)
		->and($counters[0]->group)->toBe(CounterGroup::Basic)
		->and($counters[0]->port)->toBe(1)
		->and($counters[0]->capMask)->toBe(0x1600)
		->and($counters[0]->values['PortXmitData'])->toBe('4294967295')
		->and($counters[0]->values['PortXmitWait'])->toBe('12')
		->and($counters[0]->extendedSupported())->toBeTrue()
		->and(array_key_exists('PortSelect', $counters[0]->values))->toBeFalse()
		->and($counters[1]->values['PortXmitWait'])->toBeNull()
		->and($counters[2]->group)->toBe(CounterGroup::Extended)
		->and($counters[2]->capMask2)->toBe(0x2)
		->and($counters[2]->values['PortXmitData'])->toBe('18446744073709551615')
		->and($counters[2]->values['SymbolErrorCounter'])->toBe('0')
		->and($counters[2]->values)->toHaveKey('PortUnicastXmitPkts');
});

it('rejects a counter block that is incomplete, overflowing, or reordered', function (string $output) {
	expect(fn () => (new PerfqueryParser())->parse($output))->toThrow(ParseFailure::class);
})->with([
	'missing field' => [preg_replace('/^PortRcvPkts:.*\n/m', '', fabric_fixture('perfquery-basic.txt'), 1)],
	'8-bit overflow' => [preg_replace('/^LinkDownedCounter:\.*\d+$/m', 'LinkDownedCounter:...............256', fabric_fixture('perfquery-basic.txt'), 1)],
	'64-bit overflow' => [str_replace('18446744073709551615', '18446744073709551616', fabric_fixture('perfquery-extended.txt'))],
	'negative'       => [preg_replace('/^SymbolErrorCounter:\.*\d+$/m', 'SymbolErrorCounter:..............-1', fabric_fixture('perfquery-basic.txt'), 1)],
	'stderr line'    => [fabric_fixture('perfquery-basic.txt') . "ibwarn: [1] mad_rpc: _do_madrpc failed\n"],
	'aggregate port' => [str_replace('port 1 (', 'port 255 (', fabric_fixture('perfquery-basic.txt'))],
]);

it('stops where perfquery stopped and returns the ports printed before', function () {
	$fabric = FabricGenerator::forCas(4);

	expect((new PerfqueryParser())->parse($fabric->perfquery(1, [1, 2, 3, 4], false, 3)))->toHaveCount(2);
});
