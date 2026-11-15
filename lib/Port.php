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
 * A stored port of a node: its topology from discovery and its link from
 * iblinkinfo.
 */
final readonly class Port {
	public function __construct(
		public int $nodeId,
		public int $number,
		public ?Guid $portGuid,
		public int $lid,
		public ?PortRef $remote,
		public Presence $presence,
		public int $firstSeen,
		public int $lastSeen,
		public LinkState $link,
		public int $linkObservedAt,
	) {
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$node   = Row::int($row, 'node_id', 0, 1);
		$number = Row::int($row, 'port_number', -1, 0);

		if ($node === 0 || $number < 0 || $number > PortRef::MAX_PORT) {
			return null;
		}

		$remoteGuid = Guid::tryFromString(Row::string($row, 'remote_guid'));
		$remotePort = Row::int($row, 'remote_port', -1, 0);

		return new self(
			nodeId: $node,
			number: $number,
			portGuid: Guid::tryFromString(Row::string($row, 'port_guid')),
			lid: Row::int($row, 'lid', 0, 0),
			remote: $remoteGuid !== null && $remotePort >= 0 && $remotePort <= PortRef::MAX_PORT ? new PortRef($remoteGuid, $remotePort) : null,
			presence: Presence::tryFrom(Row::string($row, 'presence')) ?? Presence::Missing,
			firstSeen: Row::int($row, 'first_seen', 0, 0),
			lastSeen: Row::int($row, 'last_seen', 0, 0),
			link: LinkState::fromRow($row),
			linkObservedAt: Row::int($row, 'link_observed_at', 0, 0),
		);
	}
}
