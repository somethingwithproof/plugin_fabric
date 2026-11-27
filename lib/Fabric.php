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
 * One fabric the plugin watches: what one query source can see, named by
 * an operator and never inferred from the network (REQ-FAB-001,
 * REQ-FAB-002). The diagnostics backend sweeps from the local adapter and
 * port recorded here (REQ-FAB-003), on the one poller assigned to it.
 */
final readonly class Fabric {
	public const DEFAULT_INTERVAL = 300;
	public const MIN_INTERVAL     = 60;
	public const MAX_INTERVAL     = 3600;

	/**
	 * The only poller a fabric may be assigned to until remote poller
	 * support lands (docs/PLAN.md, phase 3). A remote poller reads its own
	 * database copy, which holds no fabric inventory or claims.
	 */
	public const MAIN_POLLER = 1;

	/** An adapter name as the kernel names it (mlx5_0, hfi1_0); never starts with "-". */
	public const ADAPTER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.]{0,31}$/D';
	public const NAME_PATTERN    = '/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$/D';

	public function __construct(
		public int $id,
		public string $name,
		public Backend $backend,
		public int $pollerId,
		public int $interval,
		public string $adapter,
		public int $adapterPort,
		public AdminState $adminState,
		public string $ufmUrl = '',
		public string $ufmCaFile = '',
		public string $ufmSpkiPin = '',
		public int $ufmCredentialId = 0,
	) {
	}

	public function onMainPoller(): bool {
		return $this->pollerId === self::MAIN_POLLER;
	}

	/**
	 * A row whose name, backend, or backend-specific parameters are not valid
	 * is no fabric at all: it is never swept with values it was not saved with.
	 *
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$id      = Row::int($row, 'id', 0, 1);
		$name    = Row::string($row, 'name');
		$backend = Backend::tryFrom(Row::string($row, 'backend'));
		$adapter = Row::string($row, 'adapter');
		$port    = Row::int($row, 'adapter_port', 0, 0);
		$ufmUrl  = Row::string($row, 'ufm_url');

		if ($id === 0 || preg_match(self::NAME_PATTERN, $name) !== 1 || $backend === null) {
			return null;
		}

		if ($backend === Backend::Diags) {
			if (preg_match(self::ADAPTER_PATTERN, $adapter) !== 1 || $port < 1 || $port > PortRef::MAX_PORT) {
				return null;
			}
		} elseif (UfmEndpoint::parse($ufmUrl) === null) {
			return null;
		}

		return new self(
			id: $id,
			name: $name,
			backend: $backend,
			pollerId: Row::int($row, 'poller_id', 1, 1),
			interval: min(Row::int($row, 'sweep_interval', self::DEFAULT_INTERVAL, self::MIN_INTERVAL), self::MAX_INTERVAL),
			adapter: $adapter,
			adapterPort: $port,
			adminState: AdminState::tryFrom(Row::string($row, 'admin_state')) ?? AdminState::Disabled,
			ufmUrl: $ufmUrl,
			ufmCaFile: Row::string($row, 'ufm_ca_file'),
			ufmSpkiPin: Row::string($row, 'ufm_spki_pin'),
			ufmCredentialId: Row::int($row, 'ufm_credential_id', 0, 0),
		);
	}

	public function enabled(): bool {
		return $this->adminState === AdminState::Enabled;
	}
}
