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
 * Plain-text formatting for page cells. Callers escape the result.
 */
final class Format {
	public static function age(int $time, int $now): string {
		if ($time <= 0) {
			return __('Never', 'fabric');
		}

		$seconds = max(0, $now - $time);

		return match (true) {
			$seconds < 120    => __('%d seconds ago', $seconds, 'fabric'),
			$seconds < 7200   => __('%d minutes ago', intdiv($seconds, 60), 'fabric'),
			$seconds < 172800 => __('%d hours ago', intdiv($seconds, 3600), 'fabric'),
			default           => __('%d days ago', intdiv($seconds, 86400), 'fabric'),
		};
	}

	/**
	 * A table header row from plain labels.
	 *
	 * @param list<string> $labels
	 */
	public static function header(array $labels): string {
		return '<tr class="tableHeader"><th>' . implode('</th><th>', array_map('html_escape', $labels)) . '</th></tr>';
	}

	/**
	 * A table row from plain cells, each escaped.
	 *
	 * @param list<string> $cells
	 */
	public static function row(array $cells): string {
		return '<tr class="tableRow"><td>' . implode('</td><td>', array_map('html_escape', $cells)) . '</td></tr>';
	}
}
