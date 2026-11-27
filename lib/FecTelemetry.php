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
 * Pre-FEC Bit Error Rate (raw_ber / effective_ber / pre_fec_ber) and FEC
 * corrected/uncorrected codeword rate telemetry and threshold classification
 * for high-speed InfiniBand and RoCEv2 links (including 400G NDR and 800G XDR).
 */
final readonly class FecTelemetry {
	/** 400G NDR (100G/lane PAM4 RS-FEC) pre-FEC BER degraded threshold. */
	public const NDR_PRE_FEC_BER_DEGRADED = 1.0e-5;

	/** 400G NDR (100G/lane PAM4 RS-FEC) pre-FEC BER critical threshold. */
	public const NDR_PRE_FEC_BER_CRITICAL = 1.0e-4;

	/** 800G XDR (200G/lane PAM4 RS-FEC) pre-FEC BER degraded threshold. */
	public const XDR_PRE_FEC_BER_DEGRADED = 5.0e-5;

	/** 800G XDR (200G/lane PAM4 RS-FEC) pre-FEC BER critical threshold. */
	public const XDR_PRE_FEC_BER_CRITICAL = 2.0e-4;

	/** Default (HDR/EDR and legacy) pre-FEC BER degraded threshold. */
	public const DEFAULT_PRE_FEC_BER_DEGRADED = 1.0e-6;

	/** Default (HDR/EDR and legacy) pre-FEC BER critical threshold. */
	public const DEFAULT_PRE_FEC_BER_CRITICAL = 1.0e-5;

	/** Post-FEC effective BER degraded threshold. */
	public const EFFECTIVE_BER_DEGRADED = 1.0e-12;

	/** Post-FEC effective BER critical threshold. */
	public const EFFECTIVE_BER_CRITICAL = 1.0e-10;

	/** Corrected FEC codewords/s degraded threshold. */
	public const CORRECTED_RATE_DEGRADED = 1000.0;

	/** Corrected FEC codewords/s critical threshold. */
	public const CORRECTED_RATE_CRITICAL = 100000.0;

	/** Uncorrected FEC codewords/s critical threshold (any positive rate is degraded). */
	public const UNCORRECTED_RATE_CRITICAL = 0.1;

	/** Candidate counter keys for cumulative corrected FEC codewords. */
	private const CORRECTED_KEYS = ['FecCorrectedBlocks', 'Infiniband_FecCorrectedCodewords', 'fec_corrected_codewords', 'phy_corrected_bits'];

	/** Candidate counter keys for cumulative uncorrected FEC codewords. */
	private const UNCORRECTED_KEYS = ['FecUncorrectedBlocks', 'Infiniband_FecUncorrectedCodewords', 'fec_uncorrected_codewords', 'phy_uncorrectable_errors'];

	/**
	 * Construct an immutable FEC and BER telemetry record.
	 *
	 * @param string         $portKey                    canonical port identifier
	 * @param ?LinkSpeed     $speed                      active link speed (NDR, XDR, HDR, etc.)
	 * @param ?float         $correctedCodewordsPerSec   corrected FEC codewords per second
	 * @param ?float         $uncorrectedCodewordsPerSec uncorrected FEC codewords per second
	 * @param ?float         $rawBer                     raw physical (pre-FEC) bit error rate
	 * @param ?float         $effectiveBer               post-FEC effective bit error rate
	 * @param ?float         $preFecBer                  canonical pre-FEC BER used for classification
	 * @param FecHealthLevel $health                     overall FEC health classification
	 * @param list<string>   $reasons                    sorted diagnostic reason codes
	 */
	public function __construct(
		public string $portKey,
		public ?LinkSpeed $speed,
		public ?float $correctedCodewordsPerSec,
		public ?float $uncorrectedCodewordsPerSec,
		public ?float $rawBer,
		public ?float $effectiveBer,
		public ?float $preFecBer,
		public FecHealthLevel $health = FecHealthLevel::Unknown,
		public array $reasons = [],
	) {
	}

	/**
	 * Compute corrected and uncorrected FEC codeword rates from two consecutive
	 * monotonic 64-bit decimal counter snapshots and classify FEC/BER health.
	 * Counter resets or missing timestamps yield null rates, never negative values.
	 *
	 * @param array<string, ?string>|null $previousCounters previous sample counter map
	 * @param int                         $previousAt       previous observation Unix timestamp
	 * @param array<string, ?string>      $currentCounters  current sample counter map
	 * @param int                         $now              current observation Unix timestamp
	 * @param string                      $portKey          canonical port identifier
	 * @param ?LinkSpeed                  $speed            active link speed for threshold selection
	 * @param ?float                      $rawBer           optional raw physical BER
	 * @param ?float                      $effectiveBer     optional effective post-FEC BER
	 *
	 * @return self
	 */
	public static function fromCountersDelta(
		?array $previousCounters,
		int $previousAt,
		array $currentCounters,
		int $now,
		string $portKey,
		?LinkSpeed $speed = null,
		?float $rawBer = null,
		?float $effectiveBer = null,
	): self {
		$correctedRate   = self::counterRate($previousCounters, $previousAt, $currentCounters, $now, self::CORRECTED_KEYS);
		$uncorrectedRate = self::counterRate($previousCounters, $previousAt, $currentCounters, $now, self::UNCORRECTED_KEYS);
		$cleanRaw        = self::validRate($rawBer);
		$cleanEff        = self::validRate($effectiveBer);
		$preFecBer       = $cleanRaw;

		$classified = self::classify($preFecBer, $cleanEff, $correctedRate, $uncorrectedRate, $speed);

		return new self(
			portKey: $portKey,
			speed: $speed,
			correctedCodewordsPerSec: $correctedRate,
			uncorrectedCodewordsPerSec: $uncorrectedRate,
			rawBer: $cleanRaw,
			effectiveBer: $cleanEff,
			preFecBer: $preFecBer,
			health: $classified['health'],
			reasons: $classified['reasons'],
		);
	}

	/**
	 * Parse an mlxlink or UFM FEC/BER telemetry payload into a classified
	 * FecTelemetry record.
	 *
	 * @param array<array-key, mixed> $payload         decoded mlxlink or UFM JSON object
	 * @param string                  $fallbackPortKey port identifier when absent in payload
	 *
	 * @return self
	 */
	public static function parseTelemetry(array $payload, string $fallbackPortKey = ''): self {
		$result   = isset($payload['result']) && is_array($payload['result']) ? $payload['result'] : $payload;
		$output   = isset($result['output']) && is_array($result['output']) ? $result['output'] : $result;
		$counters = isset($output['Physical Counters and BER Info']) && is_array($output['Physical Counters and BER Info'])
			? $output['Physical Counters and BER Info']
			: $output;

		$portKey = $fallbackPortKey;

		if ($portKey === '') {
			$rawPort = $output['port'] ?? $output['port_name'] ?? '';
			$portKey = is_scalar($rawPort) ? trim((string) $rawPort) : '';
		}

		$speedRaw = $output['active_speed'] ?? $output['speed'] ?? null;

		if (!is_string($speedRaw) && isset($output['Operational Info']) && is_array($output['Operational Info'])) {
			$opSpeed  = $output['Operational Info']['Speed'] ?? null;
			$speedRaw = is_string($opSpeed) ? $opSpeed : null;
		}

		$speed = is_string($speedRaw) ? self::resolveSpeed($speedRaw) : null;

		$rawBer       = self::parseFloat($counters['Raw Physical BER'] ?? $counters['raw_ber'] ?? $counters['pre_fec_ber'] ?? null);
		$effectiveBer = self::parseFloat($counters['Effective Physical BER'] ?? $counters['effective_ber'] ?? $counters['post_fec_ber'] ?? null);
		$preFecBer    = self::parseFloat($counters['pre_fec_ber'] ?? null) ?? $rawBer;
		$corrRate     = self::parseFloat($counters['fec_corrected_codewords_per_sec'] ?? $counters['corrected_codewords_per_sec'] ?? null);
		$uncorrRate   = self::parseFloat($counters['fec_uncorrected_codewords_per_sec'] ?? $counters['uncorrected_codewords_per_sec'] ?? null);

		$classified = self::classify($preFecBer, $effectiveBer, $corrRate, $uncorrRate, $speed);

		return new self(
			portKey: $portKey,
			speed: $speed,
			correctedCodewordsPerSec: $corrRate,
			uncorrectedCodewordsPerSec: $uncorrRate,
			rawBer: $rawBer,
			effectiveBer: $effectiveBer,
			preFecBer: $preFecBer,
			health: $classified['health'],
			reasons: $classified['reasons'],
		);
	}

	/**
	 * Classify pre-FEC BER, effective BER, and FEC codeword rates using
	 * speed-aware thresholds for 400G NDR, 800G XDR, and legacy links.
	 *
	 * @param ?float     $preFecBer       pre-FEC raw BER
	 * @param ?float     $effectiveBer    post-FEC effective BER
	 * @param ?float     $correctedRate   corrected codewords per second
	 * @param ?float     $uncorrectedRate uncorrected codewords per second
	 * @param ?LinkSpeed $speed           active link speed
	 *
	 * @return array{health: FecHealthLevel, reasons: list<string>}
	 */
	public static function classify(
		?float $preFecBer,
		?float $effectiveBer,
		?float $correctedRate,
		?float $uncorrectedRate,
		?LinkSpeed $speed = null,
	): array {
		[$berWarn, $berCrit] = match ($speed) {
			LinkSpeed::Ndr => [self::NDR_PRE_FEC_BER_DEGRADED, self::NDR_PRE_FEC_BER_CRITICAL],
			LinkSpeed::Xdr => [self::XDR_PRE_FEC_BER_DEGRADED, self::XDR_PRE_FEC_BER_CRITICAL],
			default        => [self::DEFAULT_PRE_FEC_BER_DEGRADED, self::DEFAULT_PRE_FEC_BER_CRITICAL],
		};

		$hasMetric = false;
		$health    = FecHealthLevel::Unknown;
		$reasons   = [];

		if ($preFecBer !== null && is_finite($preFecBer) && $preFecBer >= 0.0) {
			$hasMetric = true;
			$health    = FecHealthLevel::worst($health, FecHealthLevel::Healthy);

			if ($preFecBer >= $berCrit) {
				$health    = FecHealthLevel::worst($health, FecHealthLevel::Critical);
				$reasons[] = 'pre_fec_ber_critical';
			} elseif ($preFecBer >= $berWarn) {
				$health    = FecHealthLevel::worst($health, FecHealthLevel::Degraded);
				$reasons[] = 'pre_fec_ber_degraded';
			}
		}

		if ($effectiveBer !== null && is_finite($effectiveBer) && $effectiveBer >= 0.0) {
			$hasMetric = true;
			$health    = FecHealthLevel::worst($health, FecHealthLevel::Healthy);

			if ($effectiveBer >= self::EFFECTIVE_BER_CRITICAL) {
				$health    = FecHealthLevel::worst($health, FecHealthLevel::Critical);
				$reasons[] = 'effective_ber_critical';
			} elseif ($effectiveBer >= self::EFFECTIVE_BER_DEGRADED) {
				$health    = FecHealthLevel::worst($health, FecHealthLevel::Degraded);
				$reasons[] = 'effective_ber_degraded';
			}
		}

		if ($uncorrectedRate !== null && is_finite($uncorrectedRate) && $uncorrectedRate >= 0.0) {
			$hasMetric = true;
			$health    = FecHealthLevel::worst($health, FecHealthLevel::Healthy);

			if ($uncorrectedRate >= self::UNCORRECTED_RATE_CRITICAL) {
				$health    = FecHealthLevel::worst($health, FecHealthLevel::Critical);
				$reasons[] = 'fec_uncorrected_critical';
			} elseif ($uncorrectedRate > 0.0) {
				$health    = FecHealthLevel::worst($health, FecHealthLevel::Degraded);
				$reasons[] = 'fec_uncorrected_nonzero';
			}
		}

		if ($correctedRate !== null && is_finite($correctedRate) && $correctedRate >= 0.0) {
			$hasMetric = true;
			$health    = FecHealthLevel::worst($health, FecHealthLevel::Healthy);

			if ($correctedRate >= self::CORRECTED_RATE_CRITICAL) {
				$health    = FecHealthLevel::worst($health, FecHealthLevel::Critical);
				$reasons[] = 'fec_corrected_rate_critical';
			} elseif ($correctedRate >= self::CORRECTED_RATE_DEGRADED) {
				$health    = FecHealthLevel::worst($health, FecHealthLevel::Degraded);
				$reasons[] = 'fec_corrected_rate_degraded';
			}
		}

		if (!$hasMetric) {
			return ['health' => FecHealthLevel::Unknown, 'reasons' => []];
		}

		sort($reasons);

		return ['health' => $health, 'reasons' => $reasons];
	}

	/**
	 * Convert the FEC telemetry sample to a plain associative array.
	 *
	 * @return array{
	 *   port: string,
	 *   speed: ?string,
	 *   fec_corrected_codewords_per_sec: ?float,
	 *   fec_uncorrected_codewords_per_sec: ?float,
	 *   raw_ber: ?float,
	 *   effective_ber: ?float,
	 *   pre_fec_ber: ?float,
	 *   health: string,
	 *   reasons: list<string>
	 * }
	 */
	public function toArray(): array {
		return [
			'port'                              => $this->portKey,
			'speed'                             => $this->speed?->value,
			'fec_corrected_codewords_per_sec'   => $this->correctedCodewordsPerSec,
			'fec_uncorrected_codewords_per_sec' => $this->uncorrectedCodewordsPerSec,
			'raw_ber'                           => $this->rawBer,
			'effective_ber'                     => $this->effectiveBer,
			'pre_fec_ber'                       => $this->preFecBer,
			'health'                            => $this->health->value,
			'reasons'                           => $this->reasons,
		];
	}

	/**
	 * Compute a per-second rate for the first matching counter key across two
	 * snapshots, returning null on missing values, invalid timestamps, or
	 * counter discontinuities.
	 *
	 * @param array<string, ?string>|null $previous previous counter map
	 * @param int                         $prevAt   previous Unix timestamp
	 * @param array<string, ?string>      $current  current counter map
	 * @param int                         $now      current Unix timestamp
	 * @param list<string>                $keys     candidate counter keys
	 *
	 * @return ?float
	 */
	private static function counterRate(?array $previous, int $prevAt, array $current, int $now, array $keys): ?float {
		if ($previous === null || $prevAt <= 0 || $now <= $prevAt) {
			return null;
		}

		foreach ($keys as $key) {
			if (!array_key_exists($key, $current) || !array_key_exists($key, $previous)) {
				continue;
			}

			$cur  = $current[$key];
			$prev = $previous[$key];

			if ($cur === null || $prev === null || !Decimal::valid($cur) || !Decimal::valid($prev)) {
				return null;
			}

			if (Decimal::compare($cur, $prev) < 0) {
				return null;
			}

			return Decimal::difference($cur, $prev) / ($now - $prevAt);
		}

		return null;
	}

	/**
	 * Resolve a speed descriptor (such as "NDR", "400G NDR", "800G XDR",
	 * "106.25 Gbps") into a LinkSpeed enum.
	 *
	 * @param string $raw speed string
	 *
	 * @return ?LinkSpeed
	 */
	private static function resolveSpeed(string $raw): ?LinkSpeed {
		$direct = LinkSpeed::fromName($raw) ?? LinkSpeed::fromRate($raw);

		if ($direct !== null) {
			return $direct;
		}

		$upper = strtoupper($raw);

		return match (true) {
			str_contains($upper, 'XDR') || str_contains($upper, '800G') => LinkSpeed::Xdr,
			str_contains($upper, 'NDR') || str_contains($upper, '400G') => LinkSpeed::Ndr,
			str_contains($upper, 'HDR') || str_contains($upper, '200G') => LinkSpeed::Hdr,
			str_contains($upper, 'EDR') || str_contains($upper, '100G') => LinkSpeed::Edr,
			default                                                      => null,
		};
	}

	/**
	 * Parse a non-negative finite floating-point metric (including scientific
	 * notation such as "1.2E-6" or "1e-15").
	 *
	 * @param mixed $raw raw value
	 *
	 * @return ?float
	 */
	private static function parseFloat(mixed $raw): ?float {
		if (is_int($raw) || is_float($raw)) {
			return self::validRate((float) $raw);
		}

		if (!is_string($raw)) {
			return null;
		}

		$trimmed = trim($raw);

		if ($trimmed === '' || strcasecmp($trimmed, 'N/A') === 0 || $trimmed === '-') {
			return null;
		}

		if (preg_match('/^([+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?)$/', $trimmed, $m) === 1) {
			return self::validRate((float) $m[1]);
		}

		return null;
	}

	/**
	 * Validate that a rate or BER is finite and non-negative.
	 *
	 * @param ?float $value candidate value
	 *
	 * @return ?float
	 */
	private static function validRate(?float $value): ?float {
		if ($value === null || !is_finite($value) || $value < 0.0) {
			return null;
		}

		return $value;
	}
}
