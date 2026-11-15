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
 * Why a sweep step produced no usable data. Recorded as the varchar value;
 * the health page shows message(), never the tool's own text.
 */
enum CommandError: string {
	case Config      = 'config';
	case NotRunnable = 'not_runnable';
	case Timeout     = 'timeout';
	case TooLarge    = 'too_large';
	case ExitStatus  = 'exit_status';
	case Parse       = 'parse';
	case Budget      = 'budget';
	case Storage     = 'storage';

	public function message(): string {
		return match ($this) {
			self::Config      => __('The fabric or plugin settings are incomplete or invalid', 'fabric'),
			self::NotRunnable => __('The tool is missing or not executable in the configured directory', 'fabric'),
			self::Timeout     => __('The tool exceeded its time limit and was stopped', 'fabric'),
			self::TooLarge    => __('The tool output exceeded the size limit and was discarded', 'fabric'),
			self::ExitStatus  => __('The tool failed', 'fabric'),
			self::Parse       => __('The tool output did not have the expected form', 'fabric'),
			self::Budget      => __('Not attempted: the time or failure budget was spent', 'fabric'),
			self::Storage     => __('The plugin tables could not be read or written', 'fabric'),
		};
	}
}
