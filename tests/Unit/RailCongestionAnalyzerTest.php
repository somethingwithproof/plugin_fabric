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

use Somethingwithproof\Fabric\RailCongestionAnalyzer;

it('reports healthy when all ports across leaf and spine rails stay below thresholds', function () {
	$analyzer = new RailCongestionAnalyzer();
	$report   = $analyzer->analyze([
		['port' => 'sw-l0:1', 'rail' => 'rail-0', 'tier' => 'leaf', 'ecnMarkedRate' => 1.0, 'pfcPauseRate' => 0.5, 'xmitWaitRate' => 10.0],
		['port' => 'sw-l0:2', 'rail' => 'rail-0', 'tier' => 'leaf', 'ecnMarkedRate' => 0.0, 'pfcPauseRate' => 0.0, 'xmitWaitRate' => 0.0],
		['port' => 'sw-s0:1', 'rail' => 'rail-0', 'tier' => 'spine', 'ecnMarkedRate' => 2.0, 'pfcPauseRate' => 0.0, 'xmitWaitRate' => 5.0],
	], 1800000000);

	expect($report['status'])->toBe('healthy')
		->and($report['link_local_faults'])->toBe([])
		->and($report['damped_ports'])->toBe([])
		->and($report['isolated_congestion'])->toBe([])
		->and($report['rail_storms'])->toBe([]);
});

it('isolates link-local FEC and symbol errors from rail congestion correlation', function () {
	$analyzer = new RailCongestionAnalyzer();
	$report   = $analyzer->analyze([
		[
			'port'                 => 'sw-l0:1',
			'rail'                 => 'rail-0',
			'tier'                 => 'leaf',
			'symbolErrorRate'      => 12.5,
			'fecUncorrectableRate' => 0.5,
			'ecnMarkedRate'        => 500.0,
			'pfcPauseRate'         => 200.0,
		],
		['port' => 'sw-l0:2', 'rail' => 'rail-0', 'tier' => 'leaf', 'ecnMarkedRate' => 15.0],
		['port' => 'sw-l0:3', 'rail' => 'rail-0', 'tier' => 'leaf', 'ecnMarkedRate' => 0.0],
		['port' => 'sw-l0:4', 'rail' => 'rail-0', 'tier' => 'leaf', 'ecnMarkedRate' => 0.0],
	], 1800000000);

	// Port 1 has link-local physical errors so it must not combine with port 2 into a rail storm.
	expect($report['status'])->toBe('link_local')
		->and($report['rail_storms'])->toBe([])
		->and($report['link_local_faults'])->toHaveCount(1)
		->and($report['link_local_faults'][0]['port'])->toBe('sw-l0:1')
		->and($report['link_local_faults'][0]['causes'])->toBe(['symbol_errors', 'fec_uncorrectable'])
		->and($report['isolated_congestion'])->toHaveCount(1)
		->and($report['isolated_congestion'][0]['port'])->toBe('sw-l0:2');
});

it('applies exponential-decay flap damping with hysteresis, max cap, and bounded state eviction', function () {
	$analyzer = new RailCongestionAnalyzer(
		maxTrackedPorts: 2,
		flapPenalty: 1000.0,
		suppressThreshold: 2000.0,
		reuseThreshold: 750.0,
		maxPenalty: 3500.0,
		halfLifeSeconds: 600.0,
	);

	// First flap: penalty 1000, not yet suppressed.
	$s1 = $analyzer->recordFlap('sw:1', 1000, 1);
	expect($s1['penalty'])->toBe(1000.0)
		->and($s1['suppressed'])->toBeFalse();

	// Second flap at same timestamp: penalty 2000 >= suppress threshold.
	$s2 = $analyzer->recordFlap('sw:1', 1000, 1);
	expect($s2['penalty'])->toBe(2000.0)
		->and($s2['suppressed'])->toBeTrue();

	// Burst flaps clamp at maxPenalty (3500).
	$s3 = $analyzer->recordFlap('sw:1', 1000, 10);
	expect($s3['penalty'])->toBe(3500.0)
		->and($s3['suppressed'])->toBeTrue();

	// After 1 half-life (600 s): penalty decays to 1750, still suppressed by hysteresis (> 750).
	$mid = $analyzer->flapState('sw:1', 1600);
	expect($mid['penalty'])->toBe(1750.0)
		->and($mid['suppressed'])->toBeTrue();

	// After 3 half-lives (1800 s): penalty decays to 437.5 <= 750 reuse threshold, unsuppressed.
	$reused = $analyzer->flapState('sw:1', 2800);
	expect($reused['penalty'])->toBe(437.5)
		->and($reused['suppressed'])->toBeFalse();

	// Bounded state eviction: maxTrackedPorts = 2 evicts the stalest port (sw:1 at t=1000).
	$analyzer->recordFlap('sw:2', 1100, 1);
	$analyzer->recordFlap('sw:3', 1200, 1);

	expect($analyzer->trackedPortCount())->toBe(2)
		->and($analyzer->flapState('sw:1', 1200)['penalty'])->toBe(0.0)
		->and($analyzer->flapState('sw:2', 1200)['penalty'])->toBeGreaterThan(0.0)
		->and($analyzer->flapState('sw:3', 1200)['penalty'])->toBe(1000.0);
});

it('detects single-tier and cross-tier rail-wide ECN/PFC congestion storms while isolating damped ports', function () {
	$analyzer = new RailCongestionAnalyzer();
	$now      = 1800000000;

	// Pre-flap sw-l1:9 so it is suppressed and excluded from congestion correlation.
	$analyzer->recordFlap('sw-l1:9', $now, 3);

	$report = $analyzer->analyze([
		// Rail 1: leaf + spine correlated PFC/ECN storm -> cross_tier_rail_storm.
		['port' => 'sw-l1:1', 'rail' => 'rail-1', 'tier' => 'leaf', 'ecnMarkedRate' => 50.0, 'pfcPauseRate' => 40.0],
		['port' => 'sw-l1:2', 'rail' => 'rail-1', 'tier' => 'leaf', 'ecnMarkedRate' => 30.0, 'pfcPauseRate' => 50.0],
		['port' => 'sw-l1:3', 'rail' => 'rail-1', 'tier' => 'leaf', 'ecnMarkedRate' => 0.0],
		['port' => 'sw-l1:9', 'rail' => 'rail-1', 'tier' => 'leaf', 'pfcPauseRate' => 999.0],
		['port' => 'sw-s1:1', 'rail' => 'rail-1', 'tier' => 'spine', 'pfcPauseRate' => 60.0],
		['port' => 'sw-s1:2', 'rail' => 'rail-1', 'tier' => 'spine', 'pfcPauseRate' => 70.0],
		// Rail 2: leaf-only XmitWait storm -> tier_rail_storm.
		['port' => 'sw-l2:1', 'rail' => 'rail-2', 'tier' => 'LEAF', 'xmitWaitRate' => 500.0],
		['port' => 'sw-l2:2', 'rail' => 'rail-2', 'tier' => 'leaf', 'xmitWaitRate' => 600.0],
		['port' => 'sw-l2:3', 'rail' => 'rail-2', 'tier' => 'leaf', 'active' => false, 'xmitWaitRate' => 900.0],
	], $now);

	expect($report['status'])->toBe('mixed')
		->and($report['damped_ports'])->toBe(['sw-l1:9'])
		->and($report['link_local_faults'])->toHaveCount(1)
		->and($report['rail_storms'])->toHaveCount(2);

	$rail1 = $report['rail_storms'][0];
	$rail2 = $report['rail_storms'][1];

	expect($rail1['rail'])->toBe('rail-1')
		->and($rail1['scope'])->toBe('cross_tier_rail_storm')
		->and($rail1['affected_tiers'])->toBe(['leaf', 'spine'])
		->and($rail1['affected_ports'])->toBe(4)
		->and($rail1['eligible_ports'])->toBe(5)
		->and($rail1['dominant_signal'])->toBe('pfc')
		->and($rail1['ports'])->toBe(['sw-l1:1', 'sw-l1:2', 'sw-s1:1', 'sw-s1:2'])
		->and($rail2['rail'])->toBe('rail-2')
		->and($rail2['scope'])->toBe('tier_rail_storm')
		->and($rail2['affected_tiers'])->toBe(['leaf'])
		->and($rail2['dominant_signal'])->toBe('xmit_wait')
		->and($rail2['ports'])->toBe(['sw-l2:1', 'sw-l2:2']);
});

it('handles empty observations, negative or non-finite rates, and unknown tier names safely', function () {
	$analyzer = new RailCongestionAnalyzer();
	$empty    = $analyzer->analyze([], 1800000000);
	expect($empty['status'])->toBe('healthy')
		->and($empty['tracked_ports'])->toBe(0);

	$weird = $analyzer->analyze([
		['port' => 'p1', 'rail' => 'r0', 'tier' => 'custom', 'symbolErrorRate' => -5.0, 'ecnMarkedRate' => NAN, 'pfcPauseRate' => INF],
		['port' => 'p2', 'rail' => 'r0', 'tier' => 'host', 'ecnMarkedRate' => 25.0],
		['port' => 'p3', 'rail' => 'r0', 'tier' => 'host', 'ecnMarkedRate' => 30.0],
	], 1800000000);

	expect($weird['status'])->toBe('rail_congestion')
		->and($weird['rail_storms'][0]['dominant_signal'])->toBe('ecn')
		->and($weird['rail_storms'][0]['affected_tiers'])->toBe(['host']);
});
