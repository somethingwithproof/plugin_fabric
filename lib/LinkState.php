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
 * What a source reported about one port's link (REQ-LINK-001). Every field
 * the source did not give is null, never a default (REQ-LINK-006).
 *
 * "Best" is the best width and speed in the intersection of what both ends
 * support, the value iblinkinfo prints as "Could be <x>" when the link runs
 * below it; when it prints no hint, the best is the active value. The
 * diagnostics backend has no per-end supported or enabled values: none of
 * the three allowlisted tools prints them (docs/research/link-downgrade.md),
 * so enabledWidth and enabledSpeed stay null until the UFM backend fills
 * them. They hold the best value enabled at both ends.
 */
final readonly class LinkState {
	public function __construct(
		public ?LogicalState $logical,
		public ?PhysState $physical,
		public ?LinkWidth $activeWidth,
		public ?LinkSpeed $activeSpeed,
		public ?LinkWidth $bestWidth,
		public ?LinkSpeed $bestSpeed,
		public ?LinkWidth $enabledWidth,
		public ?LinkSpeed $enabledSpeed,
		public bool $hasPeer,
	) {
	}

	public static function unknown(): self {
		return new self(null, null, null, null, null, null, null, null, false);
	}

	/**
	 * @param array<array-key, mixed> $row a plugin_fabric_port row
	 */
	public static function fromRow(array $row): self {
		$width = static fn (string $key): ?LinkWidth => LinkWidth::tryFrom(Row::int($row, $key));
		$speed = static fn (string $key): ?LinkSpeed => LinkSpeed::tryFrom(Row::string($row, $key));

		return new self(
			LogicalState::tryFrom(Row::string($row, 'logical_state')),
			PhysState::tryFrom(Row::string($row, 'phys_state')),
			$width('active_width'),
			$speed('active_speed'),
			$width('best_width'),
			$speed('best_speed'),
			$width('enabled_width'),
			$speed('enabled_speed'),
			Row::bool($row, 'has_peer'),
		);
	}

	/**
	 * @return array<string, int|string>
	 */
	public function toRow(): array {
		return [
			'logical_state' => $this->logical->value ?? '',
			'phys_state'    => $this->physical->value ?? '',
			'active_width'  => $this->activeWidth->value ?? 0,
			'active_speed'  => $this->activeSpeed->value ?? '',
			'best_width'    => $this->bestWidth->value ?? 0,
			'best_speed'    => $this->bestSpeed->value ?? '',
			'enabled_width' => $this->enabledWidth->value ?? 0,
			'enabled_speed' => $this->enabledSpeed->value ?? '',
			'has_peer'      => $this->hasPeer ? 1 : 0,
		];
	}
}
