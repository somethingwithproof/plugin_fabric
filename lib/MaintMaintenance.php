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
 * Queries Cacti Maint via plugin_maint_check_cacti_host() (the contract used
 * by Cacti core and Thold) with fallback to the is_device_in_maintenance
 * hook when Maint is loaded without its helper function.
 */
final class MaintMaintenance implements DeviceMaintenance {
	/** @var array<int, bool> */
	private array $known = [];

	/**
	 * In maintenance.
	 *
	 * @param int $hostId
	 * @return bool
	 */
	public function inMaintenance(int $hostId): bool {
		if ($hostId < 1) {
			return false;
		}

		if (array_key_exists($hostId, $this->known)) {
			return $this->known[$hostId];
		}

		if (function_exists('api_plugin_is_enabled') && !api_plugin_is_enabled('maint')) {
			$this->known[$hostId] = false;

			return false;
		}

		if (function_exists('plugin_maint_check_cacti_host')) {
			$this->known[$hostId] = (bool) plugin_maint_check_cacti_host($hostId);

			return $this->known[$hostId];
		}

		if (function_exists('api_plugin_hook_function')) {
			$this->known[$hostId] = api_plugin_hook_function('is_device_in_maintenance', $hostId) === true;

			return $this->known[$hostId];
		}

		$this->known[$hostId] = false;

		return false;
	}
}
