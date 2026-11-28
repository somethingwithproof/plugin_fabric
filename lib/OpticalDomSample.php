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
 * Digital Optical Monitoring (DOM) telemetry snapshot for one optical
 * transceiver port: module temperature (C), TX/RX optical power (dBm),
 * laser bias current (mA), optional multi-lane readings, and threshold
 * warning/alarm classification.
 */
final readonly class OpticalDomSample {
	/** Default module high-temperature warning threshold in Celsius. */
	public const DEFAULT_TEMP_WARN_HIGH = 70.0;

	/** Default module high-temperature alarm threshold in Celsius. */
	public const DEFAULT_TEMP_ALARM_HIGH = 80.0;

	/** Default module low-temperature warning threshold in Celsius. */
	public const DEFAULT_TEMP_WARN_LOW = 0.0;

	/** Default module low-temperature alarm threshold in Celsius. */
	public const DEFAULT_TEMP_ALARM_LOW = -5.0;

	/** Default TX optical power low warning threshold in dBm. */
	public const DEFAULT_TX_WARN_LOW = -6.0;

	/** Default TX optical power low alarm threshold in dBm. */
	public const DEFAULT_TX_ALARM_LOW = -9.0;

	/** Default TX optical power high warning threshold in dBm. */
	public const DEFAULT_TX_WARN_HIGH = 4.5;

	/** Default TX optical power high alarm threshold in dBm. */
	public const DEFAULT_TX_ALARM_HIGH = 6.5;

	/** Default RX optical power low warning threshold in dBm. */
	public const DEFAULT_RX_WARN_LOW = -9.0;

	/** Default RX optical power low alarm threshold in dBm. */
	public const DEFAULT_RX_ALARM_LOW = -12.0;

	/** Default RX optical power high warning threshold in dBm. */
	public const DEFAULT_RX_WARN_HIGH = 4.5;

	/** Default RX optical power high alarm threshold in dBm. */
	public const DEFAULT_RX_ALARM_HIGH = 6.5;

	/** Default laser bias current high warning threshold in mA. */
	public const DEFAULT_BIAS_WARN_HIGH = 85.0;

	/** Default laser bias current high alarm threshold in mA. */
	public const DEFAULT_BIAS_ALARM_HIGH = 100.0;

	/** Default laser bias current low warning threshold in mA. */
	public const DEFAULT_BIAS_WARN_LOW = 1.0;

	/** Default laser bias current low alarm threshold in mA. */
	public const DEFAULT_BIAS_ALARM_LOW = 0.5;

	/**
	 * Construct an immutable optical transceiver DOM sample.
	 *
	 * @param string      $portKey            canonical port identifier
	 * @param ?float      $temperatureC       module temperature in Celsius
	 * @param ?float      $txPowerDbm         aggregate or worst-lane TX optical power in dBm
	 * @param ?float      $rxPowerDbm         aggregate or worst-lane RX optical power in dBm
	 * @param ?float      $biasCurrentMa      aggregate or max-lane laser bias current in mA
	 * @param DomSeverity $severity           overall DOM warning/alarm classification
	 * @param list<string> $alerts            sorted list of active threshold alert codes
	 * @param list<float> $txPowerLanesDbm    per-lane TX optical power in dBm
	 * @param list<float> $rxPowerLanesDbm    per-lane RX optical power in dBm
	 * @param list<float> $biasCurrentLanesMa per-lane laser bias current in mA
	 * @param ?float      $voltageV           transceiver supply voltage in Volts
	 * @param string      $partNumber         sanitized transceiver vendor part number
	 */
	public function __construct(
		public string $portKey,
		public ?float $temperatureC,
		public ?float $txPowerDbm,
		public ?float $rxPowerDbm,
		public ?float $biasCurrentMa,
		public DomSeverity $severity = DomSeverity::Unknown,
		public array $alerts = [],
		public array $txPowerLanesDbm = [],
		public array $rxPowerLanesDbm = [],
		public array $biasCurrentLanesMa = [],
		public ?float $voltageV = null,
		public string $partNumber = '',
	) {
	}

	/**
	 * Classify DOM metrics against default or transceiver-reported SFF-8636/CMIS
	 * warning and alarm thresholds.
	 *
	 * @param ?float              $temperatureC       module temperature in Celsius
	 * @param ?float              $txPowerDbm         scalar TX optical power in dBm
	 * @param ?float              $rxPowerDbm         scalar RX optical power in dBm
	 * @param ?float              $biasCurrentMa      scalar laser bias current in mA
	 * @param list<float>         $txPowerLanesDbm    optional per-lane TX power values in dBm
	 * @param list<float>         $rxPowerLanesDbm    optional per-lane RX power values in dBm
	 * @param list<float>         $biasCurrentLanesMa optional per-lane bias values in mA
	 * @param array<string, float> $thresholds        optional threshold overrides
	 *
	 * @return array{severity: DomSeverity, alerts: list<string>}
	 */
	public static function classify(
		?float $temperatureC,
		?float $txPowerDbm,
		?float $rxPowerDbm,
		?float $biasCurrentMa,
		array $txPowerLanesDbm = [],
		array $rxPowerLanesDbm = [],
		array $biasCurrentLanesMa = [],
		array $thresholds = [],
	): array {
		$hasMetric = false;
		$severity  = DomSeverity::Unknown;
		$alerts    = [];

		if ($temperatureC !== null && is_finite($temperatureC)) {
			$hasMetric = true;
			$severity  = DomSeverity::worst($severity, DomSeverity::Ok);
			self::checkRange(
				$temperatureC,
				$thresholds['temp_alarm_low'] ?? self::DEFAULT_TEMP_ALARM_LOW,
				$thresholds['temp_warn_low'] ?? self::DEFAULT_TEMP_WARN_LOW,
				$thresholds['temp_warn_high'] ?? self::DEFAULT_TEMP_WARN_HIGH,
				$thresholds['temp_alarm_high'] ?? self::DEFAULT_TEMP_ALARM_HIGH,
				'temp',
				$severity,
				$alerts,
			);
		}

		$txSamples = $txPowerLanesDbm !== [] ? $txPowerLanesDbm : ($txPowerDbm !== null && is_finite($txPowerDbm) ? [$txPowerDbm] : []);

		foreach ($txSamples as $val) {
			if (!is_finite($val)) {
				continue;
			}

			$hasMetric = true;
			$severity  = DomSeverity::worst($severity, DomSeverity::Ok);
			self::checkRange(
				$val,
				$thresholds['tx_alarm_low'] ?? self::DEFAULT_TX_ALARM_LOW,
				$thresholds['tx_warn_low'] ?? self::DEFAULT_TX_WARN_LOW,
				$thresholds['tx_warn_high'] ?? self::DEFAULT_TX_WARN_HIGH,
				$thresholds['tx_alarm_high'] ?? self::DEFAULT_TX_ALARM_HIGH,
				'tx_power',
				$severity,
				$alerts,
			);
		}

		$rxSamples = $rxPowerLanesDbm !== [] ? $rxPowerLanesDbm : ($rxPowerDbm !== null && is_finite($rxPowerDbm) ? [$rxPowerDbm] : []);

		foreach ($rxSamples as $val) {
			if (!is_finite($val)) {
				continue;
			}

			$hasMetric = true;
			$severity  = DomSeverity::worst($severity, DomSeverity::Ok);
			self::checkRange(
				$val,
				$thresholds['rx_alarm_low'] ?? self::DEFAULT_RX_ALARM_LOW,
				$thresholds['rx_warn_low'] ?? self::DEFAULT_RX_WARN_LOW,
				$thresholds['rx_warn_high'] ?? self::DEFAULT_RX_WARN_HIGH,
				$thresholds['rx_alarm_high'] ?? self::DEFAULT_RX_ALARM_HIGH,
				'rx_power',
				$severity,
				$alerts,
			);
		}

		$biasSamples = $biasCurrentLanesMa !== [] ? $biasCurrentLanesMa : ($biasCurrentMa !== null && is_finite($biasCurrentMa) ? [$biasCurrentMa] : []);

		foreach ($biasSamples as $val) {
			if (!is_finite($val)) {
				continue;
			}

			$hasMetric = true;
			$severity  = DomSeverity::worst($severity, DomSeverity::Ok);
			self::checkRange(
				$val,
				$thresholds['bias_alarm_low'] ?? self::DEFAULT_BIAS_ALARM_LOW,
				$thresholds['bias_warn_low'] ?? self::DEFAULT_BIAS_WARN_LOW,
				$thresholds['bias_warn_high'] ?? self::DEFAULT_BIAS_WARN_HIGH,
				$thresholds['bias_alarm_high'] ?? self::DEFAULT_BIAS_ALARM_HIGH,
				'bias',
				$severity,
				$alerts,
			);
		}

		if (!$hasMetric) {
			return ['severity' => DomSeverity::Unknown, 'alerts' => []];
		}

		$uniqueAlerts = array_values(array_unique($alerts));
		sort($uniqueAlerts);

		return ['severity' => $severity, 'alerts' => $uniqueAlerts];
	}

	/**
	 * Convert the optical DOM sample to a plain associative array.
	 *
	 * @return array{
	 *   port: string,
	 *   temperature_c: ?float,
	 *   tx_power_dbm: ?float,
	 *   rx_power_dbm: ?float,
	 *   bias_current_ma: ?float,
	 *   voltage_v: ?float,
	 *   tx_power_lanes_dbm: list<float>,
	 *   rx_power_lanes_dbm: list<float>,
	 *   bias_current_lanes_ma: list<float>,
	 *   severity: string,
	 *   alerts: list<string>,
	 *   part_number: string
	 * }
	 */
	public function toArray(): array {
		return [
			'port'                  => $this->portKey,
			'temperature_c'         => $this->temperatureC,
			'tx_power_dbm'          => $this->txPowerDbm,
			'rx_power_dbm'          => $this->rxPowerDbm,
			'bias_current_ma'       => $this->biasCurrentMa,
			'voltage_v'             => $this->voltageV,
			'tx_power_lanes_dbm'    => $this->txPowerLanesDbm,
			'rx_power_lanes_dbm'    => $this->rxPowerLanesDbm,
			'bias_current_lanes_ma' => $this->biasCurrentLanesMa,
			'severity'              => $this->severity->value,
			'alerts'                => $this->alerts,
			'part_number'           => $this->partNumber,
		];
	}

	/**
	 * Evaluate one numeric reading against low/high alarm and warning bounds.
	 *
	 * @param float        $value     measured metric value
	 * @param float        $alarmLow  lower alarm bound
	 * @param float        $warnLow   lower warning bound
	 * @param float        $warnHigh  upper warning bound
	 * @param float        $alarmHigh upper alarm bound
	 * @param string       $prefix    metric alert code prefix
	 * @param DomSeverity  $severity  current worst severity (updated in place)
	 * @param list<string> $alerts    collected alert codes (appended in place)
	 *
	 * @return void
	 */
	private static function checkRange(
		float $value,
		float $alarmLow,
		float $warnLow,
		float $warnHigh,
		float $alarmHigh,
		string $prefix,
		DomSeverity &$severity,
		array &$alerts,
	): void {
		if ($value >= $alarmHigh) {
			$severity = DomSeverity::worst($severity, DomSeverity::Alarm);
			$alerts[] = $prefix . '_high_alarm';
		} elseif ($value >= $warnHigh) {
			$severity = DomSeverity::worst($severity, DomSeverity::Warning);
			$alerts[] = $prefix . '_high_warning';
		}

		if ($value <= $alarmLow) {
			$severity = DomSeverity::worst($severity, DomSeverity::Alarm);
			$alerts[] = $prefix . '_low_alarm';
		} elseif ($value <= $warnLow) {
			$severity = DomSeverity::worst($severity, DomSeverity::Warning);
			$alerts[] = $prefix . '_low_warning';
		}
	}
}
