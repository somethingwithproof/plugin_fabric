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
 * Read-only NVIDIA UFM REST API client.
 *
 * Strictly enforces a GET-only path allowlist so no monitoring session
 * mutation, reboot, port disable, or token creation can ever be issued.
 */
final class UfmClient {
	/** Path ports constant for UfmClient. */
	public const PATH_PORTS   = '/ufmRest/resources/ports';
	/** Path systems constant for UfmClient. */
	public const PATH_SYSTEMS = '/ufmRest/resources/systems';
	/** Path session constant for UfmClient. */
	public const PATH_SESSION = '/ufmRest/monitoring/session/0/data';

	/** @var list<string> */
	public const ALLOWED_PATHS = [
		self::PATH_PORTS,
		self::PATH_SYSTEMS,
		self::PATH_SESSION,
		'/ufmRestV3/resources/ports',
		'/ufmRestV3/resources/systems',
		'/ufmRestV3/monitoring/session/0/data',
	];

	/**
	 * Construct a new UfmClient instance.
	 *
	 * @param UfmHttpTransport $transport
	 * @param UfmEndpoint $endpoint
	 * @param string $authType
	 * @param string $username
	 * @param Secret $secret
	 * @param ?string $caFile
	 * @param ?string $spkiPin
	 */
	public function __construct(
		private readonly UfmHttpTransport $transport,
		private readonly UfmEndpoint $endpoint,
		private readonly string $authType,
		private readonly string $username,
		private readonly Secret $secret,
		private readonly ?string $caFile = null,
		private readonly ?string $spkiPin = null,
	) {
	}

	/**
	 * Fetch all ports from the read-only UFM ports resource.
	 *
	 * @return array<array-key, mixed>
	 *
	 * @throws UfmTransportError
	 */
	public function fetchPorts(float $deadline): array {
		$path = $this->authType === CredentialRepository::AUTH_TOKEN ? '/ufmRestV3/resources/ports' : self::PATH_PORTS;

		return $this->getJson($path, [], $deadline);
	}

	/**
	 * Fetch all port counters from UFM default monitoring session 0 (no server-side session created).
	 *
	 * @return array<array-key, mixed>
	 *
	 * @throws UfmTransportError
	 */
	public function fetchSessionCounters(float $deadline): array {
		$path = $this->authType === CredentialRepository::AUTH_TOKEN ? '/ufmRestV3/monitoring/session/0/data' : self::PATH_SESSION;

		return $this->getJson($path, [], $deadline);
	}

	/**
	 * Perform an allowlisted GET request and decode bounded JSON.
	 *
	 * @param array<string, string|int> $query
	 *
	 * @return array<array-key, mixed>
	 *
	 * @throws UfmTransportError
	 */
	public function getJson(string $path, array $query, float $deadline): array {
		if (!in_array($path, self::ALLOWED_PATHS, true)) {
			throw new UfmTransportError('refused', 'UFM path is not on the read-only allowlist');
		}

		$response = $this->transport->get(
			$this->endpoint,
			$path,
			$query,
			['Authorization' => $this->authHeader()],
			$deadline,
			$this->caFile,
			$this->spkiPin,
		);

		if ($response->status === 401 || $response->status === 403) {
			throw new UfmTransportError('refused', 'UFM rejected authentication');
		}

		if ($response->status < 200 || $response->status >= 300) {
			throw new UfmTransportError('http', sprintf('UFM returned HTTP %d', $response->status));
		}

		return $response->json();
	}

	/**
	 * Auth header.
	 *
	 * @return string
	 */
	private function authHeader(): string {
		if ($this->authType === CredentialRepository::AUTH_TOKEN) {
			return 'Basic ' . $this->secret->reveal();
		}

		return 'Basic ' . base64_encode($this->username . ':' . $this->secret->reveal());
	}
}
