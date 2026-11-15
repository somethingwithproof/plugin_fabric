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
 * What IblinkinfoParser read: the port records, and the port GUID of each
 * line it skipped because the line could be read in more than one way or
 * in none (a node description that mimics the tool's own fields). A
 * skipped line leaves that node's stored link states as they were.
 */
final readonly class LinkReport {
	/**
	 * @param list<LinkRecord> $records
	 * @param list<Guid>       $skipped port GUID of each skipped line
	 */
	public function __construct(public array $records, public array $skipped) {
	}
}
