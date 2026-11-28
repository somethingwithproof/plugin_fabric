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

	/** PFC pause duration threshold (microseconds per second) indicating congestion. */
	public const DEFAULT_PFC_PAUSE_DURATION_US = 10000.0;

	/** PFC pause frame rate threshold (frames per second) classifying a PFC storm. */
	public const DEFAULT_PFC_STORM_RATE = 100.0;

	/** PFC pause duration threshold (microseconds per second) classifying a PFC storm. */
	public const DEFAULT_PFC_STORM_DURATION_US = 50000.0;

	/** InfiniBand PortXmitWait rate threshold (ticks per second) indicating congestion. */
	public const DEFAULT_XMIT_WAIT_RATE = 100.0;

	/**
	 * Bounded per-port flap damping state keyed by canonical port identifier.
	 * Entries stay ordered by updatedAt ascending while timestamps are monotonic
	 * so capacity eviction runs in O(1).
	 *
	 * @var array<string, array{penalty: float, suppressed: bool, lastFlapAt: int, updatedAt: int}>
	 */
	private array $flapTable = [];

	/** Highest updatedAt timestamp recorded in $flapTable. */
	private int $maxUpdatedAt = PHP_INT_MIN;

	/** Whether $flapTable iteration order is guaranteed ascending by updatedAt. */
	private bool $flapTableOrdered = true;

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
	 * Threshold for PFC pause duration in microseconds per second.
	 */
	private readonly float $pfcPauseDurationUs;

	/**
	 * Threshold for PFC pause frames per second declaring a PFC storm.
	 */
	private readonly float $pfcStormRate;

	/**
	 * Threshold for PFC pause duration in microseconds per second declaring a PFC storm.
	 */
	private readonly float $pfcStormDurationUs;

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
	 * @param float $pfcPauseDurationUs   PFC pause duration threshold in microseconds/s (> 0)
	 * @param float $pfcStormRate         PFC pause frames/s storm threshold (> 0)
	 * @param float $pfcStormDurationUs   PFC pause duration (us/s) storm threshold (> 0)
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
		float $pfcPauseDurationUs = self::DEFAULT_PFC_PAUSE_DURATION_US,
		float $pfcStormRate = self::DEFAULT_PFC_STORM_RATE,
		float $pfcStormDurationUs = self::DEFAULT_PFC_STORM_DURATION_US,
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
		$this->pfcPauseDurationUs   = max(1.0, $pfcPauseDurationUs);
		$this->pfcStormRate         = max(0.0001, $pfcStormRate);
		$this->pfcStormDurationUs   = max(1.0, $pfcStormDurationUs);
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
		$exists     = isset($this->flapTable[$portKey]);

		if (!$exists && count($this->flapTable) >= $this->maxTrackedPorts) {
			$this->evictOldest();
		}

		// Move updated entries to the tail so monotonic timestamps keep
		// $flapTable sorted by updatedAt ascending for O(1) eviction.
		if ($timestamp < $this->maxUpdatedAt) {
			$this->flapTableOrdered = false;
		} else {
			$this->maxUpdatedAt = $timestamp;

			if ($exists) {
				unset($this->flapTable[$portKey]);
			}
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
	 * storms and RoCEv2 PFC pause storms.
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
	 *   pfcPauseDurationUs?: float,
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
	 *   rail_storms: list<array{rail: string, scope: string, affected_tiers: list<string>, affected_ports: int, eligible_ports: int, affected_ratio: float, dominant_signal: string, pfc_storm: bool, max_pfc_pause_duration_us: float, ports: list<string>}>,
	 *   pfc_storms: list<array{rail: string, ports: list<string>, max_pfc_pause_rate: float, max_pfc_pause_duration_us: float}>,
	 *   tracked_ports: int
	 * }
	 */
	public function analyze(array $observations, int $now): array {
		$localFaults = [];
		$dampedPorts = [];
		/** @var array<string, array<string, array{eligible: int, congested: list<array{port: string, signals: list<string>, scores: array<string, float>, pfcRate: float, pfcDurationUs: float, pfcStorm: bool}>}>> $buckets */
		$buckets = [];

		foreach ($observations as $obs) {
			$port   = $obs['port'];
			$rail   = $obs['rail'];
			$tier   = self::normalizeTier($obs['tier']);
			$active = $obs['active'] ?? true;

			$symbolRate    = self::nonNegative($obs['symbolErrorRate'] ?? 0.0);
			$fecRate       = self::nonNegative($obs['fecUncorrectableRate'] ?? 0.0);
			$flaps         = max(0, $obs['linkDownedDelta'] ?? 0);
			$ecnRate       = self::nonNegative($obs['ecnMarkedRate'] ?? 0.0);
			$pfcRate       = self::nonNegative($obs['pfcPauseRate'] ?? 0.0);
			$pfcDurationUs = self::nonNegative($obs['pfcPauseDurationUs'] ?? 0.0);
			$xmitRate      = self::nonNegative($obs['xmitWaitRate'] ?? 0.0);

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

			$pfcScore = max($pfcRate / $this->pfcPauseRate, $pfcDurationUs / $this->pfcPauseDurationUs);
			$pfcStorm = $pfcRate >= $this->pfcStormRate || $pfcDurationUs >= $this->pfcStormDurationUs;
			$signals  = [];
			$scores   = [
				'ecn'       => $ecnRate / $this->ecnRate,
				'pfc'       => $pfcScore,
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
					'port'          => $port,
					'signals'       => $signals,
					'scores'        => $scores,
					'pfcRate'       => $pfcRate,
					'pfcDurationUs' => $pfcDurationUs,
					'pfcStorm'      => $pfcStorm,
				];
			}
		}

		$railStorms         = [];
		$pfcStorms          = [];
		$isolatedCongestion = [];
		ksort($buckets);

		foreach ($buckets as $rail => $tiers) {
			ksort($tiers);
			$stormTiers    = [];
			$stormPorts    = [];
			$stormAffected = 0;
			$stormEligible = 0;
			$signalTotals  = ['ecn' => 0.0, 'pfc' => 0.0, 'xmit_wait' => 0.0];
			$pfcStormPorts = [];
			$maxPfcRate    = 0.0;
			$maxPfcDurUs   = 0.0;

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

						if ($entry['pfcStorm']) {
							$pfcStormPorts[] = $entry['port'];
						}

						if ($entry['pfcRate'] > $maxPfcRate) {
							$maxPfcRate = $entry['pfcRate'];
						}

						if ($entry['pfcDurationUs'] > $maxPfcDurUs) {
							$maxPfcDurUs = $entry['pfcDurationUs'];
						}

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

						if ($entry['pfcStorm']) {
							$pfcStormPorts[] = $entry['port'];

							if ($entry['pfcRate'] > $maxPfcRate) {
								$maxPfcRate = $entry['pfcRate'];
							}

							if ($entry['pfcDurationUs'] > $maxPfcDurUs) {
								$maxPfcDurUs = $entry['pfcDurationUs'];
							}
						}
					}
				}
			}

			$isPfcStorm = count($pfcStormPorts) >= $this->minRailPorts;

			if ($isPfcStorm) {
				sort($pfcStormPorts);
				$pfcStorms[] = [
					'rail'                      => $rail,
					'ports'                     => $pfcStormPorts,
					'max_pfc_pause_rate'        => round($maxPfcRate, 4),
					'max_pfc_pause_duration_us' => round($maxPfcDurUs, 4),
				];
			}

			if ($stormTiers !== []) {
				sort($stormPorts);
				$hasLeaf  = in_array('leaf', $stormTiers, true);
				$hasSpine = in_array('spine', $stormTiers, true);
				$scope    = ($hasLeaf && $hasSpine) ? 'cross_tier_rail_storm' : 'tier_rail_storm';

				$railStorms[] = [
					'rail'                      => $rail,
					'scope'                     => $scope,
					'affected_tiers'            => $stormTiers,
					'affected_ports'            => $stormAffected,
					'eligible_ports'            => $stormEligible,
					'affected_ratio'            => $stormEligible > 0 ? round($stormAffected / $stormEligible, 4) : 0.0,
					'dominant_signal'           => self::dominantSignal($signalTotals),
					'pfc_storm'                 => $isPfcStorm,
					'max_pfc_pause_duration_us' => round($maxPfcDurUs, 4),
					'ports'                     => $stormPorts,
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
			'pfc_storms'          => $pfcStorms,
			'tracked_ports'       => count($this->flapTable),
		];
	}

	/**
	 * Evict the oldest-updated entry when the bounded flap table reaches capacity.
	 * Runs in O(1) while timestamps advance monotonically; restores order via a
	 * single stable sort only after an out-of-order timestamp insert.
	 *
	 * @return void
	 */
	private function evictOldest(): void {
		if ($this->flapTable === []) {
			return;
		}

		if (!$this->flapTableOrdered) {
			uasort(
				$this->flapTable,
				static fn (array $a, array $b): int => $a['updatedAt'] <=> $b['updatedAt'],
			);
			$this->flapTableOrdered = true;
		}

		$oldestKey = array_key_first($this->flapTable);

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
