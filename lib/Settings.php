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
 * Plugin settings, read from Cacti's settings table (REQ-UI-003). Paths name
 * files on the poller that sweeps a fabric. No setting holds a credential:
 * a fabric that needs an M_Key gets it from the tools' own configuration
 * file, never from the plugin or a command line (ADR 0009).
 */
final readonly class Settings {
	public const NAMES = [
		'fabric_tool_dir',
		'fabric_node_name_map',
		'fabric_mad_timeout',
		'fabric_command_timeout',
		'fabric_max_output',
		'fabric_node_budget',
		'fabric_failure_budget',
		'fabric_uninstall_keep_tables',
		'fabric_index_down_ports',
	];

	public const DEFAULT_TOOL_DIR = '/usr/sbin';
	public const DEFAULT_MAD_MS   = 1000;
	public const DEFAULT_TIMEOUT  = 60;
	public const DEFAULT_MAX_KIB  = 8192;
	public const DEFAULT_BUDGET   = 30;
	public const DEFAULT_FAILURES = 3;

	public function __construct(
		public string $toolDir,
		public string $nodeNameMap,
		public int $madTimeoutMs,
		public int $commandTimeout,
		public int $maxOutputKib,
		public int $nodeBudget,
		public int $failureBudget,
		public bool $keepTablesOnUninstall,
		public bool $indexDownPorts = false,
	) {
	}

	/**
	 * A setting that was never saved reads as its default, and an out of
	 * range number as its default or its bound. An unusable path reads as
	 * the default directory, or as no node name map. Keeping tables is the
	 * default, so an uninstall drops the inventory only after an
	 * administrator cleared the box (REQ-LIFE-003).
	 *
	 * @param array<string, mixed> $row setting name => stored value, absent when never saved
	 */
	public static function fromRow(array $row): self {
		return new self(
			toolDir: self::path($row, 'fabric_tool_dir') ?? self::DEFAULT_TOOL_DIR,
			nodeNameMap: self::path($row, 'fabric_node_name_map') ?? '',
			madTimeoutMs: min(Row::int($row, 'fabric_mad_timeout', self::DEFAULT_MAD_MS, 100), 10000),
			commandTimeout: min(Row::int($row, 'fabric_command_timeout', self::DEFAULT_TIMEOUT, 5), 600),
			maxOutputKib: min(Row::int($row, 'fabric_max_output', self::DEFAULT_MAX_KIB, 64), 65536),
			nodeBudget: min(Row::int($row, 'fabric_node_budget', self::DEFAULT_BUDGET, 5), 300),
			failureBudget: min(Row::int($row, 'fabric_failure_budget', self::DEFAULT_FAILURES, 1), 64),
			keepTablesOnUninstall: array_key_exists('fabric_uninstall_keep_tables', $row) ? Row::bool($row, 'fabric_uninstall_keep_tables') : true,
			indexDownPorts: Row::bool($row, 'fabric_index_down_ports'),
		);
	}

	public function maxOutputBytes(): int {
		return $this->maxOutputKib * 1024;
	}

	/**
	 * Whether a value is an absolute path of plain characters with no "."
	 * or ".." component. CommandBuilder applies the same rule again before
	 * a path reaches an argument vector.
	 */
	public static function validPath(string $value): bool {
		if (preg_match('#^/[A-Za-z0-9._/+-]{0,254}$#D', $value) !== 1) {
			return false;
		}

		foreach (explode('/', $value) as $part) {
			if ($part === '.' || $part === '..') {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function path(array $row, string $name): ?string {
		$value = rtrim(Row::string($row, $name), '/');

		return $value !== '' && self::validPath($value) ? $value : null;
	}

	/**
	 * Settings tab for the config_settings hook.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form(): array {
		$number = static fn (string $label, string $description, int $default, int $min, int $max): array => [
			'friendly_name' => $label,
			'description'   => $description,
			'method'        => 'textbox',
			'default'       => (string) $default,
			'filter'        => FILTER_VALIDATE_INT,
			'options'       => ['options' => ['min_range' => $min, 'max_range' => $max]],
			'max_length'    => '5',
			'size'          => '5',
		];

		$path = static fn (string $label, string $description, string $default): array => [
			'friendly_name' => $label,
			'description'   => $description,
			'method'        => 'textbox',
			'default'       => $default,
			'max_length'    => '255',
			'size'          => '60',
		];

		return [
			'fabric_header_tools' => [
				'friendly_name' => __('Diagnostics Tools', 'fabric'),
				'method'        => 'spacer',
			],
			'fabric_tool_dir'      => $path(__('Tool Directory', 'fabric'), __('Absolute directory, on each poller that sweeps a fabric, holding perfquery, ibnetdiscover, and iblinkinfo. The Debian and Ubuntu packages install them in /usr/sbin. No other program is ever run from it.', 'fabric'), self::DEFAULT_TOOL_DIR),
			'fabric_node_name_map' => $path(__('Node Name Map', 'fabric'), __('Optional absolute path to an infiniband-diags node name map file. Names it supplies are shown only; identity is always the node GUID.', 'fabric'), ''),
			'fabric_mad_timeout'   => $number(__('Datagram Timeout', 'fabric'), __('Milliseconds each management datagram may take before the tools retry it (their -t option).', 'fabric'), self::DEFAULT_MAD_MS, 100, 10000),
			'fabric_command_timeout' => $number(__('Command Timeout', 'fabric'), __('Seconds one tool run may take before it is stopped and recorded as failed.', 'fabric'), self::DEFAULT_TIMEOUT, 5, 600),
			'fabric_max_output'    => $number(__('Maximum Output', 'fabric'), __('KiB one tool run may print. Larger output is discarded and recorded as failed.', 'fabric'), self::DEFAULT_MAX_KIB, 64, 65536),
			'fabric_header_sweep' => [
				'friendly_name' => __('Sweep', 'fabric'),
				'method'        => 'spacer',
			],
			'fabric_node_budget'    => $number(__('Node Time Budget', 'fabric'), __('Seconds one node sweep may spend reading counters. Ports not reached are read in the next cycle.', 'fabric'), self::DEFAULT_BUDGET, 5, 300),
			'fabric_failure_budget' => $number(__('Node Failure Budget', 'fabric'), __('Failed ports after which a node sweep stops for this cycle. A port that does not answer costs several seconds.', 'fabric'), self::DEFAULT_FAILURES, 1, 64),
			'fabric_header_objects' => [
				'friendly_name' => __('Cacti Objects', 'fabric'),
				'method'        => 'spacer',
			],
			'fabric_index_down_ports' => [
				'friendly_name' => __('Index Down Ports', 'fabric'),
				'description'   => __('When checked, the Fabric Port data query also lists ports that are down and have no remote end, such as unused adapter ports. Cleared, they are left out until they come up.', 'fabric'),
				'method'        => 'checkbox',
				'default'       => '',
			],
			'fabric_header_uninstall' => [
				'friendly_name' => __('Uninstall', 'fabric'),
				'method'        => 'spacer',
			],
			'fabric_uninstall_keep_tables' => [
				'friendly_name' => __('Keep Plugin Tables on Uninstall', 'fabric'),
				'description'   => __('When checked, uninstalling the plugin keeps its fabrics, inventory, and samples. Clear it to have the uninstall drop them; export the inventory first from the Fabric Admin page. The fabric is never contacted either way.', 'fabric'),
				'method'        => 'checkbox',
				'default'       => 'on',
			],
		];
	}
}
