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

/*
 * Reads the output of the real infiniband-diags tools, run against the
 * ibsim simulated fabric (tests/ibsim/run.sh), through the plugin's own
 * parsers, and checks the structure: 8 nodes, 4 of them switches, every
 * port key <node GUID>:<port>, and a 1X link only on node03 in the
 * degraded scenario. LIDs and line order come from OpenSM and vary
 * between runs, so they are not compared. ibsim prints "Could be 12X" on
 * every link, so below-best is not asserted; the active width is.
 *
 *   php tests/bin/check-ibsim-fixtures.php <dir with healthy/ and degraded/>
 *
 * Needs no Cacti and no database. Exits 1 on any failed check.
 */

use Somethingwithproof\Fabric\CounterGroup;
use Somethingwithproof\Fabric\IblinkinfoParser;
use Somethingwithproof\Fabric\IbnetdiscoverParser;
use Somethingwithproof\Fabric\LinkWidth;
use Somethingwithproof\Fabric\NodeType;
use Somethingwithproof\Fabric\PerfqueryParser;
use Somethingwithproof\Fabric\PortRef;

if (!function_exists('__')) {
	function __() {
		$args = func_get_args();

		return count($args) > 2 ? call_user_func_array('sprintf', array_slice($args, 0, -1)) : $args[0];
	}
}

require dirname(__DIR__, 2) . '/include/autoload.php';

$dir      = rtrim($argv[1] ?? '', '/');
$failures = 0;

function ibsim_check(bool $ok, string $message): void {
	global $failures;

	print ($ok ? 'ok   ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function ibsim_read(string $file): string {
	$data = is_file($file) ? file_get_contents($file) : false;

	if (!is_string($data)) {
		throw new RuntimeException("$file is missing");
	}

	return $data;
}

foreach (['healthy', 'degraded'] as $scenario) {
	try {
		$topology = (new IbnetdiscoverParser())->parse(ibsim_read("$dir/$scenario/ibnetdiscover.out"));
		$links    = (new IblinkinfoParser())->parse(ibsim_read("$dir/$scenario/iblinkinfo-line.out"));
		$basic    = (new PerfqueryParser())->parse(ibsim_read("$dir/$scenario/perfquery-ca.out"));
		$extended = (new PerfqueryParser())->parse(ibsim_read("$dir/$scenario/perfquery-ca-x.out"));
		$list     = (new PerfqueryParser())->parse(ibsim_read("$dir/$scenario/perfquery-sw-list.out"));
	} catch (Throwable $e) {
		ibsim_check(false, "$scenario: the output parses (" . $e::class . ': ' . $e->getMessage() . ')');

		continue;
	}

	$switches = array_filter($topology->nodes, static fn ($n): bool => $n->type === NodeType::Switch);
	$keys     = [];

	foreach ($topology->nodes as $node) {
		foreach ($node->ports as $port) {
			$keys[] = (new PortRef($node->guid, $port->number))->key();
		}
	}

	ibsim_check(count($topology->nodes) === 8, "$scenario: ibnetdiscover reads as 8 nodes");
	ibsim_check(count($switches) === 4, "$scenario: 4 of them switches");
	ibsim_check($topology->duplicates === [], "$scenario: no GUID printed twice");
	ibsim_check($topology->local !== null && $topology->local->text === '0x0002c90300bb0000', "$scenario: discovery is from node01");
	ibsim_check($keys !== [] && count(array_unique($keys)) === count($keys) && preg_grep('/^0x[0-9a-f]{16}:\d{1,3}$/', $keys) === $keys, "$scenario: every port has a unique <node GUID>:<port> key");

	// A switch's node GUID is the switchguid, not a port GUID: every
	// switch port in iblinkinfo carries the same GUID, which must be a
	// switch node.
	$switchGuids = array_map(static fn ($n): string => $n->guid->text, $switches);
	$narrow      = [];

	foreach ($links->records as $record) {
		if ($record->link->activeWidth === LinkWidth::X1) {
			$narrow[] = $record;
		}
	}

	ibsim_check(count($links->records) === 38 && $links->skipped === [], "$scenario: iblinkinfo --line reads as 38 port records, none skipped");

	$narrowSwitch = array_filter($narrow, static fn ($r): bool => in_array($r->portGuid->text, $switchGuids, true));
	$narrowCa     = array_filter($narrow, static fn ($r): bool => !in_array($r->portGuid->text, $switchGuids, true));

	if ($scenario === 'healthy') {
		ibsim_check($narrow === [], 'healthy: no port runs at 1X');
	} else {
		$node03 = array_values(array_filter($topology->nodes, static fn ($n): bool => str_contains($n->description, 'node03')));

		ibsim_check(count($narrow) === 2 && count($narrowSwitch) === 1 && count($narrowCa) === 1, 'degraded: one link runs at 1X, seen from both ends');
		$node03Ports = count($node03) === 1 ? array_map(static fn ($p): string => $p->portGuid->text ?? '', $node03[0]->ports) : [];
		$adapterEnd  = array_values($narrowCa)[0] ?? null;

		ibsim_check($adapterEnd !== null && in_array($adapterEnd->portGuid->text, $node03Ports, true) && in_array($adapterEnd->remotePortGuid->text ?? '', $switchGuids, true), 'degraded: the 1X link is the node03 adapter link to a switch');
	}

	ibsim_check(count($basic) === 1 && $basic[0]->group === CounterGroup::Basic && isset($basic[0]->values['PortXmitData']), "$scenario: perfquery reads basic counters");
	ibsim_check(count($extended) === 1 && $extended[0]->group === CounterGroup::Extended && isset($extended[0]->values['PortXmitData']), "$scenario: perfquery -x reads extended counters");
	ibsim_check(array_map(static fn ($c): int => $c->port, $list) === [1, 2, 3], "$scenario: a port list reads as ports 1 to 3");
}

exit($failures > 0 ? 1 : 0);
