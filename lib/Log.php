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
 * Plugin log lines go through cacti_log() under the FABRIC tag, so they share
 * Cacti's log file, rotation, and verbosity filter (REQ-CODE-005). Fabric
 * text (node descriptions, tool diagnostics) reaches a message only through
 * excerpt(), never as received.
 */
final class Log {
	public static function debug(string $message): void {
		cacti_log('DEBUG: ' . $message, false, 'FABRIC', POLLER_VERBOSITY_DEBUG);
	}

	public static function info(string $message): void {
		cacti_log('NOTE: ' . $message, false, 'FABRIC', POLLER_VERBOSITY_MEDIUM);
	}

	public static function warning(string $message): void {
		cacti_log('WARNING: ' . $message, false, 'FABRIC');
	}

	public static function error(string $message): void {
		cacti_log('ERROR: ' . $message, false, 'FABRIC');
	}

	/**
	 * A bounded, single-line, printable ASCII excerpt of untrusted text,
	 * such as a tool's standard error (REQ-EXEC-006).
	 */
	public static function excerpt(string $text, int $limit = 200): string {
		$line = trim((string) preg_replace('/ {2,}/', ' ', (string) preg_replace('/[^\x20-\x7e]+/', ' ', $text)));

		return strlen($line) > $limit ? substr($line, 0, $limit) . '...' : $line;
	}
}
