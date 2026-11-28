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
	/**
	 * Construct a new Guid instance.
	 *
	 * @param string $text
	 */
	private function __construct(public string $text) {
	}

	/**
	 * From string.
	 *
	 * @param string $value
	 * @return self
	 * @throws \InvalidArgumentException
	 */
	public static function fromString(string $value): self {
		$guid = self::tryFromString($value);

		if ($guid === null) {
			throw new \InvalidArgumentException('not a GUID');
		}

		return $guid;
	}

	/**
	 * Try from string.
	 *
	 * @param string $value
	 * @return ?self
	 */
	public static function tryFromString(string $value): ?self {
		$hex = $value;

		if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
			$hex = substr($hex, 2);
		}

		$len = strlen($hex);

		if ($len < 1 || $len > 16 || !ctype_xdigit($hex)) {
			return null;
		}

		$trimmed = ltrim($hex, '0');

		if ($trimmed === '') {
			return null;
		}

		$digits = $len === 16 ? strtolower($hex) : str_pad(strtolower($trimmed), 16, '0', STR_PAD_LEFT);

		return new self('0x' . $digits);
	}

	/**
	 * Equals.
	 *
	 * @param self $other
	 * @return bool
	 */
	public function equals(self $other): bool {
		return $this->text === $other->text;
	}
}
