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
 * Cacti devices, read for the node mapping form. Read only: the plugin
 * never creates, changes, or deletes a device in M2.
 */
final class HostRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * @return array<int, string>|null device id => description
	 */
	public function names(): ?array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT id, description FROM host ORDER BY description, id', [], true, $this->connection));

		if ($rows === null) {
			return null;
		}

		$out = [];

		foreach ($rows as $row) {
			$id = Row::int($row, 'id', 0, 1);

			if ($id > 0) {
				$out[$id] = Row::string($row, 'description');
			}
		}

		return $out;
	}

	/**
	 * True only when the lookup succeeded and found no such device; a
	 * failed lookup is not taken as proof the device is gone.
	 */
	public function gone(int $id): bool {
		$count = db_fetch_cell_prepared('SELECT COUNT(*) FROM host WHERE id = ?', [$id], '', true, $this->connection);

		return $id > 0 && $count !== false && (int) $count === 0;
	}

	public function exists(int $id): bool {
		return $id > 0 && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host WHERE id = ?', [$id], '', true, $this->connection) === 1;
	}
}
