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
 * A complete argument vector for one allowlisted tool. Only CommandBuilder
 * makes one, after checking every value that goes into it; there is no way
 * to build a Command from a string or to append to one (REQ-EXEC-001).
 */
final readonly class Command {
	/**
	 * @param list<string> $argv
	 */
	private function __construct(public Tool $tool, public array $argv) {
	}

	/**
	 * @param list<string> $argv
	 *
	 * @internal for CommandBuilder
	 */
	public static function fromBuilder(CommandBuilder $builder, Tool $tool, array $argv): self {
		return new self($tool, $argv);
	}
}
