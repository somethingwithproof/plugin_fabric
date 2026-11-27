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

use Somethingwithproof\Fabric\DomSeverity;
use Somethingwithproof\Fabric\FecHealthLevel;
use Somethingwithproof\Fabric\FecTelemetry;
use Somethingwithproof\Fabric\LinkSpeed;
use Somethingwithproof\Fabric\OpticalDomParser;
use Somethingwithproof\Fabric\OpticalDomSample;
use Somethingwithproof\Fabric\ParseFailure;
use Somethingwithproof\Fabric\RailCongestionAnalyzer;
use Somethingwithproof\Fabric\RoceCongestionSample;
use Somethingwithproof\Fabric\UfmParser;

describe('optical transceiver DOM telemetry', function () {
	it('parses mlxlink JSON with multi-lane DOM telemetry and classifies warning/alarm states', function () {
		$parser = new OpticalDomParser();

		$healthyJson = (string) json_encode([
			'result' => [
				'output' => [
					'Module Info' => [
						'Vendor Part Number'                       => 'MMA4Z00-NS400',
						'Temperature [C]'                          => '44 [70..80]',
						'Voltage [mV]'                             => '3298',
						'Bias Current [mA]'                        => '36.2,37.1,36.8,36.5',
						'Rx Power Current [dBm]'                   => '-1.2,-1.4,-1.1,-1.5',
						'Tx Power Current [dBm]'                   => '-0.8,-0.9,-0.7,-1.0',
						'Temperature Alarm and Warning Thresholds' => '80,0,70,5',
					],
				],
			],
		]);

		$sample = $parser->parseMlxlinkJson($healthyJson, '0x0002c90300a00000:1');

		expect($sample->portKey)->toBe('0x0002c90300a00000:1')
			->and($sample->temperatureC)->toBe(44.0)
			->and($sample->voltageV)->toBe(3.298)
			->and($sample->txPowerDbm)->toBe(-1.0)
			->and($sample->rxPowerDbm)->toBe(-1.5)
			->and($sample->biasCurrentMa)->toBe(37.1)
			->and($sample->txPowerLanesDbm)->toHaveCount(4)
			->and($sample->rxPowerLanesDbm)->toHaveCount(4)
			->and($sample->biasCurrentLanesMa)->toHaveCount(4)
			->and($sample->partNumber)->toBe('MMA4Z00-NS400')
			->and($sample->severity)->toBe(DomSeverity::Ok)
			->and($sample->alerts)->toBe([])
			->and($sample->toArray()['severity'])->toBe('ok');

		// High temperature alarm + low RX optical power alarm on one lane + high bias warning.
		$alarmJson = (string) json_encode([
			'result' => [
				'output' => [
					'port'        => 'mlx5_0:1',
					'Module Info' => [
						'Temperature [C]'        => '82.5 C',
						'Bias Current [mA]'      => '88.0',
						'Rx Power Current [dBm]' => '-2.0,-13.5,-2.1,-2.0',
						'Tx Power Current [dBm]' => '-7.2',
					],
				],
			],
		]);

		$alarm = $parser->parseMlxlinkJson($alarmJson);

		expect($alarm->portKey)->toBe('mlx5_0:1')
			->and($alarm->severity)->toBe(DomSeverity::Alarm)
			->and($alarm->alerts)->toContain('temp_high_alarm')
			->and($alarm->alerts)->toContain('rx_power_low_alarm')
			->and($alarm->alerts)->toContain('tx_power_low_warning')
			->and($alarm->alerts)->toContain('bias_high_warning');
	});

	it('parses UFM cable-info payloads via UfmParser and handles unknown/malformed inputs', function () {
		$ufm    = new UfmParser();
		$parsed = $ufm->parseOpticalDom([
			'data' => [
				[
					'systemID'           => '0002c90300a00001',
					'port_number'        => 3,
					'module_temperature' => '72.0 C',
					'tx_power_dbm'       => -1.0,
					'rx_power_dbm'       => -9.5,
					'laser_bias_current' => '45.0 mA',
					'voltage'            => 3300,
					'part_number'        => 'OSFP-800G-2XDR4',
				],
				[
					'port_name'   => '0002c90300a00001_4',
					'temperature' => 'N/A',
					'tx_power'    => '-',
					'rx_power'    => null,
				],
			],
		]);

		expect($parsed)->toHaveKey('0x0002c90300a00001:3')
			->and($parsed['0x0002c90300a00001:3']->severity)->toBe(DomSeverity::Warning)
			->and($parsed['0x0002c90300a00001:3']->alerts)->toBe(['rx_power_low_warning', 'temp_high_warning'])
			->and($parsed['0x0002c90300a00001:3']->voltageV)->toBe(3.3)
			->and($parsed['0x0002c90300a00001:4']->severity)->toBe(DomSeverity::Unknown)
			->and($parsed['0x0002c90300a00001:4']->alerts)->toBe([]);

		$parser = new OpticalDomParser();
		expect(fn () => $parser->parseMlxlinkJson('{broken'))->toThrow(ParseFailure::class)
			->and(fn () => $parser->parseMlxlinkJson('"scalar"'))->toThrow(ParseFailure::class)
			->and(fn () => $parser->parseUfmCableInfo(['data' => ['not-an-object']]))->toThrow(ParseFailure::class);

		// Low alarm boundaries and custom threshold overrides.
		$low = OpticalDomSample::classify(-6.0, 7.0, 5.0, 0.3);
		expect($low['severity'])->toBe(DomSeverity::Alarm)
			->and($low['alerts'])->toContain('temp_low_alarm')
			->and($low['alerts'])->toContain('tx_power_high_alarm')
			->and($low['alerts'])->toContain('rx_power_high_warning')
			->and($low['alerts'])->toContain('bias_low_alarm');
	});
});

describe('pre-FEC BER and FEC codeword rates', function () {
	it('computes 64-bit FEC codeword rates and applies speed-specific 400G NDR and 800G XDR pre-FEC BER thresholds', function () {
		$prev = [
			'FecCorrectedBlocks'   => '1000000',
			'FecUncorrectedBlocks' => '10',
		];
		$curr = [
			'FecCorrectedBlocks'   => '1060000',
			'FecUncorrectedBlocks' => '10',
		];

		// 60,000 corrected / 60s = 1,000/s (degraded), 0 uncorrected, NDR raw_ber 3e-5 (degraded for NDR, healthy for XDR).
		$ndr = FecTelemetry::fromCountersDelta($prev, 1000, $curr, 1060, '0x0002c90300a00000:1', LinkSpeed::Ndr, 3.0e-5, 1.0e-15);
		expect($ndr->correctedCodewordsPerSec)->toBe(1000.0)
			->and($ndr->uncorrectedCodewordsPerSec)->toBe(0.0)
			->and($ndr->preFecBer)->toBe(3.0e-5)
			->and($ndr->health)->toBe(FecHealthLevel::Degraded)
			->and($ndr->reasons)->toBe(['fec_corrected_rate_degraded', 'pre_fec_ber_degraded'])
			->and($ndr->toArray()['health'])->toBe('degraded');

		// Same raw BER 3e-5 on 800G XDR with low corrected rate is healthy (XDR degraded starts at 5e-5).
		$xdrHealthy = FecTelemetry::fromCountersDelta(
			['FecCorrectedBlocks' => '100', 'FecUncorrectedBlocks' => '0'],
			1000,
			['FecCorrectedBlocks' => '700', 'FecUncorrectedBlocks' => '0'],
			1060,
			'0x0002c90300a00000:2',
			LinkSpeed::Xdr,
			3.0e-5,
			1.0e-15,
		);
		expect($xdrHealthy->health)->toBe(FecHealthLevel::Healthy)
			->and($xdrHealthy->reasons)->toBe([]);

		// Critical XDR pre-FEC BER + uncorrected codewords/s.
		$xdrCrit = FecTelemetry::fromCountersDelta(
			['FecCorrectedBlocks' => '0', 'FecUncorrectedBlocks' => '0'],
			1000,
			['FecCorrectedBlocks' => '10000000', 'FecUncorrectedBlocks' => '12'],
			1060,
			'0x0002c90300a00000:3',
			LinkSpeed::Xdr,
			2.5e-4,
			5.0e-10,
		);
		expect($xdrCrit->uncorrectedCodewordsPerSec)->toBe(0.2)
			->and($xdrCrit->health)->toBe(FecHealthLevel::Critical)
			->and($xdrCrit->reasons)->toContain('pre_fec_ber_critical')
			->and($xdrCrit->reasons)->toContain('effective_ber_critical')
			->and($xdrCrit->reasons)->toContain('fec_uncorrected_critical')
			->and($xdrCrit->reasons)->toContain('fec_corrected_rate_critical');

		// Counter reset yields null rates rather than negative numbers.
		$reset = FecTelemetry::fromCountersDelta(['FecCorrectedBlocks' => '500'], 1000, ['FecCorrectedBlocks' => '10'], 1060, 'p1', LinkSpeed::Ndr);
		expect($reset->correctedCodewordsPerSec)->toBeNull()
			->and($reset->health)->toBe(FecHealthLevel::Unknown);
	});

	it('parses mlxlink and UFM FEC/BER telemetry payloads cleanly', function () {
		$parsed = FecTelemetry::parseTelemetry([
			'result' => [
				'output' => [
					'Operational Info'               => ['Speed' => '400G NDR'],
					'Physical Counters and BER Info' => [
						'Raw Physical BER'                  => '1.5E-4',
						'Effective Physical BER'            => '1.0E-14',
						'fec_corrected_codewords_per_sec'   => 250.0,
						'fec_uncorrected_codewords_per_sec' => 0.05,
					],
				],
			],
		], 'mlx5_0:1');

		expect($parsed->speed)->toBe(LinkSpeed::Ndr)
			->and($parsed->rawBer)->toBe(1.5e-4)
			->and($parsed->effectiveBer)->toBe(1.0e-14)
			->and($parsed->health)->toBe(FecHealthLevel::Critical)
			->and($parsed->reasons)->toBe(['fec_uncorrected_nonzero', 'pre_fec_ber_critical']);
	});
});

describe('RoCEv2 ECN/PFC counters and PFC storm integration', function () {
	it('computes RoCEv2 ECN, per-priority PFC pause frame rates, pause duration (us), and integrates PFC storms with RailCongestionAnalyzer', function () {
		$prev = [
			'np_ecn_marked_roce_packets' => '1000',
			'rx_prio3_pause'             => '500',
			'rx_prio3_pause_duration'    => '100000',
			'rx_prio4_pause'             => '100',
			'rx_prio4_pause_duration'    => '20000',
		];
		$curr = [
			'np_ecn_marked_roce_packets' => '10000',
			'rx_prio3_pause'             => '12500',
			'rx_prio3_pause_duration'    => '4300000',
			'rx_prio4_pause'             => '400',
			'rx_prio4_pause_duration'    => '200000',
		];

		// Over 60s: ECN = 150/s, PFC prio3 = 200/s + prio4 = 5/s = 205/s, duration = 73,000 us/s -> PFC storm.
		$s1 = RoceCongestionSample::fromCounterDelta($prev, 1000, $curr, 1060, 'sw-l0:1');
		expect($s1->ecnMarkedPktsPerSec)->toBe(150.0)
			->and($s1->pfcPauseFramesPerSec)->toBe(205.0)
			->and($s1->pfcPauseDurationUs)->toBe(73000.0)
			->and($s1->pfcStorm)->toBeTrue()
			->and($s1->pfcPriorityPausesPerSec)->toBe([3 => 200.0, 4 => 5.0]);

		$s2 = RoceCongestionSample::parseTelemetry([
			'port'                        => 'sw-l0:2',
			'ecn_marked_pkts_per_sec'     => 120.0,
			'pfc_pause_frames_per_sec'    => 180.0,
			'pfc_pause_duration_us'       => 62000.0,
			'pfc_priority_pauses_per_sec' => [3 => 180.0],
		]);
		expect($s2->pfcStorm)->toBeTrue();

		$s3 = RoceCongestionSample::parseTelemetry([
			'port'                     => 'sw-l0:3',
			'ecn_marked_pkts_per_sec'  => 0.0,
			'pfc_pause_frames_per_sec' => 0.0,
			'pfc_pause_duration_us'    => 0.0,
		]);
		expect($s3->pfcStorm)->toBeFalse();

		$analyzer = new RailCongestionAnalyzer();
		$report   = $analyzer->analyze([
			$s1->toRailObservation('rail-0', 'leaf'),
			$s2->toRailObservation('rail-0', 'leaf'),
			$s3->toRailObservation('rail-0', 'leaf'),
		], 1060);

		expect($report['status'])->toBe('rail_congestion')
			->and($report['rail_storms'])->toHaveCount(1)
			->and($report['rail_storms'][0]['pfc_storm'])->toBeTrue()
			->and($report['rail_storms'][0]['max_pfc_pause_duration_us'])->toBe(73000.0)
			->and($report['pfc_storms'])->toHaveCount(1)
			->and($report['pfc_storms'][0]['rail'])->toBe('rail-0')
			->and($report['pfc_storms'][0]['ports'])->toBe(['sw-l0:1', 'sw-l0:2'])
			->and($report['pfc_storms'][0]['max_pfc_pause_rate'])->toBe(205.0);
	});

	it('evicts the true oldest flap entry under out-of-order timestamps as well as monotonic churn', function () {
		$analyzer = new RailCongestionAnalyzer(maxTrackedPorts: 2);
		$analyzer->recordFlap('p-mid', 2000, 1);
		$analyzer->recordFlap('p-old', 1500, 1); // out-of-order older timestamp
		$analyzer->recordFlap('p-new', 2500, 1); // must evict p-old (1500), keeping p-mid and p-new

		expect($analyzer->trackedPortCount())->toBe(2)
			->and($analyzer->flapState('p-old', 2500)['penalty'])->toBe(0.0)
			->and($analyzer->flapState('p-mid', 2500)['penalty'])->toBeGreaterThan(0.0)
			->and($analyzer->flapState('p-new', 2500)['penalty'])->toBe(1000.0);
	});
});
