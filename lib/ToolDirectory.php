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
 * The checks a tool directory must pass before it is saved and before each
 * sweep runs anything from it (ADR 0009). Whoever can write the directory
 * chooses what the poller runs, so it must be an absolute, canonical path
 * to a real directory, with no symbolic link in it, owned by root, and
 * writable by neither its group nor others.
 */
final class ToolDirectory {
	public const CODES = ['path', 'missing', 'symlink', 'owner', 'writable'];

	/**
	 * @param int $owner the required owner; root except in tests, which
	 *                   cannot create a root-owned directory
	 *
	 * @return string|null the first check the directory fails, or null
	 */
	public static function problem(string $dir, int $owner = 0): ?string {
		if (!Settings::validPath($dir) || ($dir !== '/' && str_ends_with($dir, '/'))) {
			return 'path';
		}

		clearstatcache(true, $dir);

		if (!is_dir($dir)) {
			return 'missing';
		}

		if (realpath($dir) !== $dir) {
			return 'symlink';
		}

		$stat = @lstat($dir);

		if ($stat === false) {
			return 'missing';
		}

		if ($stat['uid'] !== $owner) {
			return 'owner';
		}

		return ($stat['mode'] & 0o022) !== 0 ? 'writable' : null;
	}

	public static function message(string $code): string {
		return match ($code) {
			'path'     => __('The tool directory must be an absolute path of letters, digits, dots, dashes, underscores, and slashes, with no trailing slash.', 'fabric'),
			'missing'  => __('The tool directory does not exist or is not a directory.', 'fabric'),
			'symlink'  => __('The tool directory path contains a symbolic link. Enter the real path.', 'fabric'),
			'owner'    => __('The tool directory is not owned by root.', 'fabric'),
			'writable' => __('The tool directory can be written by its group or by others.', 'fabric'),
			default    => __('Unknown warning', 'fabric'),
		};
	}
}
