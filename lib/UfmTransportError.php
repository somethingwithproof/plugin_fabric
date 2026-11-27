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
 * Classified UFM transport error. Messages carry fixed reasons, never peer
 * response text or credentials.
 */
final class UfmTransportError extends \RuntimeException {
	public function __construct(public readonly string $kind, string $reason) {
		parent::__construct($reason);
	}

	public function toCommandError(): CommandError {
		return match ($this->kind) {
			'timeout'   => CommandError::Timeout,
			'too_large' => CommandError::TooLarge,
			'malformed' => CommandError::Parse,
			'refused', 'tls_pin', 'config' => CommandError::Config,
			default     => CommandError::ExitStatus,
		};
	}
}
