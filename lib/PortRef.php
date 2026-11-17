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
 * A port's identity: its node's GUID and its port number (REQ-CODE-011,
 * ADR 0003). Parsers work with tool text; everything after them uses this.
 *
 * The key form "<GUID>:<port>" is the Fabric Port data query index. It
 * contains a colon, so the query XML uses "!" as its output delimiter
 * (docs/research/cacti-core.md); nothing here or in Cacti's RRD file names
 * depends on the colon.
 */
final readonly class PortRef {
	/** InfiniBand port numbers are one octet; 255 means "all ports" in PerfMgt. */
	public const MAX_PORT = 254;

	public function __construct(public Guid $node, public int $port) {
		if ($port < 0 || $port > self::MAX_PORT) {
			throw new \InvalidArgumentException('port number out of range');
		}
	}

	public static function fromKey(string $key): ?self {
		if (preg_match('/^(0x[0-9a-f]{16}):([0-9]{1,3})$/D', $key, $match) !== 1 || (int) $match[2] > self::MAX_PORT) {
			return null;
		}

		$guid = Guid::tryFromString($match[1]);

		return $guid === null ? null : new self($guid, (int) $match[2]);
	}

	public function key(): string {
		return $this->node->text . ':' . $this->port;
	}

	public function equals(self $other): bool {
		return $this->port === $other->port && $this->node->equals($other->node);
	}
}
