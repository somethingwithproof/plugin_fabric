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
 * Replicates non-secret fabric definitions to remote data collectors via the
 * replicate_out hook. Vault secrets (plugin_fabric_secret) and external key
 * files are never replicated across pollers.
 */
final class ReplicatorRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Fetch fabric rows assigned to a remote poller for replication.
	 *
	 * @return list<array<array-key, mixed>>
	 */
	public function fabricsForPoller(int $remotePollerId): array {
		if ($remotePollerId < 2) {
			return [];
		}

		$rows = Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_fabric_fabric WHERE poller_id = ? ORDER BY id', [$remotePollerId], true, $this->connection));

		return $rows ?? [];
	}

	/**
	 * Fetch non-secret plugin settings for remote poller replication.
	 * The vault key path is excluded because each collector holds its own
	 * local key material if configured.
	 *
	 * @return list<array<array-key, mixed>>
	 */
	public function nonSecretSettings(): array {
		$names = array_values(array_filter(
			Settings::NAMES,
			static fn (string $name): bool => $name !== 'fabric_vault_key_file',
		));

		if ($names === []) {
			return [];
		}

		$placeholders = implode(', ', array_fill(0, count($names), '?'));
		$rows         = Row::rows(db_fetch_assoc_prepared("SELECT name, value FROM settings WHERE name IN ($placeholders)", $names, true, $this->connection));

		return $rows ?? [];
	}
}
