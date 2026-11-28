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
 * The attributes that tell two devices with one GUID apart
 * (docs/research/identity.md, rule 4). A change in any of them between
 * discoveries is a conflict. Neighbors are deliberately absent: a moved or
 * disconnected cable changes them on a healthy node.
 */
final readonly class NodeIdentity {
	/**
	 * Construct a new NodeIdentity instance.
	 *
	 * @param NodeType $type
	 * @param int $portCount
	 * @param int $deviceId
	 * @param int $vendorId
	 * @param ?Guid $sysimgGuid
	 */
	public function __construct(
		public NodeType $type,
		public int $portCount,
		public int $deviceId,
		public int $vendorId,
		public ?Guid $sysimgGuid,
	) {
	}

	/**
	 * @return list<string> names of the attributes that differ
	 */
	public function differences(self $other): array {
		$fields = [];

		if ($this->type !== $other->type) {
			$fields[] = 'node_type';
		}

		if ($this->portCount !== $other->portCount) {
			$fields[] = 'port_count';
		}

		if ($this->deviceId !== $other->deviceId) {
			$fields[] = 'device_id';
		}

		if ($this->vendorId !== $other->vendorId) {
			$fields[] = 'vendor_id';
		}

		if ($this->sysimgGuid?->text !== $other->sysimgGuid?->text) {
			$fields[] = 'sysimg_guid';
		}

		return $fields;
	}

	/**
	 * To json.
	 *
	 * @return string
	 */
	public function toJson(): string {
		return json_encode([
			'node_type'   => $this->type->value,
			'port_count'  => $this->portCount,
			'device_id'   => $this->deviceId,
			'vendor_id'   => $this->vendorId,
			'sysimg_guid' => $this->sysimgGuid->text ?? '',
		], JSON_THROW_ON_ERROR);
	}

	/**
	 * The conflicting sighting stored with a node, or null for an empty or
	 * damaged column.
	 */
	public static function fromJson(string $json): ?self {
		if ($json === '') {
			return null;
		}

		$data = json_decode($json, true);

		if (!is_array($data)) {
			return null;
		}

		$type = NodeType::tryFrom(Row::string($data, 'node_type'));

		return $type === null ? null : new self($type, Row::int($data, 'port_count', 0, 0), Row::int($data, 'device_id', 0, 0), Row::int($data, 'vendor_id', 0, 0), Guid::tryFromString(Row::string($data, 'sysimg_guid')));
	}
}
