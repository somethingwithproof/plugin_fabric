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
 * RoCEv2 Explicit Congestion Notification (ECN) and Priority Flow Control
 * (PFC) telemetry record for a single fabric port, including per-priority
 * pause rates, pause duration in microseconds, and PFC storm detection.
 */
final readonly class RoceCongestionSample {
	/** Default PFC pause frame rate (frames/s) declaring a port-level PFC storm. */
	public const DEFAULT_PFC_STORM_RATE = 100.0;

	/** Default PFC pause duration (microseconds/s) declaring a port-level PFC storm (5% duty cycle). */
	public const DEFAULT_PFC_STORM_DURATION_US = 50000.0;

	/** Candidate cumulative counter keys for ECN marked packets. */
	private const ECN_KEYS = ['ecn_marked_pkts', 'np_ecn_marked_roce_packets', 'rp_cnp_handled', 'Infiniband_EcnMarkedPkts'];

	/** Candidate cumulative counter keys for aggregate PFC pause frames. */
	private const PFC_FRAME_KEYS = ['pfc_pause_frames', 'rx_pause_ctrl_phy', 'Infiniband_PfcPauseFrames'];

	/** Candidate cumulative counter keys for aggregate PFC pause duration in microseconds. */
	private const PFC_DURATION_KEYS = ['pfc_pause_duration_us', 'rx_pause_duration_us', 'Infiniband_PfcPauseDurationUs'];

	/**
	 * Construct an immutable RoCEv2 ECN/PFC congestion sample.
	 *
	 * @param string           $portKey                 canonical port identifier
	 * @param float            $ecnMarkedPktsPerSec     ECN marked packets per second
	 * @param float            $pfcPauseFramesPerSec    PFC pause frames per second
	 * @param float            $pfcPauseDurationUs      PFC pause duration in microseconds per second
	 * @param bool             $pfcStorm                true when PFC rate or pause duration crosses storm thresholds
	 * @param array<int, float> $pfcPriorityPausesPerSec optional per-PCP priority (0..7) pause frame rates
	 */
	public function __construct(
		public string $portKey,
		public float $ecnMarkedPktsPerSec,
		public float $pfcPauseFramesPerSec,
		public float $pfcPauseDurationUs,
		public bool $pfcStorm = false,
		public array $pfcPriorityPausesPerSec = [],
	) {
	}

	/**
	 * Compute ECN marked packet rate, PFC pause frame rate, and PFC pause
	 * duration rate from two consecutive monotonic decimal counter snapshots.
	 * Aggregates per-priority counters (rx_prio0_pause..rx_prio7_pause and
	 * rx_prio0_pause_duration..rx_prio7_pause_duration) when aggregate keys
	 * are not present.
	 *
	 * @param array<string, ?string>|null $previous           previous counter snapshot
	 * @param int                         $previousAt         previous observation Unix timestamp
	 * @param array<string, ?string>      $current            current counter snapshot
	 * @param int                         $now                current observation Unix timestamp
	 * @param string                      $portKey            canonical port identifier
	 * @param float                       $pfcStormRate       PFC pause frames/s storm threshold
	 * @param float                       $pfcStormDurationUs PFC pause duration (us/s) storm threshold
	 *
	 * @return self
	 */
	public static function fromCounterDelta(
		?array $previous,
		int $previousAt,
		array $current,
		int $now,
		string $portKey,
		float $pfcStormRate = self::DEFAULT_PFC_STORM_RATE,
		float $pfcStormDurationUs = self::DEFAULT_PFC_STORM_DURATION_US,
	): self {
		$dt = ($previous !== null && $previousAt > 0 && $now > $previousAt) ? (float) ($now - $previousAt) : 0.0;

		$ecnRate  = $dt > 0.0 ? (self::firstDeltaRate($previous, $current, $dt, self::ECN_KEYS) ?? 0.0) : 0.0;
		$prioRate = [];
		$sumFrame = 0.0;
		$sumDur   = 0.0;

		if ($dt > 0.0 && $previous !== null) {
			for ($p = 0; $p <= 7; $p++) {
				$fRate = self::singleDeltaRate($previous, $current, $dt, 'rx_prio' . $p . '_pause');

				if ($fRate !== null) {
					$prioRate[$p] = $fRate;
					$sumFrame    += $fRate;
				}

				$dRate = self::singleDeltaRate($previous, $current, $dt, 'rx_prio' . $p . '_pause_duration');

				if ($dRate !== null) {
					$sumDur += $dRate;
				}
			}
		}

		$pfcFrames   = $dt > 0.0 ? (self::firstDeltaRate($previous, $current, $dt, self::PFC_FRAME_KEYS) ?? $sumFrame) : 0.0;
		$pfcDuration = $dt > 0.0 ? (self::firstDeltaRate($previous, $current, $dt, self::PFC_DURATION_KEYS) ?? $sumDur) : 0.0;
		$storm       = $pfcFrames >= max(0.0001, $pfcStormRate) || $pfcDuration >= max(0.0001, $pfcStormDurationUs);

		return new self(
			portKey: $portKey,
			ecnMarkedPktsPerSec: $ecnRate,
			pfcPauseFramesPerSec: $pfcFrames,
			pfcPauseDurationUs: $pfcDuration,
			pfcStorm: $storm,
			pfcPriorityPausesPerSec: $prioRate,
		);
	}

	/**
	 * Parse pre-computed RoCEv2 ECN/PFC telemetry rates from a JSON payload.
	 *
	 * @param array<array-key, mixed> $payload            decoded RoCEv2 telemetry record
	 * @param string                  $fallbackPortKey    port identifier if absent in payload
	 * @param float                   $pfcStormRate       PFC pause frames/s storm threshold
	 * @param float                   $pfcStormDurationUs PFC pause duration (us/s) storm threshold
	 *
	 * @return self
	 */
	public static function parseTelemetry(
		array $payload,
		string $fallbackPortKey = '',
		float $pfcStormRate = self::DEFAULT_PFC_STORM_RATE,
		float $pfcStormDurationUs = self::DEFAULT_PFC_STORM_DURATION_US,
	): self {
		$portKey = $fallbackPortKey;

		if ($portKey === '') {
			$rawPort = $payload['port'] ?? $payload['port_name'] ?? '';
			$portKey = is_scalar($rawPort) ? trim((string) $rawPort) : '';
		}

		$ecnRate     = self::nonNegFloat($payload['ecn_marked_pkts_per_sec'] ?? $payload['ecnMarkedRate'] ?? 0.0);
		$pfcFrames   = self::nonNegFloat($payload['pfc_pause_frames_per_sec'] ?? $payload['pfcPauseRate'] ?? 0.0);
		$pfcDuration = self::nonNegFloat($payload['pfc_pause_duration_us'] ?? $payload['pfcPauseDurationUs'] ?? 0.0);
		$prioRates   = [];

		if (isset($payload['pfc_priority_pauses_per_sec']) && is_array($payload['pfc_priority_pauses_per_sec'])) {
			foreach ($payload['pfc_priority_pauses_per_sec'] as $prio => $val) {
				if (is_int($prio) && $prio >= 0 && $prio <= 7) {
					$prioRates[$prio] = self::nonNegFloat($val);
				}
			}
		}

		$storm = $pfcFrames >= max(0.0001, $pfcStormRate) || $pfcDuration >= max(0.0001, $pfcStormDurationUs);

		return new self(
			portKey: $portKey,
			ecnMarkedPktsPerSec: $ecnRate,
			pfcPauseFramesPerSec: $pfcFrames,
			pfcPauseDurationUs: $pfcDuration,
			pfcStorm: $storm,
			pfcPriorityPausesPerSec: $prioRates,
		);
	}

	/**
	 * Convert this RoCEv2 congestion sample into an observation array directly
	 * accepted by RailCongestionAnalyzer::analyze().
	 *
	 * @param string $rail   fabric rail identifier
	 * @param string $tier   fabric tier ('leaf', 'spine', or 'host')
	 * @param bool   $active whether the port is active and eligible for correlation
	 *
	 * @return array{
	 *   port: string,
	 *   rail: string,
	 *   tier: string,
	 *   ecnMarkedRate: float,
	 *   pfcPauseRate: float,
	 *   pfcPauseDurationUs: float,
	 *   active: bool
	 * }
	 */
	public function toRailObservation(string $rail, string $tier, bool $active = true): array {
		return [
			'port'               => $this->portKey,
			'rail'               => $rail,
			'tier'               => $tier,
			'ecnMarkedRate'      => $this->ecnMarkedPktsPerSec,
			'pfcPauseRate'       => $this->pfcPauseFramesPerSec,
			'pfcPauseDurationUs' => $this->pfcPauseDurationUs,
			'active'             => $active,
		];
	}

	/**
	 * Compute rate for the first matching counter key in a list.
	 *
	 * @param array<string, ?string>|null $previous previous counter map
	 * @param array<string, ?string>      $current  current counter map
	 * @param float                       $dt       elapsed seconds (> 0)
	 * @param list<string>                $keys     candidate counter names
	 *
	 * @return ?float
	 */
	private static function firstDeltaRate(?array $previous, array $current, float $dt, array $keys): ?float {
		if ($previous === null) {
			return null;
		}

		foreach ($keys as $key) {
			$rate = self::singleDeltaRate($previous, $current, $dt, $key);

			if ($rate !== null) {
				return $rate;
			}
		}

		return null;
	}

	/**
	 * Compute rate for one 64-bit decimal counter key, returning null on
	 * missing values or counter reset.
	 *
	 * @param array<string, ?string> $previous previous counter map
	 * @param array<string, ?string> $current  current counter map
	 * @param float                  $dt       elapsed seconds (> 0)
	 * @param string                 $key      counter name
	 *
	 * @return ?float
	 */
	private static function singleDeltaRate(array $previous, array $current, float $dt, string $key): ?float {
		if (!array_key_exists($key, $current) || !array_key_exists($key, $previous)) {
			return null;
		}

		$cur  = $current[$key];
		$prev = $previous[$key];

		if ($cur === null || $prev === null || !Decimal::valid($cur) || !Decimal::valid($prev)) {
			return null;
		}

		if (Decimal::compare($cur, $prev) < 0) {
			return null;
		}

		return Decimal::difference($cur, $prev) / $dt;
	}

	/**
	 * Coerce an arbitrary numeric value to a non-negative finite float.
	 *
	 * @param mixed $raw raw metric value
	 *
	 * @return float
	 */
	private static function nonNegFloat(mixed $raw): float {
		if (!is_numeric($raw)) {
			return 0.0;
		}

		$f = (float) $raw;

		return (is_finite($f) && $f >= 0.0) ? $f : 0.0;
	}
}
