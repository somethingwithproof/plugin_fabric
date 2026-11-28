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
 * One counter block perfquery printed for one port. Values are decimal
 * strings exactly as received, because the extended counters are unsigned
 * 64-bit and exceed PHP's integer range; nothing is scaled or reset
 * (REQ-SWEEP-007). A counter the port does not support is null, never 0.
 */
final readonly class Counters {
	/** PortCounters:CapMask bits (IBA 16.1.3.1, rdma-core mad.h). */
	public const CAP_EXT_WIDTH        = 0x200;
	/** Cap ext width noietf constant for Counters. */
	public const CAP_EXT_WIDTH_NOIETF = 0x400;
	/** Cap xmit wait constant for Counters. */
	public const CAP_XMIT_WAIT        = 0x1000;

	/**
	 * @param array<string, ?string> $values counter name => decimal string, or null when unsupported
	 */
	public function __construct(
		public CounterGroup $group,
		public int $lid,
		public int $port,
		public int $capMask,
		public ?int $capMask2,
		public array $values,
	) {
	}

	/**
	 * Whether the port's agent says it has the extended counters, the
	 * condition for running perfquery -x at all.
	 */
	public function extendedSupported(): bool {
		return ($this->capMask & (self::CAP_EXT_WIDTH | self::CAP_EXT_WIDTH_NOIETF)) !== 0;
	}

	/**
	 * To json.
	 *
	 * @return string
	 */
	public function toJson(): string {
		return json_encode($this->values, JSON_THROW_ON_ERROR);
	}

	/**
	 * Stored values back from the sample table, or null for a damaged
	 * column. Each value must be a decimal string or null.
	 *
	 * @return array<string, ?string>|null
	 */
	public static function valuesFromJson(string $json): ?array {
		$data = json_decode($json, true);

		if (!is_array($data)) {
			return null;
		}

		$out = [];

		foreach ($data as $name => $value) {
			if (!is_string($name) || ($value !== null && (!is_string($value) || preg_match('/^[0-9]{1,20}$/D', $value) !== 1))) {
				return null;
			}

			$out[$name] = $value;
		}

		return $out;
	}
}
