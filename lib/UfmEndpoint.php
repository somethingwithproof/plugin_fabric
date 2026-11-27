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
 * Validated NVIDIA UFM REST base URL. Enforces HTTPS by default, rejects
 * embedded credentials, queries, fragments, and control characters.
 */
final readonly class UfmEndpoint {
	public function __construct(
		public string $scheme,
		public string $host,
		public int $port,
		public string $basePath,
	) {
	}

	public static function parse(string $url, bool $allowLoopbackHttp = false): ?self {
		$url = trim($url);

		if ($url === '' || strlen($url) > 255 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
			return null;
		}

		$parts = parse_url($url);

		if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
			return null;
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		$host   = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

		if ($host === '' || preg_match('/^([a-z0-9.-]{1,253}|[0-9a-f:]{2,45})$/', $host) !== 1) {
			return null;
		}

		if ($scheme !== 'https' && !($allowLoopbackHttp && $scheme === 'http' && self::isLoopback($host))) {
			return null;
		}

		$defaultPort = $scheme === 'https' ? 443 : 80;
		$port        = isset($parts['port']) ? (int) $parts['port'] : $defaultPort;

		if ($port < 1 || $port > 65535) {
			return null;
		}

		$path = rtrim((string) ($parts['path'] ?? ''), '/');

		if ($path !== '' && preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $path) !== 1) {
			return null;
		}

		return new self($scheme, $host, $port, $path);
	}

	public function socketAddress(): string {
		$transport = $this->scheme === 'https' ? 'tls' : 'tcp';
		$host      = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;

		return $transport . '://' . $host . ':' . $this->port;
	}

	public function hostHeader(): string {
		$host        = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
		$defaultPort = $this->scheme === 'https' ? 443 : 80;

		return $this->port === $defaultPort ? $host : $host . ':' . $this->port;
	}

	public function canonical(): string {
		return $this->scheme . '://' . $this->hostHeader() . $this->basePath;
	}

	public static function isLoopback(string $host): bool {
		return $host === '127.0.0.1' || $host === '::1' || $host === 'localhost';
	}
}
