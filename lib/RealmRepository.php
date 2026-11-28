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
 * Resolves plugin realm IDs for permission checks invoked outside page auth,
 * such as MCP read-only ability authorization.
 */
final class RealmRepository {
	/**
	 * Construct a new RealmRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Return Cacti realm IDs (plugin_realms.id + 100) that grant at least View
	 * access to Fabric (View, Operator, or Admin realms).
	 *
	 * @return list<int>
	 */
	public function viewRealmIds(): array {
		$rows = Row::rows(db_fetch_assoc_prepared("SELECT id FROM plugin_realms WHERE plugin = 'fabric' ORDER BY id", [], true, $this->connection));

		if ($rows === null) {
			return [];
		}

		$ids = [];

		foreach ($rows as $row) {
			$id = Row::int($row, 'id', 0, 1);

			if ($id > 0) {
				$ids[] = $id + 100;
			}
		}

		return $ids;
	}
}
