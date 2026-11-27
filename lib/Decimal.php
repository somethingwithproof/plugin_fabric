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
 * Arithmetic on the unsigned decimal strings counters are stored as. The
 * extended counters are 64 bits wide and exceed PHP's integer range, so
 * values are split into a high part and the last nine digits, each of which
 * fits an integer. Inputs are canonical: digits only, no leading zero.
 */
final class Decimal {
	/** Low constant for Decimal. */
	private const LOW = 9;

	/**
	 * Valid.
	 *
	 * @param string $value
	 * @return bool
	 */
	public static function valid(string $value): bool {
		$len = strlen($value);

		return $len >= 1 && $len <= 20 && ctype_digit($value) && ($len === 1 || $value[0] !== '0');
	}

	/**
	 * -1, 0, or 1 as $a is below, equal to, or above $b.
	 */
	public static function compare(string $a, string $b): int {
		return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
	}

	/**
	 * $a minus $b as a float, exact while the difference is below 2^53.
	 */
	public static function difference(string $a, string $b): float {
		$lenA = strlen($a);
		$lenB = strlen($b);

		if ($lenA <= 15 && $lenB <= 15) {
			return (float) ((int) $a - (int) $b);
		}

		$ah = $lenA > self::LOW ? (int) substr($a, 0, -self::LOW) : 0;
		$al = $lenA > self::LOW ? (int) substr($a, -self::LOW) : (int) $a;
		$bh = $lenB > self::LOW ? (int) substr($b, 0, -self::LOW) : 0;
		$bl = $lenB > self::LOW ? (int) substr($b, -self::LOW) : (int) $b;

		return (float) ($ah - $bh) * 1e9 + (float) ($al - $bl);
	}
}
