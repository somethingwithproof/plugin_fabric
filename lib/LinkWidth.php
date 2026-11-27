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
 * Link width, backed by its lane count (REQ-LINK-002).
 *
 * The tools encode widths as bits that are not lane counts: 1 is 1X, 2 is
 * 4X, 4 is 8X, 8 is 12X, and 16 is 2X (libibmad dump.c). Comparisons use
 * the lane count, so 2X sorts between 1X and 4X as iblinkinfo's own
 * get_max_width() orders them (docs/research/link-downgrade.md).
 */
enum LinkWidth: int {
	case X1  = 1;
	case X2  = 2;
	case X4  = 4;
	case X8  = 8;
	case X12 = 12;

	/**
	 * From encoded.
	 *
	 * @param int $value
	 * @return ?self
	 */
	public static function fromEncoded(int $value): ?self {
		return match ($value) {
			1       => self::X1,
			2       => self::X4,
			4       => self::X8,
			8       => self::X12,
			16      => self::X2,
			default => null,
		};
	}

	/**
	 * "4X" as iblinkinfo prints it, or "4x" as ibnetdiscover does. Anything
	 * else, including libibmad's "undefined (3)", is unknown.
	 */
	public static function fromText(string $text): ?self {
		return match (strtoupper(trim($text))) {
			'1X'    => self::X1,
			'2X'    => self::X2,
			'4X'    => self::X4,
			'8X'    => self::X8,
			'12X'   => self::X12,
			default => null,
		};
	}

	/**
	 * Lanes.
	 *
	 * @return int
	 */
	public function lanes(): int {
		return $this->value;
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->value . 'X';
	}
}
