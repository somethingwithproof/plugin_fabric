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
 * Holds a sensitive value (a UFM token, password, or vault key) so it does
 * not leak into stack traces, var_dump(), or serialization.
 */
final class Secret {
	private string $value;

	public function __construct(#[\SensitiveParameter] string $value) {
		$this->value = $value;
	}

	public function reveal(): string {
		return $this->value;
	}

	public function isEmpty(): bool {
		return $this->value === '';
	}

	/**
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return ['value' => '[redacted]'];
	}

	public function __toString(): string {
		return '[redacted]';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function __serialize(): array {
		throw new \LogicException('A secret cannot be serialized');
	}
}
