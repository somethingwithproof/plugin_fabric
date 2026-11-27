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
 * One bounded HTTP response from UFM.
 */
final readonly class UfmHttpResponse {
	/**
	 * @param array<string, string> $headers lower-case header name => value
	 */
	public function __construct(
		public int $status,
		public array $headers,
		public string $body,
	) {
	}

	/**
	 * Decode the response body as JSON with a bounded nesting depth.
	 *
	 * @return array<array-key, mixed>
	 *
	 * @throws UfmTransportError
	 */
	public function json(): array {
		try {
			$decoded = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new UfmTransportError('malformed', 'invalid JSON response');
		}

		if (!is_array($decoded)) {
			throw new UfmTransportError('malformed', 'expected JSON object or array');
		}

		return $decoded;
	}
}
