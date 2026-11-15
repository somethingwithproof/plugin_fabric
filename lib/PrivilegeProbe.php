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
 * Checks, on the sweeping poller, the privilege conditions ADR 0009 warns
 * about. It only reads file modes; it changes nothing and runs nothing.
 *
 * - root: the sweep runs as root. The tools need only read access to the
 *   umad devices, granted to a group.
 * - setid: an allowlisted tool has the setuid or setgid bit.
 * - tool_writable: an allowlisted tool, or its directory, can be written by
 *   its group, by others, or by the account the sweep runs as, so whoever
 *   can write it chooses what the poller runs.
 * - umad_world: a umad device is readable or writable by every account,
 *   which lets any local user send management datagrams.
 */
final class PrivilegeProbe {
	public const CODES = ['root', 'setid', 'tool_writable', 'umad_world'];

	public function __construct(private readonly string $toolDir, private readonly string $deviceDir = '/dev/infiniband', private readonly int $toolOwner = 0) {
	}

	/**
	 * Why no tool may run from the directory, checked again before every
	 * sweep because the directory can change after the setting was saved.
	 * Null when it passes ToolDirectory's checks.
	 */
	public function refusal(): ?string {
		return ToolDirectory::problem($this->toolDir, $this->toolOwner);
	}

	/**
	 * @return list<string> warning codes, in CODES order
	 */
	public function warnings(): array {
		$found = [];

		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$found['root'] = true;
		}

		$paths = [$this->toolDir];

		foreach (Tool::cases() as $tool) {
			$paths[] = $this->toolDir . '/' . $tool->value;
		}

		foreach ($paths as $index => $path) {
			$mode = @fileperms($path);

			if ($mode === false) {
				continue;
			}

			if ($index > 0 && ($mode & 0o6000) !== 0) {
				$found['setid'] = true;
			}

			if (($mode & 0o022) !== 0 || is_writable($path)) {
				$found['tool_writable'] = true;
			}
		}

		foreach (glob($this->deviceDir . '/umad*') ?: [] as $device) {
			$mode = @fileperms($device);

			if ($mode !== false && ($mode & 0o006) !== 0) {
				$found['umad_world'] = true;
			}
		}

		return array_values(array_filter(self::CODES, static fn (string $code): bool => isset($found[$code])));
	}

	public static function message(string $code): string {
		return match ($code) {
			'root'          => __('The sweep runs as root. Run the poller as an unprivileged account with read access to the umad devices through a group.', 'fabric'),
			'setid'         => __('A diagnostics tool has the setuid or setgid bit. The plugin never needs it.', 'fabric'),
			'tool_writable' => __('A diagnostics tool or its directory can be written by an account other than its owner, or by the poller account. Whoever can write it chooses what the poller runs.', 'fabric'),
			'umad_world'    => __('A umad device is readable or writable by every account, so any local user can query the fabric. Restrict it to a group.', 'fabric'),
			default         => __('Unknown warning', 'fabric'),
		};
	}
}
