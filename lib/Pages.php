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
 * Page files, realms, menu entries, and breadcrumbs.
 *
 * Cacti's auth.php denies any page that is not listed in a realm, so every
 * page script the plugin ships appears in exactly one realm below
 * (REQ-UI-001). The admin page changes fabrics, identities, and device
 * mappings, and the health page reveals poller privilege and tool
 * diagnostics, so both are Fabric Admin pages (REQ-UI-002, REQ-HEALTH-004).
 * The view realm sees the inventory of devices it may see.
 */
final class Pages {
	/** Operator constant for Pages. */
	public const OPERATOR = ['fabric_operator.php'];
	/** View constant for Pages. */
	public const VIEW  = ['fabric.php'];
	/** Admin constant for Pages. */
	public const ADMIN = ['fabric_admin.php', 'fabric_health.php'];

	/**
	 * @return list<array{name: string, files: list<string>}>
	 */
	public static function realms(): array {
		return [
			['name' => __('Fabric Operator', 'fabric'), 'files' => self::OPERATOR],
			['name' => __('Fabric View', 'fabric'), 'files' => self::VIEW],
			['name' => __('Fabric Admin', 'fabric'), 'files' => self::ADMIN],
		];
	}

	/**
	 * @return list<string>
	 */
	public static function files(): array {
		return array_merge(self::VIEW, self::ADMIN, self::OPERATOR);
	}

	/**
	 * Console > Management entries. Cacti shows a menu entry only to users
	 * whose realms allow its file.
	 *
	 * @return array<string, string>
	 */
	public static function menu(): array {
		return [
			'fabric_operator.php' => __('Fabric Operations', 'fabric'),
			'fabric.php'        => __('Fabrics', 'fabric'),
			'fabric_admin.php'  => __('Fabric Admin', 'fabric'),
			'fabric_health.php' => __('Fabric Health', 'fabric'),
		];
	}

	/**
	 * Pages whose load re-checks the schema, matching how maintained plugins
	 * apply upgrades in a core that never calls plugin_*_upgrade() itself.
	 *
	 * @return list<string>
	 */
	public static function upgradeTriggers(): array {
		return array_merge(['plugins.php'], self::files());
	}

	/**
	 * @return array<string, array{title: string, mapping: string, url: string, level: string}>
	 */
	public static function navigation(): array {
		$nav = [];

		foreach (self::menu() as $file => $title) {
			$nav[$file . ':'] = [
				'title'   => $title,
				'mapping' => 'index.php:',
				'url'     => $file,
				'level'   => '1',
			];
		}

		return $nav;
	}
}
