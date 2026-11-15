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
 * Plugin tables, their migrations, and the plugin's registration records.
 *
 * Tables are created through api_plugin_db_table_create() so Cacti tracks them
 * in plugin_db_changes and drops them on uninstall. The schema version is kept
 * in its own setting because api_plugin_install() writes plugin_config.version
 * before the plugin's install function runs, so that column cannot tell us
 * whether the tables exist.
 *
 * Migrations run once each, in order, and the stored schema version moves
 * past a migration only after it succeeds (REQ-LIFE-002). Every migration is
 * written to be safe to repeat, so one interrupted part way is completed by
 * the next run. No migration touches a Cacti device, data source, graph, or
 * RRD file, runs a fabric tool, or contacts UFM (REQ-LIFE-005).
 *
 * There is no poller_bottom hook: sweeps start lazily from the script
 * server (ADR 0008), so disabling the plugin, which stops core calling the
 * hooks, and the enabled check in fabric_sweep_allowed() stop them.
 */
final class Schema {
	public const VERSION = 1;

	public const HOOKS = [
		'config_arrays'        => 'fabric_config_arrays',
		'config_settings'      => 'fabric_config_settings',
		'draw_navigation_text' => 'fabric_draw_navigation_text',
	];

	private const PLUGIN  = 'fabric';
	private const SETTING = 'fabric_schema_version';

	public function __construct(private readonly \PDO|false $connection) {
	}

	public function storedVersion(): int {
		$value = db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', [self::SETTING], '', true, $this->connection);

		return is_numeric($value) ? (int) $value : 0;
	}

	public function isCurrent(): bool {
		return $this->storedVersion() === self::VERSION;
	}

	/**
	 * Apply every pending migration, or stop at the first that fails.
	 *
	 * @param int $through the last migration to run; tests pass a lower
	 *                     number to leave an upgrade interrupted
	 */
	public function migrate(int $through = self::VERSION): bool {
		$from = $this->storedVersion();

		if ($from > self::VERSION) {
			Log::error(sprintf('Schema version %d is newer than this code (%d); refusing to downgrade', $from, self::VERSION));

			return false;
		}

		foreach ($this->migrations() as $version => $migration) {
			if ($version <= $from || $version > $through) {
				continue;
			}

			if (!$migration()) {
				Log::error(sprintf('Schema migration %d failed; schema left at version %d', $version, $this->storedVersion()));

				return false;
			}

			db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', [self::SETTING, (string) $version], true, $this->connection);

			Log::info(sprintf('Schema migrated to version %d', $version));
		}

		return true;
	}

	public function recordedPluginVersion(): string {
		return (string) db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', [self::PLUGIN], '', true, $this->connection);
	}

	public function recordPluginVersion(string $version, string $author, string $homepage): void {
		db_execute_prepared('UPDATE plugin_config SET version = ?, author = ?, webpage = ? WHERE directory = ?', [$version, $author, $homepage, self::PLUGIN], true, $this->connection);
	}

	/**
	 * Whether core has the plugin enabled (plugin_config status 1).
	 */
	public function pluginEnabled(): bool {
		return (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory = ?', [self::PLUGIN], '', true, $this->connection) === 1;
	}

	public function hooksRegistered(): bool {
		$placeholders = implode(', ', array_fill(0, count(self::HOOKS), '?'));

		$hooks  = (int) db_fetch_cell_prepared("SELECT COUNT(DISTINCT hook) FROM plugin_hooks WHERE name = ? AND hook IN ($placeholders)", array_merge([self::PLUGIN], array_keys(self::HOOKS)), '', true, $this->connection);
		$realms = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?', [self::PLUGIN], '', true, $this->connection);

		return $hooks === count(self::HOOKS) && $realms === count(Pages::realms());
	}

	/**
	 * Stop Cacti from dropping the plugin tables on uninstall, and keep the
	 * schema version so a reinstall reuses them. True only when no table is
	 * still tracked afterwards, because core drops every tracked table.
	 */
	public function untrackTables(): bool {
		$ok = db_execute_prepared("DELETE FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", [self::PLUGIN], true, $this->connection);

		return $ok !== false && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", [self::PLUGIN], '', true, $this->connection) === 0;
	}

	public function forgetVersion(): void {
		db_execute_prepared('DELETE FROM settings WHERE name = ?', [self::SETTING], true, $this->connection);
	}

	/**
	 * @return array<int, \Closure(): bool> schema version => migration
	 */
	private function migrations(): array {
		return [
			1 => fn (): bool => $this->createTables(self::tables()),
		];
	}

	/**
	 * Track every existing plugin table for uninstall again.
	 *
	 * Core records a table only when it creates one, so tables kept through an
	 * uninstall would otherwise never be dropped by a later one. The row is
	 * the one api_plugin_db_table_create() writes; the table has no unique
	 * key, so an existing row is checked for rather than replaced.
	 */
	public function trackTables(): void {
		foreach (array_keys(self::tables()) as $table) {
			if ($this->tableExists($table)) {
				db_execute_prepared("INSERT INTO plugin_db_changes (plugin, `table`, `column`, `method`) SELECT ?, ?, '', 'create' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM plugin_db_changes WHERE plugin = ? AND `table` = ? AND `method` = 'create')", [self::PLUGIN, $table, self::PLUGIN, $table], true, $this->connection);
			}
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $tables
	 */
	private function createTables(array $tables): bool {
		foreach ($tables as $table => $definition) {
			api_plugin_db_table_create(self::PLUGIN, $table, $definition);

			if (!$this->tableExists($table)) {
				Log::error(sprintf('Unable to create table %s', $table));

				return false;
			}
		}

		return true;
	}

	private function tableExists(string $table): bool {
		return db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table], '', true, $this->connection) > 0;
	}

	/**
	 * Table definitions in api_plugin_db_table_create() format.
	 *
	 * State values are stored as varchar enum values, never list positions
	 * (REQ-CODE-012). Times are Unix seconds from the sweeping poller's
	 * clock. GUIDs are stored in Guid's canonical text. Node descriptions
	 * are fabric-supplied text, kept only after the parser cleaned and
	 * shortened them. No table holds a credential.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tables(): array {
		$innodb = ['type' => 'InnoDB', 'charset' => 'utf8mb4', 'collate' => 'utf8mb4_unicode_ci', 'row_format' => 'Dynamic'];
		$id     = static fn (string $name): array => ['name' => $name, 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
		$time   = static fn (string $name): array => ['name' => $name, 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'];
		$count  = static fn (string $name): array => ['name' => $name, 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'];
		$text   = static fn (string $name, int $length, string $default = ''): array => ['name' => $name, 'type' => "varchar($length)", 'NULL' => false, 'default' => $default];
		$guid   = static fn (string $name): array => ['name' => $name, 'type' => 'varchar(18)', 'NULL' => false, 'default' => ''];
		$small  = static fn (string $name): array => ['name' => $name, 'type' => 'smallint(5)', 'unsigned' => true, 'NULL' => false, 'default' => '0'];
		$float  = static fn (string $name): array => ['name' => $name, 'type' => 'double', 'NULL' => false, 'default' => '0'];

		return [
			'plugin_fabric_fabric' => $innodb + [
				'comment' => 'Fabrics the plugin watches',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$text('name', 64),
					$text('backend', 16, 'diags'),
					$id('poller_id') + ['default' => '1'],
					$id('sweep_interval') + ['default' => '300'],
					$text('adapter', 32),
					$small('adapter_port'),
					$text('admin_state', 16, 'enabled'),
					$time('created_at'),
					$time('updated_at'),
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'name', 'columns' => ['name']]],
			],
			// One row per node GUID and fabric (ADR 0003). Type, port count,
			// device, vendor, and system image GUID are the attributes whose
			// change marks a conflict; LID and description are attributes
			// only. revision guards every update against a concurrent one.
			'plugin_fabric_node' => $innodb + [
				'comment' => 'Fabric nodes by GUID',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$id('fabric_id'),
					$guid('guid'),
					$text('node_type', 16),
					$small('port_count'),
					$count('device_id'),
					$count('vendor_id'),
					$guid('sysimg_guid'),
					$guid('port0_guid'),
					$text('description', 64),
					$count('lid'),
					$id('host_id') + ['default' => '0'],
					$text('presence', 16, 'present'),
					$text('identity', 16, 'ok'),
					$text('conflict_seen', 255),
					$time('first_seen'),
					$time('last_seen'),
					$count('revision'),
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'fabric_guid', 'columns' => ['fabric_id', 'guid']]],
				'keys'        => [['name' => 'port0_guid', 'columns' => ['fabric_id', 'port0_guid']], ['name' => 'host_id', 'columns' => ['host_id']]],
			],
			// One row per node GUID and port number. The topology columns
			// come from discovery, the link columns from iblinkinfo.
			'plugin_fabric_port' => $innodb + [
				'comment' => 'Fabric ports by node GUID and port number',
				'columns' => [
					$id('node_id'),
					$small('port_number'),
					$guid('port_guid'),
					$count('lid'),
					$guid('remote_guid'),
					$small('remote_port'),
					$text('presence', 16, 'present'),
					$time('first_seen'),
					$time('last_seen'),
					$text('logical_state', 16),
					$text('phys_state', 16),
					$small('active_width'),
					$text('active_speed', 8),
					$small('best_width'),
					$text('best_speed', 8),
					$small('enabled_width'),
					$text('enabled_speed', 8),
					['name' => 'has_peer', 'type' => 'tinyint(1)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					$time('link_observed_at'),
				],
				'primary' => 'node_id`,`port_number',
			],
			// Latest counters per port and group, as received (REQ-SWEEP-007):
			// a JSON object of counter name to decimal string, or null where
			// the port does not support the counter. A failed read updates
			// attempted_at and error only, so old values keep their old
			// observed_at and age into stale; they are never replaced by zero.
			'plugin_fabric_sample' => $innodb + [
				'comment' => 'Latest port counter samples',
				'columns' => [
					$id('node_id'),
					$small('port_number'),
					$text('counter_group', 16),
					$time('observed_at'),
					$time('attempted_at'),
					$text('backend', 16, 'diags'),
					$count('cap_mask'),
					$count('cap_mask2'),
					$text('counters', 2048),
					$text('error', 16),
				],
				'primary' => 'node_id`,`port_number`,`counter_group',
			],
			'plugin_fabric_relink' => $innodb + [
				'comment' => 'Proposed re-links of replaced nodes',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$id('fabric_id'),
					$id('old_node_id'),
					$id('new_node_id'),
					$guid('neighbor_guid'),
					$small('neighbor_port'),
					$text('state', 16, 'proposed'),
					$time('proposed_at'),
					$time('decided_at'),
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'pair', 'columns' => ['old_node_id', 'new_node_id']]],
			],
			// Claims make a lazy sweep single: one topology sweep per fabric
			// and one counter sweep per node at a time (ADR 0008).
			'plugin_fabric_claim' => $innodb + [
				'comment' => 'Sweep claims',
				'columns' => [
					$text('scope', 16),
					$id('claim_key'),
					$text('holder', 64),
					$time('expires_at'),
				],
				'primary' => 'scope`,`claim_key',
			],
			'plugin_fabric_node_sweep' => $innodb + [
				'comment' => 'Last counter sweep per node',
				'columns' => [
					$id('node_id'),
					$time('last_attempt'),
					$time('last_success'),
					$float('duration'),
					$count('ports_total'),
					$count('ports_read'),
					$count('ports_failed'),
					$count('ports_skipped'),
					$text('outcome', 16, 'never'),
					$text('error', 16),
				],
				'primary' => 'node_id',
			],
			'plugin_fabric_health' => $innodb + [
				'comment' => 'Last topology sweep per fabric',
				'columns' => [
					$id('fabric_id'),
					$time('discovery_attempt'),
					$time('discovery_success'),
					$float('discovery_duration'),
					$text('discovery_error', 16),
					$count('nodes_seen'),
					$count('ports_seen'),
					$time('links_attempt'),
					$time('links_success'),
					$float('links_duration'),
					$text('links_error', 16),
					$count('links_unmatched'),
					$count('links_skipped'),
					$guid('local_guid'),
					$text('privilege', 128),
				],
				'primary' => 'fabric_id',
			],
		];
	}
}
