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

namespace Somethingwithproof\Fabric;

/**
 * Separates link-local physical faults (FEC/symbol errors and flap-damped
 * ports) from correlated rail-wide ECN/PFC/XmitWait congestion storms across
 * leaf and spine tiers.
 *
 * Grounded in Guo et al. (Pingmesh, ACM SIGCOMM 2015) for tier-aware fault
 * localization and Gangidi et al. (RDMA over Ethernet for Distributed AI
 * Training at Meta Scale, ACM SIGCOMM 2024) for rail-optimized AI training
 * fabrics. Flap damping state is strictly bounded in memory via oldest-entry
 * eviction.
 */
final class RailCongestionAnalyzer {
	/** Maximum number of port keys retained in the flap damping table. */
	public const DEFAULT_MAX_TRACKED_PORTS = 4096;

	/** Penalty added per observed link-downed flap transition. */
	public const DEFAULT_FLAP_PENALTY = 1000.0;

	/** Penalty threshold at or above which a port is flap-suppressed. */
	public const DEFAULT_SUPPRESS_THRESHOLD = 2000.0;

	/** Penalty threshold at or below which a suppressed port is reused. */
	public const DEFAULT_REUSE_THRESHOLD = 750.0;

	/** Upper bound on accumulated flap penalty per port. */
	public const DEFAULT_MAX_PENALTY = 8000.0;

	/** Exponential decay half-life for flap penalties, in seconds. */
	public const DEFAULT_HALF_LIFE_SECONDS = 900.0;

	/** Minimum fraction of eligible non-damped rail/tier ports congested for a storm. */
	public const DEFAULT_RAIL_CONGESTION_RATIO = 0.25;

	/** Minimum count of congested non-damped ports required to declare a rail storm. */
	public const DEFAULT_MIN_RAIL_PORTS = 2;

	/** Link-local symbol error rate threshold (errors per second). */
	public const DEFAULT_SYMBOL_ERROR_RATE = 1.0;

	/** Link-local uncorrectable FEC error rate threshold (errors per second). */
	public const DEFAULT_FEC_UNCORRECTABLE_RATE = 0.1;

	/** ECN marking rate threshold (marks per second) indicating congestion. */
	public const DEFAULT_ECN_RATE = 10.0;

	/** PFC pause frame rate threshold (frames per second) indicating congestion. */
	public const DEFAULT_PFC_PAUSE_RATE = 5.0;

	/** InfiniBand PortXmitWait rate threshold (ticks per second) indicating congestion. */
	public const DEFAULT_XMIT_WAIT_RATE = 100.0;

	/**
	 * Bounded per-port flap damping state keyed by canonical port identifier.
	 *
	 * @var array<string, array{penalty: float, suppressed: bool, lastFlapAt: int, updatedAt: int}>
	 */
	private array $flapTable = [];

	/**
	 * Maximum number of tracked port entries before evicting the stalest key.
	 */
	private readonly int $maxTrackedPorts;

	/**
	 * Penalty added per flap event.
	 */
	private readonly float $flapPenalty;

	/**
	 * Penalty at or above which suppression activates.
	 */
	private readonly float $suppressThreshold;

	/**
	 * Penalty at or below which suppression clears.
	 */
	private readonly float $reuseThreshold;

	/**
	 * Maximum penalty ceiling per port.
	 */
	private readonly float $maxPenalty;

	/**
	 * Exponential decay half-life in seconds.
	 */
	private readonly float $halfLifeSeconds;

	/**
	 * Fraction of eligible rail/tier ports needed to classify a rail storm.
	 */
	private readonly float $railCongestionRatio;

	/**
	 * Minimum number of affected ports required for a rail storm.
	 */
	private readonly int $minRailPorts;

	/**
	 * Threshold for link-local symbol errors per second.
	 */
	private readonly float $symbolErrorRate;

	/**
	 * Threshold for link-local uncorrectable FEC errors per second.
	 */
	private readonly float $fecUncorrectableRate;

	/**
	 * Threshold for ECN marked packets per second.
	 */
	private readonly float $ecnRate;

	/**
	 * Threshold for PFC pause frames per second.
	 */
	private readonly float $pfcPauseRate;

	/**
	 * Threshold for PortXmitWait ticks per second.
	 */
	private readonly float $xmitWaitRate;

	/**
	 * Construct a bounded-state rail congestion and link-fault analyzer.
	 *
	 * @param int   $maxTrackedPorts      maximum ports held in flap state (clamped >= 1)
	 * @param float $flapPenalty          penalty per link flap (> 0)
	 * @param float $suppressThreshold    suppression activation penalty (> 0)
	 * @param float $reuseThreshold       suppression release penalty (>= 0 and <= suppressThreshold)
	 * @param float $maxPenalty           maximum penalty cap (>= suppressThreshold)
	 * @param float $halfLifeSeconds      decay half-life in seconds (> 0)
	 * @param float $railCongestionRatio  minimum congested port fraction in (0, 1]
	 * @param int   $minRailPorts         minimum congested ports per rail/tier (>= 1)
	 * @param float $symbolErrorRate      link-local symbol error rate threshold (> 0)
	 * @param float $fecUncorrectableRate link-local uncorrectable FEC rate threshold (> 0)
	 * @param float $ecnRate              ECN marking rate threshold (> 0)
	 * @param float $pfcPauseRate         PFC pause rate threshold (> 0)
	 * @param float $xmitWaitRate         PortXmitWait rate threshold (> 0)
	 */
	public function __construct(
		int $maxTrackedPorts = self::DEFAULT_MAX_TRACKED_PORTS,
		float $flapPenalty = self::DEFAULT_FLAP_PENALTY,
		float $suppressThreshold = self::DEFAULT_SUPPRESS_THRESHOLD,
		float $reuseThreshold = self::DEFAULT_REUSE_THRESHOLD,
		float $maxPenalty = self::DEFAULT_MAX_PENALTY,
		float $halfLifeSeconds = self::DEFAULT_HALF_LIFE_SECONDS,
		float $railCongestionRatio = self::DEFAULT_RAIL_CONGESTION_RATIO,
		int $minRailPorts = self::DEFAULT_MIN_RAIL_PORTS,
		float $symbolErrorRate = self::DEFAULT_SYMBOL_ERROR_RATE,
		float $fecUncorrectableRate = self::DEFAULT_FEC_UNCORRECTABLE_RATE,
		float $ecnRate = self::DEFAULT_ECN_RATE,
		float $pfcPauseRate = self::DEFAULT_PFC_PAUSE_RATE,
		float $xmitWaitRate = self::DEFAULT_XMIT_WAIT_RATE,
	) {
		$this->maxTrackedPorts      = max(1, $maxTrackedPorts);
		$this->flapPenalty          = max(1.0, $flapPenalty);
		$this->suppressThreshold    = max(1.0, $suppressThreshold);
		$this->reuseThreshold       = max(0.0, min($this->suppressThreshold, $reuseThreshold));
		$this->maxPenalty           = max($this->suppressThreshold, $maxPenalty);
		$this->halfLifeSeconds      = max(1.0, $halfLifeSeconds);
		$this->railCongestionRatio  = max(0.01, min(1.0, $railCongestionRatio));
		$this->minRailPorts         = max(1, $minRailPorts);
		$this->symbolErrorRate      = max(0.0001, $symbolErrorRate);
		$this->fecUncorrectableRate = max(0.0001, $fecUncorrectableRate);
		$this->ecnRate              = max(0.0001, $ecnRate);
		$this->pfcPauseRate         = max(0.0001, $pfcPauseRate);
		$this->xmitWaitRate         = max(0.0001, $xmitWaitRate);
	}

	/**
	 * Record one or more link-downed flap transitions for a port and return
	 * its updated exponential-decay damping state.
	 *
	 * @param string $portKey   canonical port identifier (for example "0x...:1")
	 * @param int    $timestamp observation time in Unix seconds
	 * @param int    $flapCount number of transitions observed since the previous poll
	 *
	 * @return array{port: string, penalty: float, suppressed: bool, lastFlapAt: int}
	 */
	public function recordFlap(string $portKey, int $timestamp, int $flapCount = 1): array {
		$flaps   = max(0, $flapCount);
		$current = $this->flapState($portKey, $timestamp);
		$penalty = $current['penalty'];

		if ($flaps > 0) {
			$penalty = min($this->maxPenalty, $penalty + ($flaps * $this->flapPenalty));
		}

		$suppressed = $current['suppressed'];

		if ($penalty >= $this->suppressThreshold) {
			$suppressed = true;
		} elseif ($penalty <= $this->reuseThreshold) {
			$suppressed = false;
		}

		$lastFlapAt = $flaps > 0 ? $timestamp : $current['lastFlapAt'];

		if (!isset($this->flapTable[$portKey]) && count($this->flapTable) >= $this->maxTrackedPorts) {
			$this->evictOldest();
		}

		$this->flapTable[$portKey] = [
			'penalty'    => round($penalty, 6),
			'suppressed' => $suppressed,
			'lastFlapAt' => $lastFlapAt,
			'updatedAt'  => $timestamp,
		];

		return [
			'port'       => $portKey,
			'penalty'    => $this->flapTable[$portKey]['penalty'],
			'suppressed' => $suppressed,
			'lastFlapAt' => $lastFlapAt,
		];
	}

	/**
	 * Read the decayed flap damping state of a port at a given timestamp
	 * without mutating the internal table.
	 *
	 * @param string $portKey canonical port identifier
	 * @param int    $now     evaluation time in Unix seconds
	 *
	 * @return array{penalty: float, suppressed: bool, lastFlapAt: int}
	 */
	public function flapState(string $portKey, int $now): array {
		if (!isset($this->flapTable[$portKey])) {
			return [
				'penalty'    => 0.0,
				'suppressed' => false,
				'lastFlapAt' => 0,
			];
		}

		$entry = $this->flapTable[$portKey];
		$dt    = max(0, $now - $entry['updatedAt']);
		$decay = $dt > 0 ? pow(0.5, $dt / $this->halfLifeSeconds) : 1.0;

		$penalty    = round($entry['penalty'] * $decay, 6);
		$suppressed = $entry['suppressed'];

		if ($suppressed && $penalty <= $this->reuseThreshold) {
			$suppressed = false;
		}

		return [
			'penalty'    => $penalty,
			'suppressed' => $suppressed,
			'lastFlapAt' => $entry['lastFlapAt'],
		];
	}

	/**
	 * Return the number of ports currently held in bounded flap damping state.
	 *
	 * @return int
	 */
	public function trackedPortCount(): int {
		return count($this->flapTable);
	}

	/**
	 * Analyze a batch of port observations across rails and tiers, separating
	 * link-local physical/flap faults from correlated rail-wide congestion
	 * storms.
	 *
	 * @param list<array{
	 *   port: string,
	 *   rail: string,
	 *   tier: string,
	 *   symbolErrorRate?: float,
	 *   fecUncorrectableRate?: float,
	 *   linkDownedDelta?: int,
	 *   ecnMarkedRate?: float,
	 *   pfcPauseRate?: float,
	 *   xmitWaitRate?: float,
	 *   active?: bool
	 * }> $observations port telemetry snapshots for the current sweep window
	 * @param int $now observation timestamp in Unix seconds
	 *
	 * @return array{
	 *   status: string,
	 *   link_local_faults: list<array{port: string, rail: string, tier: string, causes: list<string>, flapPenalty: float, suppressed: bool}>,
	 *   damped_ports: list<string>,
	 *   isolated_congestion: list<array{port: string, rail: string, tier: string, signals: list<string>}>,
	 *   rail_storms: list<array{rail: string, scope: string, affected_tiers: list<string>, affected_ports: int, eligible_ports: int, affected_ratio: float, dominant_signal: string, ports: list<string>}>,
	 *   tracked_ports: int
	 * }
	 */
	public function analyze(array $observations, int $now): array {
		$localFaults = [];
		$dampedPorts = [];
		/** @var array<string, array<string, array{eligible: int, congested: list<array{port: string, signals: list<string>, scores: array<string, float>}>}>> $buckets */
		$buckets = [];

		foreach ($observations as $obs) {
			$port   = $obs['port'];
			$rail   = $obs['rail'];
			$tier   = self::normalizeTier($obs['tier']);
			$active = $obs['active'] ?? true;

			$symbolRate = self::nonNegative($obs['symbolErrorRate'] ?? 0.0);
			$fecRate    = self::nonNegative($obs['fecUncorrectableRate'] ?? 0.0);
			$flaps      = max(0, $obs['linkDownedDelta'] ?? 0);
			$ecnRate    = self::nonNegative($obs['ecnMarkedRate'] ?? 0.0);
			$pfcRate    = self::nonNegative($obs['pfcPauseRate'] ?? 0.0);
			$xmitRate   = self::nonNegative($obs['xmitWaitRate'] ?? 0.0);

			$flap = $flaps > 0
				? $this->recordFlap($port, $now, $flaps)
				: $this->flapState($port, $now);

			if ($flap['suppressed']) {
				$dampedPorts[] = $port;
			}

			$causes = [];

			if ($symbolRate >= $this->symbolErrorRate) {
				$causes[] = 'symbol_errors';
			}

			if ($fecRate >= $this->fecUncorrectableRate) {
				$causes[] = 'fec_uncorrectable';
			}

			if ($flap['suppressed']) {
				$causes[] = 'flap_damped';
			}

			if ($causes !== []) {
				$localFaults[] = [
					'port'        => $port,
					'rail'        => $rail,
					'tier'        => $tier,
					'causes'      => $causes,
					'flapPenalty' => $flap['penalty'],
					'suppressed'  => $flap['suppressed'],
				];

				// Isolate link-local faults and flap-damped ports so damaged
				// optics or unstable cables are never misclassified as
				// rail-wide fabric congestion.
				continue;
			}

			if (!$active) {
				continue;
			}

			if (!isset($buckets[$rail][$tier])) {
				$buckets[$rail][$tier] = [
					'eligible'  => 0,
					'congested' => [],
				];
			}

			$buckets[$rail][$tier]['eligible']++;

			$signals = [];
			$scores  = [
				'ecn'       => $ecnRate / $this->ecnRate,
				'pfc'       => $pfcRate / $this->pfcPauseRate,
				'xmit_wait' => $xmitRate / $this->xmitWaitRate,
			];

			if ($scores['ecn'] >= 1.0) {
				$signals[] = 'ecn';
			}

			if ($scores['pfc'] >= 1.0) {
				$signals[] = 'pfc';
			}

			if ($scores['xmit_wait'] >= 1.0) {
				$signals[] = 'xmit_wait';
			}

			if ($signals !== []) {
				$buckets[$rail][$tier]['congested'][] = [
					'port'    => $port,
					'signals' => $signals,
					'scores'  => $scores,
				];
			}
		}

		$railStorms         = [];
		$isolatedCongestion = [];
		ksort($buckets);

		foreach ($buckets as $rail => $tiers) {
			ksort($tiers);
			$stormTiers    = [];
			$stormPorts    = [];
			$stormAffected = 0;
			$stormEligible = 0;
			$signalTotals  = ['ecn' => 0.0, 'pfc' => 0.0, 'xmit_wait' => 0.0];

			foreach ($tiers as $tier => $bucket) {
				$eligible  = $bucket['eligible'];
				$congested = $bucket['congested'];
				$affected  = count($congested);
				$ratio     = $eligible > 0 ? $affected / $eligible : 0.0;

				if ($affected >= $this->minRailPorts && $ratio >= $this->railCongestionRatio) {
					$stormTiers[]   = $tier;
					$stormAffected += $affected;
					$stormEligible += $eligible;

					foreach ($congested as $entry) {
						$stormPorts[] = $entry['port'];

						foreach ($signalTotals as $sig => $sum) {
							$signalTotals[$sig] = $sum + ($entry['scores'][$sig] ?? 0.0);
						}
					}
				} else {
					foreach ($congested as $entry) {
						$isolatedCongestion[] = [
							'port'    => $entry['port'],
							'rail'    => $rail,
							'tier'    => $tier,
							'signals' => $entry['signals'],
						];
					}
				}
			}

			if ($stormTiers !== []) {
				sort($stormPorts);
				$hasLeaf  = in_array('leaf', $stormTiers, true);
				$hasSpine = in_array('spine', $stormTiers, true);
				$scope    = ($hasLeaf && $hasSpine) ? 'cross_tier_rail_storm' : 'tier_rail_storm';

				$railStorms[] = [
					'rail'            => $rail,
					'scope'           => $scope,
					'affected_tiers'  => $stormTiers,
					'affected_ports'  => $stormAffected,
					'eligible_ports'  => $stormEligible,
					'affected_ratio'  => $stormEligible > 0 ? round($stormAffected / $stormEligible, 4) : 0.0,
					'dominant_signal' => self::dominantSignal($signalTotals),
					'ports'           => $stormPorts,
				];
			}
		}

		sort($dampedPorts);

		$hasLocal = $localFaults !== [];
		$hasStorm = $railStorms !== [];
		$status   = match (true) {
			$hasLocal && $hasStorm => 'mixed',
			$hasStorm              => 'rail_congestion',
			$hasLocal              => 'link_local',
			default                => 'healthy',
		};

		return [
			'status'              => $status,
			'link_local_faults'   => $localFaults,
			'damped_ports'        => $dampedPorts,
			'isolated_congestion' => $isolatedCongestion,
			'rail_storms'         => $railStorms,
			'tracked_ports'       => count($this->flapTable),
		];
	}

	/**
	 * Evict the oldest-updated entry when the bounded flap table reaches capacity.
	 *
	 * @return void
	 */
	private function evictOldest(): void {
		$oldestKey = null;
		$oldestTs  = PHP_INT_MAX;

		foreach ($this->flapTable as $key => $entry) {
			if ($entry['updatedAt'] < $oldestTs) {
				$oldestTs  = $entry['updatedAt'];
				$oldestKey = $key;
			}
		}

		if ($oldestKey !== null) {
			unset($this->flapTable[$oldestKey]);
		}
	}

	/**
	 * Normalize a fabric tier label into a canonical tier identifier.
	 *
	 * @param string $tier raw tier label
	 *
	 * @return string canonical tier ('leaf', 'spine', 'host', or 'unknown')
	 */
	private static function normalizeTier(string $tier): string {
		$normalized = strtolower(trim($tier));

		return in_array($normalized, ['leaf', 'spine', 'host'], true) ? $normalized : 'unknown';
	}

	/**
	 * Clamp non-finite or negative floating-point rates to zero.
	 *
	 * @param float $value raw metric rate
	 *
	 * @return float non-negative finite rate
	 */
	private static function nonNegative(float $value): float {
		if (!is_finite($value) || $value < 0.0) {
			return 0.0;
		}

		return $value;
	}

	/**
	 * Select the dominant congestion signal from cumulative normalized scores.
	 *
	 * @param array<string, float> $totals signal name => cumulative threshold ratio
	 *
	 * @return string dominant signal name ('ecn', 'pfc', or 'xmit_wait')
	 */
	private static function dominantSignal(array $totals): string {
		$bestSignal = 'ecn';
		$bestScore  = -1.0;

		foreach (['pfc', 'ecn', 'xmit_wait'] as $signal) {
			$score = $totals[$signal] ?? 0.0;

			if ($score > $bestScore) {
				$bestScore  = $score;
				$bestSignal = $signal;
			}
		}

		return $bestSignal;
	}
}
