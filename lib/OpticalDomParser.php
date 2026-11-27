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
 * Parses read-only optical transceiver DOM telemetry payloads from mlxlink
 * JSON output and NVIDIA UFM cable-info / transceiver JSON responses into
 * normalized OpticalDomSample records.
 */
final class OpticalDomParser {
	/** Maximum JSON nesting depth accepted for transceiver payloads. */
	private const JSON_DEPTH = 32;

	/**
	 * Parse an mlxlink JSON output payload ("mlxlink -m --json") into an
	 * OpticalDomSample.
	 *
	 * @param string $json            raw JSON output from mlxlink
	 * @param string $fallbackPortKey port identifier used if absent in payload
	 *
	 * @return OpticalDomSample
	 *
	 * @throws ParseFailure
	 */
	public function parseMlxlinkJson(string $json, string $fallbackPortKey = ''): OpticalDomSample {
		try {
			$decoded = json_decode($json, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw ParseFailure::at(0, 'mlxlink JSON is malformed');
		}

		if (!is_array($decoded)) {
			throw ParseFailure::at(0, 'mlxlink JSON root is not an object');
		}

		$result = isset($decoded['result']) && is_array($decoded['result']) ? $decoded['result'] : $decoded;
		$output = isset($result['output']) && is_array($result['output']) ? $result['output'] : $result;
		$module = isset($output['Module Info']) && is_array($output['Module Info']) ? $output['Module Info'] : $output;

		$portKey = $fallbackPortKey !== '' ? $fallbackPortKey : self::firstString($output, ['port', 'Port', 'device', 'Device']);
		$temp    = self::parseScalar($module['Temperature [C]'] ?? $module['temperature'] ?? null);
		$txLanes = self::parseLanes($module['Tx Power Current [dBm]'] ?? $module['tx_power_dbm'] ?? $module['tx_power'] ?? null);
		$rxLanes = self::parseLanes($module['Rx Power Current [dBm]'] ?? $module['rx_power_dbm'] ?? $module['rx_power'] ?? null);
		$biasL   = self::parseLanes($module['Bias Current [mA]'] ?? $module['bias_current_ma'] ?? $module['bias_current'] ?? null);

		$txScalar   = $txLanes !== [] ? min($txLanes) : null;
		$rxScalar   = $rxLanes !== [] ? min($rxLanes) : null;
		$biasScalar = $biasL !== [] ? max($biasL) : null;

		$voltageMv = self::parseScalar($module['Voltage [mV]'] ?? null);
		$voltageV  = $voltageMv !== null ? round($voltageMv / 1000.0, 4) : self::parseScalar($module['voltage_v'] ?? $module['Voltage [V]'] ?? null);

		$thresholds = self::extractThresholds($module);
		$classified = OpticalDomSample::classify($temp, $txScalar, $rxScalar, $biasScalar, $txLanes, $rxLanes, $biasL, $thresholds);
		$part       = self::cleanAscii(self::firstString($module, ['Vendor Part Number', 'part_number', 'Part Number']));

		return new OpticalDomSample(
			portKey: $portKey,
			temperatureC: $temp,
			txPowerDbm: $txScalar,
			rxPowerDbm: $rxScalar,
			biasCurrentMa: $biasScalar,
			severity: $classified['severity'],
			alerts: $classified['alerts'],
			txPowerLanesDbm: $txLanes,
			rxPowerLanesDbm: $rxLanes,
			biasCurrentLanesMa: $biasL,
			voltageV: $voltageV,
			partNumber: $part,
		);
	}

	/**
	 * Parse UFM cable-info or transceiver JSON payload (paged envelope, list,
	 * or port-keyed map) into OpticalDomSample instances keyed by canonical
	 * port identifier.
	 *
	 * @param array<array-key, mixed> $payload decoded UFM cable-info JSON
	 *
	 * @return array<string, OpticalDomSample>
	 *
	 * @throws ParseFailure
	 */
	public function parseUfmCableInfo(array $payload): array {
		$items = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
		$out   = [];

		foreach ($items as $key => $entry) {
			if (!is_array($entry)) {
				throw ParseFailure::at(0, 'UFM cable-info entry is not an object');
			}

			$portKey = self::resolvePortKey($entry, is_string($key) ? $key : '');

			if ($portKey === '') {
				continue;
			}

			$temp    = self::parseScalar($entry['temperature'] ?? $entry['module_temperature'] ?? $entry['temperature_c'] ?? null);
			$txLanes = self::parseLanes($entry['tx_power_lanes_dbm'] ?? $entry['tx_power'] ?? $entry['tx_optical_power'] ?? $entry['tx_power_dbm'] ?? null);
			$rxLanes = self::parseLanes($entry['rx_power_lanes_dbm'] ?? $entry['rx_power'] ?? $entry['rx_optical_power'] ?? $entry['rx_power_dbm'] ?? null);
			$biasL   = self::parseLanes($entry['bias_current_lanes_ma'] ?? $entry['bias_current'] ?? $entry['laser_bias_current'] ?? $entry['bias_current_ma'] ?? null);

			$txScalar   = $txLanes !== [] ? min($txLanes) : null;
			$rxScalar   = $rxLanes !== [] ? min($rxLanes) : null;
			$biasScalar = $biasL !== [] ? max($biasL) : null;
			$voltageV   = self::parseScalar($entry['voltage_v'] ?? $entry['voltage'] ?? null);

			if ($voltageV !== null && $voltageV > 100.0) {
				$voltageV = round($voltageV / 1000.0, 4);
			}

			$thresholds    = self::extractThresholds($entry);
			$classified    = OpticalDomSample::classify($temp, $txScalar, $rxScalar, $biasScalar, $txLanes, $rxLanes, $biasL, $thresholds);
			$part          = self::cleanAscii(self::firstString($entry, ['part_number', 'vendor_part_number', 'Part Number']));
			$out[$portKey] = new OpticalDomSample(
				portKey: $portKey,
				temperatureC: $temp,
				txPowerDbm: $txScalar,
				rxPowerDbm: $rxScalar,
				biasCurrentMa: $biasScalar,
				severity: $classified['severity'],
				alerts: $classified['alerts'],
				txPowerLanesDbm: $txLanes,
				rxPowerLanesDbm: $rxLanes,
				biasCurrentLanesMa: $biasL,
				voltageV: $voltageV,
				partNumber: $part,
			);
		}

		return $out;
	}

	/**
	 * Resolve a canonical port key ("0x<guid>:<port>" or explicit port key)
	 * from a UFM transceiver record.
	 *
	 * @param array<array-key, mixed> $entry       transceiver record
	 * @param string                  $fallbackKey map key when payload is keyed
	 *
	 * @return string
	 */
	private static function resolvePortKey(array $entry, string $fallbackKey): string {
		$portName = self::firstString($entry, ['port_name', 'name']);

		if ($portName === '' && $fallbackKey !== '') {
			$portName = $fallbackKey;
		}

		if ($portName !== '' && preg_match('/^(?:0x)?([0-9A-Fa-f]{1,16})[_:]([0-9]{1,3})$/D', $portName, $m) === 1) {
			$guid = Guid::tryFromString($m[1]);
			$port = (int) $m[2];

			if ($guid !== null && $port >= 1 && $port <= PortRef::MAX_PORT) {
				return $guid->text . ':' . $port;
			}
		}

		$rawGuid = self::firstString($entry, ['systemID', 'port_guid', 'guid']);
		$rawPort = $entry['port_number'] ?? $entry['number'] ?? $entry['port'] ?? 0;
		$portNum = is_numeric($rawPort) ? (int) $rawPort : 0;
		$guid    = $rawGuid !== '' ? Guid::tryFromString($rawGuid) : null;

		if ($guid !== null && $portNum >= 1 && $portNum <= PortRef::MAX_PORT) {
			return $guid->text . ':' . $portNum;
		}

		return self::cleanAscii($portName);
	}

	/**
	 * Parse a single numeric DOM reading (int, float, or formatted string such
	 * as "42.5 C", "-2.1 dBm", "45 [70..80]").
	 *
	 * @param mixed $raw raw field value
	 *
	 * @return ?float
	 */
	private static function parseScalar(mixed $raw): ?float {
		if (is_int($raw) || is_float($raw)) {
			$f = (float) $raw;

			return is_finite($f) ? $f : null;
		}

		if (!is_string($raw)) {
			return null;
		}

		$trimmed = trim($raw);

		if ($trimmed === '' || strcasecmp($trimmed, 'N/A') === 0 || $trimmed === '-') {
			return null;
		}

		if (preg_match('/^([+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?)/', $trimmed, $m) === 1) {
			$f = (float) $m[1];

			return is_finite($f) ? $f : null;
		}

		return null;
	}

	/**
	 * Parse one or more lane DOM readings from a scalar, comma-separated
	 * string, or array of values.
	 *
	 * @param mixed $raw raw lane or scalar field value
	 *
	 * @return list<float>
	 */
	private static function parseLanes(mixed $raw): array {
		if (is_array($raw)) {
			$out = [];

			foreach ($raw as $item) {
				$v = self::parseScalar($item);

				if ($v !== null) {
					$out[] = $v;
				}
			}

			return $out;
		}

		if (is_string($raw)) {
			// Strip optional bracketed threshold suffix such as "-1.2,-1.4 [-9..4.5]".
			$clean = (string) preg_replace('/\[[^\]]*\]/', '', $raw);
			$parts = explode(',', $clean);
			$out   = [];

			foreach ($parts as $part) {
				$v = self::parseScalar($part);

				if ($v !== null) {
					$out[] = $v;
				}
			}

			return $out;
		}

		$scalar = self::parseScalar($raw);

		return $scalar !== null ? [$scalar] : [];
	}

	/**
	 * Extract optional transceiver-reported SFF-8636/CMIS thresholds.
	 *
	 * @param array<array-key, mixed> $row module or cable-info record
	 *
	 * @return array<string, float>
	 */
	private static function extractThresholds(array $row): array {
		$map = [
			'temp_alarm_high' => ['temp_alarm_high', 'high_temp_alarm'],
			'temp_warn_high'  => ['temp_warn_high', 'high_temp_warning'],
			'temp_warn_low'   => ['temp_warn_low', 'low_temp_warning'],
			'temp_alarm_low'  => ['temp_alarm_low', 'low_temp_alarm'],
			'tx_alarm_high'   => ['tx_alarm_high', 'tx_power_high_alarm'],
			'tx_warn_high'    => ['tx_warn_high', 'tx_power_high_warning'],
			'tx_warn_low'     => ['tx_warn_low', 'tx_power_low_warning'],
			'tx_alarm_low'    => ['tx_alarm_low', 'tx_power_low_alarm'],
			'rx_alarm_high'   => ['rx_alarm_high', 'rx_power_high_alarm'],
			'rx_warn_high'    => ['rx_warn_high', 'rx_power_high_warning'],
			'rx_warn_low'     => ['rx_warn_low', 'rx_power_low_warning'],
			'rx_alarm_low'    => ['rx_alarm_low', 'rx_power_low_alarm'],
			'bias_alarm_high' => ['bias_alarm_high', 'bias_high_alarm'],
			'bias_warn_high'  => ['bias_warn_high', 'bias_high_warning'],
			'bias_warn_low'   => ['bias_warn_low', 'bias_low_warning'],
			'bias_alarm_low'  => ['bias_alarm_low', 'bias_low_alarm'],
		];

		$out = [];

		foreach ($map as $target => $candidates) {
			foreach ($candidates as $candidate) {
				if (!array_key_exists($candidate, $row)) {
					continue;
				}

				$v = self::parseScalar($row[$candidate]);

				if ($v !== null) {
					$out[$target] = $v;

					break;
				}
			}
		}

		// mlxlink exports "[high_alarm, low_alarm, high_warn, low_warn]" arrays.
		$mlxlinkTriplets = [
			'temp' => 'Temperature Alarm and Warning Thresholds',
			'tx'   => 'Tx Power Alarm and Warning Thresholds',
			'rx'   => 'Rx Power Alarm and Warning Thresholds',
			'bias' => 'Bias Alarm and Warning Thresholds',
		];

		foreach ($mlxlinkTriplets as $prefix => $field) {
			$vals = self::parseLanes($row[$field] ?? null);

			if (count($vals) === 4) {
				$out[$prefix . '_alarm_high'] ??= $vals[0];
				$out[$prefix . '_alarm_low']  ??= $vals[1];
				$out[$prefix . '_warn_high']  ??= $vals[2];
				$out[$prefix . '_warn_low']   ??= $vals[3];
			}
		}

		return $out;
	}

	/**
	 * Read the first non-empty string field from a list of candidate keys.
	 *
	 * @param array<array-key, mixed> $row  source array
	 * @param list<string>            $keys candidate keys
	 *
	 * @return string
	 */
	private static function firstString(array $row, array $keys): string {
		foreach ($keys as $key) {
			$val = $row[$key] ?? null;

			if (is_scalar($val) && trim((string) $val) !== '') {
				return trim((string) $val);
			}
		}

		return '';
	}

	/**
	 * Strip non-printable ASCII characters and clip display strings to 64 bytes.
	 *
	 * @param string $value raw string
	 *
	 * @return string
	 */
	private static function cleanAscii(string $value): string {
		$clean = preg_replace('/[^\x20-\x7e]+/', ' ', $value);

		return substr(trim(is_string($clean) ? $clean : ''), 0, 64);
	}
}
