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

	private const MAX = [4 => '15', 8 => '255', 16 => '65535', 32 => '4294967295', 64 => '18446744073709551615'];

	/**
	 * @return list<Counters> in print order
	 */
	public function parse(string $output): array {
		$blocks = [];
		$header = null;
		$fields = [];
		$start  = 0;

		foreach (preg_split('/\r?\n/', $output) ?: [] as $index => $line) {
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

			if ($header === null || preg_match('/^([A-Za-z0-9]{1,40}):\.*(0x[0-9a-f]{1,16}|[0-9]{1,20})$/', $line, $m) !== 1) {
				throw ParseFailure::at($number, 'unrecognized line');
			}

			$fields[] = [$m[1], $m[2], $number];
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

		$layouts = $group === CounterGroup::Basic
			? [self::BASIC]
			: [self::EXTENDED, self::EXTENDED + self::EXTENDED_CAST, self::EXTENDED + self::EXTENDED_ERRORS, self::EXTENDED + self::EXTENDED_CAST + self::EXTENDED_ERRORS];

		$names  = array_column($fields, 0);
		$layout = null;

		foreach ($layouts as $candidate) {
			if ($names === array_keys($candidate)) {
				$layout = $candidate;
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

	private static function fits(string $value, int $width): bool {
		if (preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
			return false;
		}

		$max = self::MAX[$width];

		return strlen($value) < strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) <= 0);
	}
}
