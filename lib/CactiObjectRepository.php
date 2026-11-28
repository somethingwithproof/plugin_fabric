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
 * Read-only lookups in Cacti's own tables: the shipped templates by hash,
 * devices, and graphs. Every change to a Cacti object goes through Cacti's
 * API functions in DeviceProvisioner; nothing here writes.
 */
final class CactiObjectRepository {
	/** Tables whose rows the plugin looks up by hash. */
	private const HASHED = ['host_template', 'data_template', 'graph_templates', 'snmp_query'];

	/**
	 * Construct a new CactiObjectRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Id by hash.
	 *
	 * @param string $table
	 * @param string $hash
	 * @return int
	 */
	public function idByHash(string $table, string $hash): int {
		if (!in_array($table, self::HASHED, true) || preg_match('/^[0-9a-f]{32}$/D', $hash) !== 1) {
			return 0;
		}

		return (int) db_fetch_cell_prepared("SELECT id FROM `$table` WHERE hash = ? ORDER BY id LIMIT 1", [$hash], '', true, $this->connection);
	}

	/**
	 * Device template id.
	 *
	 * @return int
	 */
	public function deviceTemplateId(): int {
		return $this->idByHash('host_template', TemplateInstaller::hash('host_template', TemplateInstaller::DEVICE_TEMPLATE));
	}

	/**
	 * Query id.
	 *
	 * @return int
	 */
	public function queryId(): int {
		return $this->idByHash('snmp_query', TemplateInstaller::hash('data_query', TemplateInstaller::QUERY));
	}

	/**
	 * Graph template id.
	 *
	 * @param string $name
	 * @return int
	 */
	public function graphTemplateId(string $name): int {
		return $this->idByHash('graph_templates', TemplateInstaller::hash('graph_template', $name));
	}

	/**
	 * Default profile id.
	 *
	 * @return int
	 */
	public function defaultProfileId(): int {
		$id = (int) db_fetch_cell_prepared("SELECT id FROM data_source_profiles WHERE `default` = 'on' ORDER BY id LIMIT 1", [], '', true, $this->connection);

		return $id > 0 ? $id : 1;
	}

	/**
	 * @return array{id: int, description: string, hostname: string, poller_id: int, host_template_id: int}|null
	 */
	public function device(int $hostId): ?array {
		$row = $hostId > 0 ? db_fetch_row_prepared("SELECT id, description, hostname, poller_id, host_template_id FROM host WHERE id = ? AND deleted = ''", [$hostId], true, $this->connection) : false;

		if (!is_array($row) || Row::int($row, 'id', 0, 1) === 0) {
			return null;
		}

		return [
			'id'               => Row::int($row, 'id', 0, 1),
			'description'      => Row::string($row, 'description'),
			'hostname'         => Row::string($row, 'hostname'),
			'poller_id'        => Row::int($row, 'poller_id', 1, 1),
			'host_template_id' => Row::int($row, 'host_template_id', 0, 0),
		];
	}

	/**
	 * Check whether query is present.
	 *
	 * @param int $hostId
	 * @return bool
	 */
	public function hasQuery(int $hostId): bool {
		$queryId = $this->queryId();

		return $queryId > 0 && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_snmp_query WHERE host_id = ? AND snmp_query_id = ?', [$hostId, $queryId], '', true, $this->connection) > 0;
	}

	/**
	 * Devices whose host name or description begins with the first word of
	 * a node description, the evidence offered when mapping a node
	 * (REQ-MAP-003). A word shorter than three characters suggests nothing.
	 *
	 * @return array<int, string> host id => description
	 */
	public function suggestions(string $nodeDescription): array {
		$word = strtolower((string) preg_replace('/[^A-Za-z0-9._-].*$/s', '', trim($nodeDescription)));

		if (strlen($word) < 3) {
			return [];
		}

		$like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word);
		$rows = Row::rows(db_fetch_assoc_prepared(
			"SELECT id, description FROM host WHERE deleted = '' AND (LOWER(hostname) = ? OR LOWER(hostname) LIKE ? OR LOWER(description) = ? OR LOWER(description) LIKE ?) ORDER BY description, id LIMIT 10",
			[$word, $like . '.%', $word, $like . ' %'],
			true,
			$this->connection,
		)) ?? [];

		$out = [];

		foreach ($rows as $row) {
			$out[Row::int($row, 'id', 0, 1)] = Row::string($row, 'description');
		}

		unset($out[0]);

		return $out;
	}

	/**
	 * Graphs of a device made from one of the plugin's graph templates,
	 * by data query index.
	 *
	 * @return array<string, list<array{id: int, template: string}>> index => graphs
	 */
	public function portGraphs(int $hostId): array {
		$ids = [];

		foreach (array_keys(PortValues::templates()) as $name) {
			$id = $this->graphTemplateId($name);

			if ($id > 0) {
				$ids[$id] = $name;
			}
		}

		if ($ids === []) {
			return [];
		}

		$placeholders = implode(', ', array_fill(0, count($ids), '?'));
		$rows         = Row::rows(db_fetch_assoc_prepared("SELECT id, graph_template_id, snmp_index FROM graph_local WHERE host_id = ? AND graph_template_id IN ($placeholders) ORDER BY id", [$hostId, ...array_keys($ids)], true, $this->connection)) ?? [];
		$out          = [];

		foreach ($rows as $row) {
			$out[Row::string($row, 'snmp_index')][] = ['id' => Row::int($row, 'id', 0, 1), 'template' => $ids[Row::int($row, 'graph_template_id')] ?? ''];
		}

		return $out;
	}
}
