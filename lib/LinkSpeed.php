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
 * Per-lane link speed, ordered by rate. rank() is the planned speed_rank
 * value of the state data source: a larger rank is a faster lane, and the
 * numbers never change once released.
 *
 * iblinkinfo prints the per-lane rate ("25.78125 Gbps", "10.0 Gbps (FDR10)");
 * ibnetdiscover prints the name ("EDR", "FDR10"). Both forms map here, and
 * any other text (libibmad's "undefined (n)", "Extended speed", "???") is
 * unknown (REQ-LINK-006).
 */
enum LinkSpeed: string {
	case Sdr   = 'sdr';
	case Ddr   = 'ddr';
	case Qdr   = 'qdr';
	case Fdr10 = 'fdr10';
	case Fdr   = 'fdr';
	case Edr   = 'edr';
	case Hdr   = 'hdr';
	case Ndr   = 'ndr';
	case Xdr   = 'xdr';

	/**
	 * From rate.
	 *
	 * @param string $text
	 * @return ?self
	 */
	public static function fromRate(string $text): ?self {
		return match (trim($text)) {
			'2.5 Gbps'          => self::Sdr,
			'5.0 Gbps'          => self::Ddr,
			'10.0 Gbps'         => self::Qdr,
			'10.0 Gbps (FDR10)' => self::Fdr10,
			'14.0625 Gbps'      => self::Fdr,
			'25.78125 Gbps'     => self::Edr,
			'53.125 Gbps'       => self::Hdr,
			'106.25 Gbps'       => self::Ndr,
			'212.5 Gbps'        => self::Xdr,
			default             => null,
		};
	}

	/**
	 * From name.
	 *
	 * @param string $text
	 * @return ?self
	 */
	public static function fromName(string $text): ?self {
		return self::tryFrom(strtolower(trim($text)));
	}

	/**
	 * Rank.
	 *
	 * @return int
	 */
	public function rank(): int {
		return match ($this) {
			self::Sdr   => 1,
			self::Ddr   => 2,
			self::Qdr   => 3,
			self::Fdr10 => 4,
			self::Fdr   => 5,
			self::Edr   => 6,
			self::Hdr   => 7,
			self::Ndr   => 8,
			self::Xdr   => 9,
		};
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return strtoupper($this->value);
	}
}
