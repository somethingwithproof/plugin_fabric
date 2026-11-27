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
 * Node types as InfiniBand defines them (REQ-INV-003). Stored as the backing
 * value (REQ-CODE-012).
 */
enum NodeType: string {
	case Switch  = 'switch';
	case Adapter = 'ca';
	case Router  = 'router';

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ($this) {
			self::Switch  => __('Switch', 'fabric'),
			self::Adapter => __('Channel adapter', 'fabric'),
			self::Router  => __('Router', 'fabric'),
		};
	}
}
