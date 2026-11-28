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
 * Parses perfquery output: one block per port, each a "# Port counters"
 * or "# Port extended counters" header and then one "Name:....value" line
 * per field. Formats and field lists are those of rdma-core v66.0
 * infiniband-diags/perfquery.c dump_perfcounters() and libibmad dump.c and
 * fields.c, the same since v39.0.
 *
 * perfquery stops at the first port that fails and exits non-zero after
 * printing the ports before it, so the caller parses what was printed and
 * treats the first port missing from it as failed. A block is accepted only
 * whole: every field of its group present once, in order, each value
 * within its counter's width. A block that is not is a ParseFailure.
 */
final class PerfqueryParser {
	/** PortCounters fields in print order with their widths in bits; 0 marks a hex selector. */
	public const BASIC = [
		'PortSelect'                   => 8,
		'CounterSelect'                => 0,
		'SymbolErrorCounter'           => 16,
		'LinkErrorRecoveryCounter'     => 8,
		'LinkDownedCounter'            => 8,
		'PortRcvErrors'                => 16,
		'PortRcvRemotePhysicalErrors'  => 16,
		'PortRcvSwitchRelayErrors'     => 16,
		'PortXmitDiscards'             => 16,
		'PortXmitConstraintErrors'     => 8,
		'PortRcvConstraintErrors'      => 8,
		'CounterSelect2'               => 0,
		'LocalLinkIntegrityErrors'     => 4,
		'ExcessiveBufferOverrunErrors' => 4,
		'QP1Dropped'                   => 16,
		'VL15Dropped'                  => 16,
		'PortXmitData'                 => 32,
		'PortRcvData'                  => 32,
		'PortXmitPkts'                 => 32,
		'PortRcvPkts'                  => 32,
		'PortXmitWait'                 => 32,
	];

	/** PortCountersExtended fields always printed. */
	public const EXTENDED = [
		'PortSelect'    => 8,
		'CounterSelect' => 0,
		'PortXmitData'  => 64,
		'PortRcvData'   => 64,
		'PortXmitPkts'  => 64,
		'PortRcvPkts'   => 64,
	];

	/** Printed when CapMask has the extended width bit. */
	public const EXTENDED_CAST = [
		'PortUnicastXmitPkts'   => 64,
		'PortUnicastRcvPkts'    => 64,
		'PortMulticastXmitPkts' => 64,
		'PortMulticastRcvPkts'  => 64,
	];

	/** Printed when CapMask2 has the additional extended counters bit. */
	public const EXTENDED_ERRORS = [
		'CounterSelect2'               => 0,
		'SymbolErrorCounter'           => 64,
		'LinkErrorRecoveryCounter'     => 64,
		'LinkDownedCounter'            => 64,
		'PortRcvErrors'                => 64,
		'PortRcvRemotePhysicalErrors'  => 64,
		'PortRcvSwitchRelayErrors'     => 64,
		'PortXmitDiscards'             => 64,
		'PortXmitConstraintErrors'     => 64,
		'PortRcvConstraintErrors'      => 64,
		'LocalLinkIntegrityErrors'     => 64,
		'ExcessiveBufferOverrunErrors' => 64,
		'VL15Dropped'                  => 64,
		'PortXmitWait'                 => 64,
		'QP1Dropped'                   => 64,
	];

	/** Combined extended counter widths keyed by field name for O(1) lookup. */
	private const EXTENDED_ALL = self::EXTENDED + self::EXTENDED_CAST + self::EXTENDED_ERRORS;

	/** Precomputed extended layout candidates indexed by field count for O(1) dispatch. */
	private const EXTENDED_LAYOUTS = [
		6  => self::EXTENDED,
		10 => self::EXTENDED + self::EXTENDED_CAST,
		21 => self::EXTENDED + self::EXTENDED_ERRORS,
		25 => self::EXTENDED_ALL,
	];

	/**
	 * Cached ordered field-name lists per extended layout size.
	 *
	 * @var array<int, list<string>>|null
	 */
	private static ?array $extendedKeys = null;

	/**
	 * Cached ordered field-name list for BASIC layout.
	 *
	 * @var list<string>|null
	 */
	private static ?array $basicKeys = null;

	/** Max constant for PerfqueryParser. */
	private const MAX = [4 => '15', 8 => '255', 16 => '65535', 32 => '4294967295', 64 => '18446744073709551615'];

	/**
	 * Cached counter name => cap decimal string per CounterGroup value.
	 *
	 * @var array<string, array<string, string>>|null
	 */
	private static ?array $capsByGroup = null;

	/**
	 * The width in bits of a counter in a group, or 0 for a selector or a
	 * name the group does not have.
	 */
	public static function width(CounterGroup $group, string $name): int {
		if ($name === 'PortSelect') {
			return 0;
		}

		return $group === CounterGroup::Basic ? (self::BASIC[$name] ?? 0) : (self::EXTENDED_ALL[$name] ?? 0);
	}

	/**
	 * Map every counter name in a group to its decimal cap string for fast
	 * O(1) lookup across a port's counter loop.
	 *
	 * @param CounterGroup $group counter group
	 *
	 * @return array<string, string>
	 */
	public static function caps(CounterGroup $group): array {
		if (self::$capsByGroup === null) {
			self::$capsByGroup = [];

			foreach ([CounterGroup::Basic->value => self::BASIC, CounterGroup::Extended->value => self::EXTENDED_ALL] as $key => $layout) {
				$map = [];

				foreach ($layout as $name => $width) {
					if ($name !== 'PortSelect' && $width > 0) {
						$map[$name] = self::MAX[$width];
					}
				}

				self::$capsByGroup[$key] = $map;
			}
		}

		return self::$capsByGroup[$group->value];
	}

	/**
	 * The largest value a counter of the width holds, where a basic counter
	 * stops (caps) instead of wrapping, as a decimal string; '' for 0.
	 */
	public static function cap(int $width): string {
		return self::MAX[$width] ?? '';
	}

	/**
	 * @return list<Counters> in print order
	 */
	public function parse(string $output): array {
		$blocks = [];
		$header = null;
		$fields = [];
		$start  = 0;

		foreach (explode("\n", $output) as $index => $line) {
			if ($line !== '' && $line[-1] === "\r") {
				$line = substr($line, 0, -1);
			}

			$number = $index + 1;

			if ($line === '') {
				continue;
			}

			if (str_starts_with($line, '#')) {
				if ($header !== null) {
					$blocks[] = $this->block($header, $fields, $start);
				}

				$header = $this->header($line, $number);
				$fields = [];
				$start  = $number;

				continue;
			}

			if ($header === null) {
				throw ParseFailure::at($number, 'unrecognized line');
			}

			$colon = strpos($line, ':');

			if ($colon === false || $colon < 1 || $colon > 40) {
				throw ParseFailure::at($number, 'unrecognized line');
			}

			$name = substr($line, 0, $colon);

			if (!ctype_alnum($name)) {
				throw ParseFailure::at($number, 'unrecognized line');
			}

			$rawValue = ltrim(substr($line, $colon + 1), '.');
			$valLen   = strlen($rawValue);

			if (str_starts_with($rawValue, '0x')) {
				$hex = substr($rawValue, 2);

				if ($hex === '' || strlen($hex) > 16 || !ctype_xdigit($hex) || strtolower($hex) !== $hex) {
					throw ParseFailure::at($number, 'unrecognized line');
				}
			} elseif ($valLen < 1 || $valLen > 20 || !ctype_digit($rawValue)) {
				throw ParseFailure::at($number, 'unrecognized line');
			}

			$fields[] = [$name, $rawValue, $number];
		}

		if ($header !== null) {
			$blocks[] = $this->block($header, $fields, $start);
		}

		return $blocks;
	}

	/**
	 * @return array{0: CounterGroup, 1: int, 2: int, 3: int, 4: ?int}
	 */
	private function header(string $line, int $number): array {
		if (preg_match('/^# Port counters: Lid (\d{1,5}) port (\d{1,3}) \(CapMask: 0x([0-9A-F]{2,4})\)$/', $line, $m) === 1) {
			return [CounterGroup::Basic, (int) $m[1], (int) $m[2], (int) hexdec($m[3]), null];
		}

		if (preg_match('/^# Port extended counters: Lid (\d{1,5}) port (\d{1,3}) \(CapMask: 0x([0-9A-F]{2,4}) CapMask2: 0x([0-9A-F]{7})\)$/', $line, $m) === 1) {
			return [CounterGroup::Extended, (int) $m[1], (int) $m[2], (int) hexdec($m[3]), (int) hexdec($m[4])];
		}

		throw ParseFailure::at($number, 'unrecognized header');
	}

	/**
	 * @param array{0: CounterGroup, 1: int, 2: int, 3: int, 4: ?int} $header
	 * @param list<array{0: string, 1: string, 2: int}>                $fields
	 */
	private function block(array $header, array $fields, int $start): Counters {
		[$group, $lid, $port, $capMask, $capMask2] = $header;

		if ($port < 1 || $port > PortRef::MAX_PORT) {
			throw ParseFailure::at($start, 'port out of range');
		}

		$fieldCount = count($fields);
		$layout     = null;

		if ($group === CounterGroup::Basic) {
			if ($fieldCount === count(self::BASIC)) {
				self::$basicKeys ??= array_keys(self::BASIC);

				if (array_column($fields, 0) === self::$basicKeys) {
					$layout = self::BASIC;
				}
			}
		} elseif (isset(self::EXTENDED_LAYOUTS[$fieldCount])) {
			if (self::$extendedKeys === null) {
				self::$extendedKeys = [];

				foreach (self::EXTENDED_LAYOUTS as $size => $candidate) {
					self::$extendedKeys[$size] = array_keys($candidate);
				}
			}

			if (array_column($fields, 0) === self::$extendedKeys[$fieldCount]) {
				$layout = self::EXTENDED_LAYOUTS[$fieldCount];
			}
		}

		if ($layout === null) {
			throw ParseFailure::at($start, 'counter fields incomplete, repeated, or out of order');
		}

		$values = [];

		foreach ($fields as [$name, $value, $number]) {
			$width = $layout[$name];

			if ($width === 0) {
				if (!str_starts_with($value, '0x')) {
					throw ParseFailure::at($number, 'selector is not hexadecimal');
				}

				continue;
			}

			if (!self::fits($value, $width)) {
				throw ParseFailure::at($number, 'counter value out of range');
			}

			if ($name !== 'PortSelect') {
				$values[$name] = $value;
			}
		}

		// perfquery prints 0 for PortXmitWait when the agent does not
		// support it; that 0 is not a measurement.
		if ($group === CounterGroup::Basic && ($capMask & Counters::CAP_XMIT_WAIT) === 0) {
			$values['PortXmitWait'] = null;
		}

		return new Counters($group, $lid, $port, $capMask, $capMask2, $values);
	}

	/**
	 * Fits.
	 *
	 * @param string $value
	 * @param int $width
	 * @return bool
	 */
	private static function fits(string $value, int $width): bool {
		if ($value === '' || !ctype_digit($value) || ($value !== '0' && $value[0] === '0')) {
			return false;
		}

		$max    = self::MAX[$width];
		$valLen = strlen($value);
		$maxLen = strlen($max);

		return $valLen < $maxLen || ($valLen === $maxLen && strcmp($value, $max) <= 0);
	}
}
