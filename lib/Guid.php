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
 * A node or port GUID in its one canonical text form: "0x" and 16 lowercase
 * hexadecimal digits (REQ-CODE-010, ADR 0003).
 *
 * The tools print GUIDs three ways, all accepted here: zero-padded with
 * "0x" (iblinkinfo, the switchguid= and caguid= lines print "0x" without
 * padding), without "0x" and unpadded (the port GUID in parentheses in
 * ibnetdiscover), and zero-padded without "0x" (the "S-" and "H-" node
 * names and the "Initiated from" line). Zero is not a GUID: libibmad uses it
 * for "none", and a node or port with GUID 0 cannot be told apart.
 */
final readonly class Guid {
	private function __construct(public string $text) {
	}

	public static function fromString(string $value): self {
		$guid = self::tryFromString($value);

		if ($guid === null) {
			throw new \InvalidArgumentException('not a GUID');
		}

		return $guid;
	}

	public static function tryFromString(string $value): ?self {
		if (preg_match('/^(?:0[xX])?([0-9a-fA-F]{1,16})$/D', $value, $match) !== 1) {
			return null;
		}

		$digits = str_pad(strtolower(ltrim($match[1], '0')), 16, '0', STR_PAD_LEFT);

		return $digits === '0000000000000000' ? null : new self('0x' . $digits);
	}

	public function equals(self $other): bool {
		return $this->text === $other->text;
	}
}
