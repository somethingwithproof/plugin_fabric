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
 * Encrypted UFM credential storage in plugin_fabric_secret. Metadata listings
 * never expose ciphertext, nonces, tags, or plaintext secrets.
 */
final class CredentialRepository {
	/** Auth token constant for CredentialRepository. */
	public const AUTH_TOKEN = 'token';
	/** Auth basic constant for CredentialRepository. */
	public const AUTH_BASIC = 'basic';
	/** Purpose constant for CredentialRepository. */
	public const PURPOSE    = 'ufm_secret';

	/**
	 * Construct a new CredentialRepository instance.
	 *
	 * @param \PDO|false $connection
	 * @param Clock $clock
	 */
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	/**
	 * @return list<array{id: int, name: string, auth_type: string, username: string, key_id: string, updated_at: int}>|null
	 */
	public function listMetadata(): ?array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT id, name, auth_type, username, key_id, updated_at FROM plugin_fabric_secret ORDER BY name, id', [], true, $this->connection));

		if ($rows === null) {
			return null;
		}

		$out = [];

		foreach ($rows as $row) {
			$id = Row::int($row, 'id', 0, 1);

			if ($id > 0) {
				$out[] = [
					'id'         => $id,
					'name'       => Row::string($row, 'name'),
					'auth_type'  => Row::string($row, 'auth_type'),
					'username'   => Row::string($row, 'username'),
					'key_id'     => Row::string($row, 'key_id'),
					'updated_at' => Row::int($row, 'updated_at'),
				];
			}
		}

		return $out;
	}

	/**
	 * @return array{id: int, name: string, auth_type: string, username: string, entry: VaultEntry}|null
	 */
	public function find(int $id): ?array {
		if ($id < 1) {
			return null;
		}

		$row = db_fetch_row_prepared('SELECT id, name, auth_type, username, nonce, ciphertext, tag, key_id FROM plugin_fabric_secret WHERE id = ?', [$id], true, $this->connection);

		if (!is_array($row)) {
			return null;
		}

		$entry = VaultEntry::fromRow($row);

		if ($entry === null) {
			return null;
		}

		return [
			'id'        => Row::int($row, 'id', 0, 1),
			'name'      => Row::string($row, 'name'),
			'auth_type' => Row::string($row, 'auth_type'),
			'username'  => Row::string($row, 'username'),
			'entry'     => $entry,
		];
	}

	/**
	 * Find id by name.
	 *
	 * @param string $name
	 * @return int
	 */
	public function findIdByName(string $name): int {
		if ($name === '') {
			return 0;
		}

		$raw = db_fetch_cell_prepared('SELECT id FROM plugin_fabric_secret WHERE name = ? LIMIT 1', [$name], '', true, $this->connection);

		return is_numeric($raw) ? max(0, (int) $raw) : 0;
	}

	/**
	 * Save.
	 *
	 * @param int $id
	 * @param string $name
	 * @param string $authType
	 * @param string $username
	 * @param Secret $secret
	 * @param Vault $vault
	 * @return int
	 */
	public function save(int $id, string $name, string $authType, string $username, Secret $secret, Vault $vault): int {
		if ($authType !== self::AUTH_TOKEN && $authType !== self::AUTH_BASIC) {
			throw CredentialError::because('unsupported UFM auth type');
		}

		if ($name === '' || preg_match(Fabric::NAME_PATTERN, $name) !== 1) {
			throw CredentialError::because('invalid credential name');
		}

		$now = $this->clock->now();

		if ($id < 1) {
			// Insert placeholder to allocate the row ID so the AAD binds to the real credential ID.
			$ok = db_execute_prepared(
				"INSERT INTO plugin_fabric_secret (name, auth_type, username, nonce, ciphertext, tag, key_id, created_at, updated_at) VALUES (?, ?, ?, '', '', '', '', ?, ?)",
				[$name, $authType, $username, $now, $now],
				true,
				$this->connection,
			);

			if ($ok === false) {
				return 0;
			}

			$id = (int) db_fetch_insert_id($this->connection);

			if ($id < 1) {
				return 0;
			}
		}

		$entry = $vault->encrypt($secret, $id, self::PURPOSE);

		$ok = db_execute_prepared(
			'UPDATE plugin_fabric_secret SET name = ?, auth_type = ?, username = ?, nonce = ?, ciphertext = ?, tag = ?, key_id = ?, updated_at = ? WHERE id = ?',
			[$name, $authType, $username, $entry->nonce, $entry->ciphertext, $entry->tag, $entry->keyId, $now, $id],
			true,
			$this->connection,
		);

		return $ok !== false ? $id : 0;
	}

	/**
	 * @return array{auth_type: string, username: string, secret: Secret}
	 *
	 * @throws CredentialError
	 */
	public function decrypt(int $id, Vault $vault): array {
		$record = $this->find($id);

		if ($record === null) {
			throw CredentialError::because('credential not found');
		}

		return [
			'auth_type' => $record['auth_type'],
			'username'  => $record['username'],
			'secret'    => $vault->decrypt($record['entry'], $record['id'], self::PURPOSE),
		];
	}

	/**
	 * Delete.
	 *
	 * @param int $id
	 * @return bool
	 */
	public function delete(int $id): bool {
		if ($id < 1) {
			return false;
		}

		return db_execute_prepared('DELETE FROM plugin_fabric_secret WHERE id = ?', [$id], true, $this->connection) !== false;
	}
}
