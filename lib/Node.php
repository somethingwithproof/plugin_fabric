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
 * A stored fabric node. Identity is the GUID within its fabric; everything
 * else is an attribute.
 */
final readonly class Node {
	public function __construct(
		public int $id,
		public int $fabricId,
		public Guid $guid,
		public NodeType $type,
		public int $portCount,
		public int $deviceId,
		public int $vendorId,
		public ?Guid $sysimgGuid,
		public ?Guid $port0Guid,
		public string $description,
		public int $lid,
		public int $hostId,
		public Presence $presence,
		public IdentityState $identity,
		public ?NodeIdentity $conflictSeen,
		public int $firstSeen,
		public int $lastSeen,
		public int $revision,
		public string $conflictSignature = '',
		public string $acceptedSignature = '',
	) {
	}

	/**
	 * A row without a valid id, fabric, GUID, or node type is not a node.
	 * Unknown presence reads as missing and unknown identity as a conflict,
	 * so a damaged row is never swept.
	 *
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$id     = Row::int($row, 'id', 0, 1);
		$fabric = Row::int($row, 'fabric_id', 0, 1);
		$guid   = Guid::tryFromString(Row::string($row, 'guid'));
		$type   = NodeType::tryFrom(Row::string($row, 'node_type'));

		if ($id === 0 || $fabric === 0 || $guid === null || $type === null) {
			return null;
		}

		return new self(
			id: $id,
			fabricId: $fabric,
			guid: $guid,
			type: $type,
			portCount: Row::int($row, 'port_count', 0, 0),
			deviceId: Row::int($row, 'device_id', 0, 0),
			vendorId: Row::int($row, 'vendor_id', 0, 0),
			sysimgGuid: Guid::tryFromString(Row::string($row, 'sysimg_guid')),
			port0Guid: Guid::tryFromString(Row::string($row, 'port0_guid')),
			description: Row::string($row, 'description'),
			lid: Row::int($row, 'lid', 0, 0),
			hostId: Row::int($row, 'host_id', 0, 0),
			presence: Presence::tryFrom(Row::string($row, 'presence')) ?? Presence::Missing,
			identity: IdentityState::tryFrom(Row::string($row, 'identity')) ?? IdentityState::Conflict,
			conflictSeen: NodeIdentity::fromJson(Row::string($row, 'conflict_seen')),
			firstSeen: Row::int($row, 'first_seen', 0, 0),
			lastSeen: Row::int($row, 'last_seen', 0, 0),
			revision: Row::int($row, 'revision', 0, 0),
			conflictSignature: Row::string($row, 'conflict_signature'),
			acceptedSignature: Row::string($row, 'accepted_signature'),
		);
	}

	public function identity(): NodeIdentity {
		return new NodeIdentity($this->type, $this->portCount, $this->deviceId, $this->vendorId, $this->sysimgGuid);
	}

	/**
	 * Swept only while present, unconflicted, and of a fabric that is
	 * enabled; the caller checks the fabric.
	 */
	public function sweepable(): bool {
		return $this->presence === Presence::Present && $this->identity === IdentityState::Ok;
	}

	public function label(): string {
		return $this->description !== '' ? $this->description : $this->guid->text;
	}
}
