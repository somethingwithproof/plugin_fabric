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

use Somethingwithproof\Fabric\CounterDelta;
use Somethingwithproof\Fabric\CounterGroup;
use Somethingwithproof\Fabric\Counters;
use Somethingwithproof\Fabric\Decimal;
use Somethingwithproof\Fabric\FixedClock;
use Somethingwithproof\Fabric\PortQuery;
use Somethingwithproof\Fabric\PortValues;

/*
 * Counter series, rates, discontinuities, and what the Fabric Port data
 * query publishes for them (REQ-CTR-001 to REQ-CTR-006, REQ-DISC-001,
 * REQ-DISC-002, REQ-IDX-001 to REQ-IDX-003, REQ-ALERT-001, REQ-ALERT-002).
 */

const FABRIC_T0   = 1_800_000_000;
const FABRIC_NODE = '0x0002c90300a00000';

/**
 * @param array<string, ?string> $values
 */
function fabric_counters(CounterGroup $group, array $values, int $capMask = 0x1600): Counters {
	return new Counters($group, 7, 1, $capMask, $group === CounterGroup::Extended ? 2 : null, $values);
}

/**
 * A sample row as SampleRepository stores it, from two consecutive reads.
 *
 * @param array<string, ?string>      $now
 * @param array<string, ?string>|null $before
 *
 * @return array<string, mixed>
 */
function fabric_sample_row(CounterGroup $group, array $now, ?array $before, int $at, int $beforeAt = 0, int $capMask = 0x1600, int $port = 1): array {
	$delta = CounterDelta::between($before, $beforeAt, fabric_counters($group, $now, $capMask), $at);

	return [
		'node_id' => 9, 'port_number' => $port, 'counter_group' => $group->value, 'observed_at' => $at, 'attempted_at' => $at, 'cap_mask' => $capMask,
		'counters' => json_encode($now), 'rates' => $delta->ratesJson(), 'reset' => $delta->resetJson(), 'discontinuities' => $delta->reset === [] ? 0 : 1, 'discontinuity_at' => $delta->reset === [] ? 0 : $at, 'error' => '',
	];
}

/**
 * Queue one switch node mapped to device 5 with the given ports and samples.
 *
 * @param list<array<string, mixed>> $ports
 * @param list<array<string, mixed>> $samples
 */
function fabric_queue_host(array $ports, array $samples): void {
	$GLOBALS['__fabric_db_rows'] = [
		'FROM plugin_fabric_node WHERE host_id' => [['id' => 9, 'fabric_id' => 1, 'guid' => FABRIC_NODE, 'node_type' => 'switch', 'description' => 'leaf01 | evil', 'host_id' => 5, 'presence' => 'present', 'identity' => 'ok']],
		'FROM plugin_fabric_fabric WHERE id'    => [['id' => 1, 'name' => 'ib0', 'backend' => 'diags', 'poller_id' => 1, 'sweep_interval' => 300, 'adapter' => 'mlx5_0', 'adapter_port' => 1, 'admin_state' => 'enabled']],
		'FROM plugin_fabric_port'               => $ports,
		'FROM plugin_fabric_sample'             => $samples,
	];
}

/**
 * @return array<string, mixed>
 */
function fabric_port_row(int $number, string $width = '4', string $speed = 'edr', string $logical = 'active', string $remote = '0x0002c90300b00010', int $observed = FABRIC_T0): array {
	return [
		'node_id' => 9, 'port_number' => $number, 'remote_guid' => $remote, 'remote_port' => $remote === '' ? 0 : 1, 'presence' => 'present',
		'logical_state' => $logical, 'phys_state' => $logical === 'down' ? 'polling' : 'linkup', 'active_width' => $width, 'active_speed' => $speed, 'best_width' => '4', 'best_speed' => 'edr',
		'enabled_width' => 0, 'enabled_speed' => '', 'has_peer' => $remote === '' ? 0 : 1, 'link_observed_at' => $observed,
	];
}

function fabric_query(int $now = FABRIC_T0, bool $downPorts = false, ?Closure $sweep = null): PortQuery {
	return new PortQuery(false, new FixedClock($now), $downPorts, $sweep);
}

beforeEach(function () {
	$GLOBALS['__fabric_db_rows'] = [];
	$GLOBALS['__fabric_db_exec'] = [];
});

it('compares and subtracts 64-bit decimal strings exactly', function () {
	expect(Decimal::compare('18446744073709551615', '18446744073709551614'))->toBe(1)
		->and(Decimal::compare('9', '10'))->toBe(-1)
		->and(Decimal::compare('4294967295', '4294967295'))->toBe(0)
		->and(Decimal::difference('18446744073709551615', '18446744073709550615'))->toBe(1000.0)
		->and(Decimal::difference('1000000000', '999999999'))->toBe(1.0)
		->and(Decimal::valid('012'))->toBeFalse()
		->and(Decimal::valid('-1'))->toBeFalse();
});

it('computes rates and flags a decrease as a discontinuity, never a negative rate', function () {
	$delta = CounterDelta::between(['PortXmitData' => '1000', 'SymbolErrorCounter' => '5', 'PortXmitWait' => null], FABRIC_T0 - 300, fabric_counters(CounterGroup::Basic, ['PortXmitData' => '61000', 'SymbolErrorCounter' => '2', 'PortXmitWait' => null]), FABRIC_T0);

	expect($delta->rates)->toBe(['PortXmitData' => 200.0, 'SymbolErrorCounter' => null, 'PortXmitWait' => null])
		->and($delta->reset)->toBe(['SymbolErrorCounter'])
		->and($delta->since)->toBe(FABRIC_T0 - 300);
});

it('gives a saturated counter no rate, at the cap and on leaving it', function () {
	$capped = CounterDelta::between(['LinkDownedCounter' => '250'], FABRIC_T0 - 300, fabric_counters(CounterGroup::Basic, ['LinkDownedCounter' => '255']), FABRIC_T0);
	$held   = CounterDelta::between(['LinkDownedCounter' => '255'], FABRIC_T0 - 300, fabric_counters(CounterGroup::Basic, ['LinkDownedCounter' => '255']), FABRIC_T0);
	$wide   = CounterDelta::between(['LinkDownedCounter' => '255'], FABRIC_T0 - 300, fabric_counters(CounterGroup::Extended, ['LinkDownedCounter' => '555']), FABRIC_T0);

	expect($capped->rates['LinkDownedCounter'])->toBeNull()
		->and($held->rates['LinkDownedCounter'])->toBeNull()
		->and($wide->rates['LinkDownedCounter'])->toBe(1.0)
		->and($capped->reset)->toBe([]);
});

it('has no rate without a usable previous sample', function () {
	expect(CounterDelta::between(null, 0, fabric_counters(CounterGroup::Basic, ['PortRcvErrors' => '3']), FABRIC_T0)->rates['PortRcvErrors'])->toBeNull()
		->and(CounterDelta::between(['PortRcvErrors' => '1'], FABRIC_T0, fabric_counters(CounterGroup::Basic, ['PortRcvErrors' => '3']), FABRIC_T0)->since)->toBe(0)
		->and(CounterDelta::ratesFromJson('{"a":-1,"b":2,"c":"x"}'))->toBe(['b' => 2.0]);
});

it('lists one index per port in GUID and port number order, leaving out down ports without a remote end', function () {
	fabric_queue_host([fabric_port_row(0), fabric_port_row(2), fabric_port_row(10), fabric_port_row(3, '', '', 'down', '')], []);

	expect(fabric_query()->answer(5, 'index'))->toBe(FABRIC_NODE . ":2\n" . FABRIC_NODE . ":10\n")
		->and(fabric_query()->answer(5, 'num_indexes'))->toBe('2')
		->and(fabric_query(downPorts: true)->answer(5, 'num_indexes'))->toBe('3')
		->and(fabric_query()->answer(0, 'index'))->toBe('');
});

it('answers query lines with the ! delimiter and keeps fabric text from naming Cacti variables', function () {
	fabric_queue_host([fabric_port_row(2)], []);

	expect(fabric_query()->answer(5, 'query', 'fabricNode'))->toBe(FABRIC_NODE . ':2!leaf01   evil (' . FABRIC_NODE . ")\n")
		->and(fabric_query()->answer(5, 'query', 'fabricRemote'))->toBe(FABRIC_NODE . ":2!0x0002c90300b00010:1\n")
		->and(fabric_query()->answer(5, 'query', 'no_such_field'))->toBe('');
});

it('publishes counters, rates, and state from a current sample', function () {
	$basic = fabric_sample_row(CounterGroup::Basic, ['SymbolErrorCounter' => '30', 'PortXmitData' => '100', 'PortXmitWait' => '900'], ['SymbolErrorCounter' => '0', 'PortXmitData' => '50', 'PortXmitWait' => '0'], FABRIC_T0 - 10, FABRIC_T0 - 310, 0x1000, 2);
	fabric_queue_host([fabric_port_row(2)], [$basic]);
	$q = fabric_query();

	expect($q->answer(5, 'get', 'symbol_err', FABRIC_NODE . ':2'))->toBe('30')
		->and($q->answer(5, 'get', 'symbol_err_rate', FABRIC_NODE . ':2'))->toBe('0.1')
		->and($q->answer(5, 'get', 'xmit_wait_rate', FABRIC_NODE . ':2'))->toBe('3')
		->and($q->answer(5, 'get', 'xmit_data', FABRIC_NODE . ':2'))->toBe('100')
		->and($q->answer(5, 'get', 'active_lanes', FABRIC_NODE . ':2'))->toBe('4')
		->and($q->answer(5, 'get', 'speed_rank', FABRIC_NODE . ':2'))->toBe('6')
		->and($q->answer(5, 'get', 'port_state', FABRIC_NODE . ':2'))->toBe('4')
		->and($q->answer(5, 'get', 'degraded', FABRIC_NODE . ':2'))->toBe('0')
		->and($q->answer(5, 'get', 'age', FABRIC_NODE . ':2'))->toBe('10')
		->and($q->answer(5, 'get', 'fabricPortNum', FABRIC_NODE . ':2'))->toBe('2');
});

it('publishes U, never 0, for unsupported, saturated, reset, stale, and unknown values', function () {
	$basic = fabric_sample_row(CounterGroup::Basic, ['SymbolErrorCounter' => '65535', 'PortRcvErrors' => '1', 'PortXmitWait' => null, 'LinkDownedCounter' => '4'], ['SymbolErrorCounter' => '60000', 'PortRcvErrors' => '9', 'LinkDownedCounter' => '4'], FABRIC_T0, FABRIC_T0 - 300, 0x0000, 2);
	fabric_queue_host([fabric_port_row(2, '1', 'edr')], [$basic]);
	$q = fabric_query();
	$i = FABRIC_NODE . ':2';

	expect($q->answer(5, 'get', 'xmit_wait', $i))->toBe('U')
		->and($q->answer(5, 'get', 'symbol_err', $i))->toBe('U')
		->and($q->answer(5, 'get', 'symbol_err_rate', $i))->toBe('U')
		->and($q->answer(5, 'get', 'rcv_err', $i))->toBe('U')
		->and($q->answer(5, 'get', 'rcv_err_rate', $i))->toBe('U')
		->and($q->answer(5, 'get', 'link_downed_rate', $i))->toBe('0')
		->and($q->answer(5, 'get', 'degraded', $i))->toBe('U')
		->and($q->answer(5, 'get', 'active_lanes', $i))->toBe('1')
		->and($q->answer(5, 'get', 'no_such_field', $i))->toBe('U')
		->and($q->answer(5, 'get', 'symbol_err', FABRIC_NODE . ':99'))->toBe('U')
		->and($q->answer(5, 'get', 'symbol_err', '../../etc/passwd'))->toBe('U');

	// Stale: counters, rates, and state are unknown; age stays numeric.
	$late = fabric_query(FABRIC_T0 + 3000);

	expect($late->answer(5, 'get', 'link_downed', $i))->toBe('U')
		->and($late->answer(5, 'get', 'link_downed_rate', $i))->toBe('U')
		->and($late->answer(5, 'get', 'active_lanes', $i))->toBe('U')
		->and($late->answer(5, 'get', 'age', $i))->toBe('3000');
});

it('reads data counters from the extended group the port supports, even when its read failed', function () {
	$basic    = fabric_sample_row(CounterGroup::Basic, ['PortXmitData' => '4294967295', 'SymbolErrorCounter' => '1'], null, FABRIC_T0, 0, 0x1600, 2);
	$extended = fabric_sample_row(CounterGroup::Extended, ['PortXmitData' => '98765432109876', 'SymbolErrorCounter' => '7'], null, FABRIC_T0, 0, 0x1600, 2);
	fabric_queue_host([fabric_port_row(2)], [$basic, $extended]);

	expect(fabric_query()->answer(5, 'get', 'xmit_data', FABRIC_NODE . ':2'))->toBe('98765432109876')
		->and(fabric_query()->answer(5, 'get', 'symbol_err', FABRIC_NODE . ':2'))->toBe('7');

	$failed                = $extended;
	$failed['observed_at'] = 0;
	$failed['counters']    = '';
	fabric_queue_host([fabric_port_row(2)], [$basic, $failed]);

	// Not the basic counter at its cap: the data source keeps one width.
	expect(fabric_query()->answer(5, 'get', 'xmit_data', FABRIC_NODE . ':2'))->toBe('U')
		->and(fabric_query()->answer(5, 'get', 'age', FABRIC_NODE . ':2'))->toBe('0');
});

it('answers never-sampled ports with U for every value, age included', function () {
	fabric_queue_host([fabric_port_row(2, observed: 0)], []);

	foreach (PortValues::names() as $name) {
		expect(fabric_query()->answer(5, 'get', $name, FABRIC_NODE . ':2'))->toBe('U');
	}
});

it('starts the sweep once per device, only for get', function () {
	fabric_queue_host([fabric_port_row(2)], []);
	$swept = [];
	$q     = fabric_query(sweep: function (int $host) use (&$swept): void {
		$swept[] = $host;
	});

	$q->answer(5, 'index');
	$q->answer(5, 'query', 'fabricPort');
	$q->answer(5, 'get', 'age', FABRIC_NODE . ':2');
	$q->answer(5, 'get', 'age', FABRIC_NODE . ':2');
	$q->answer(6, 'get', 'age', FABRIC_NODE . ':2');

	expect($swept)->toBe([5, 6]);
});

it('keeps the data query fields, the query XML, and the data source name limit in step', function () {
	$xml = simplexml_load_file(dirname(__DIR__, 2) . '/resource/script_server/fabric_ports.xml');
	assert($xml !== false);
	$fields = [];

	foreach ($xml->fields->children() as $name => $field) {
		$fields[(string) $field->direction][] = $name;
		expect((string) $field->query_name)->toBe($name);
	}

	expect($fields['input'])->toBe(PortQuery::INPUT_FIELDS)
		->and($fields['output'])->toBe(PortValues::names())
		->and((string) $xml->output_delimiter)->toBe(PortQuery::DELIMITER)
		->and((string) $xml->index_order)->toBe(PortQuery::INDEX_FIELD)
		->and((string) $xml->arg_num_indexes)->toBe('num_indexes');

	foreach (PortValues::names() as $name) {
		expect(preg_match('/^[a-z0-9_]{1,19}$/', $name))->toBe(1);
	}

	foreach (PortValues::COUNTERS + PortValues::RATE_OF as $counter) {
		expect(\Somethingwithproof\Fabric\PerfqueryParser::width(CounterGroup::Basic, $counter))->toBeGreaterThan(0);
	}
});
