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
 * Deterministic CasC plan with a SHA-256 confirmation hash bound to the exact
 * settings and fabric mutations proposed.
 */
final readonly class ConfigPlan {
	/**
	 * @param array<string, string>                                   $settingsChanges setting name => new DB string value
	 * @param list<array{action: string, fabric: Fabric}>             $fabricChanges   create|update
	 * @param list<string>                                            $errors
	 * @param list<string>                                            $notes
	 */
	public function __construct(
		public array $settingsChanges,
		public array $fabricChanges,
		public array $errors,
		public array $notes = [],
	) {
	}

	public function hasErrors(): bool {
		return $this->errors !== [];
	}

	public function isEmpty(): bool {
		return $this->settingsChanges === [] && $this->fabricChanges === [];
	}

	public function id(): string {
		$canonical = [
			'settings' => $this->settingsChanges,
			'fabrics'  => array_map(static fn (array $c): array => [
				'action'          => $c['action'],
				'name'            => $c['fabric']->name,
				'backend'         => $c['fabric']->backend->value,
				'poller_id'       => $c['fabric']->pollerId,
				'sweep_interval'  => $c['fabric']->interval,
				'adapter'         => $c['fabric']->adapter,
				'adapter_port'    => $c['fabric']->adapterPort,
				'admin_state'     => $c['fabric']->adminState->value,
				'ufm_url'         => $c['fabric']->ufmUrl,
				'ufm_ca_file'     => $c['fabric']->ufmCaFile,
				'ufm_spki_pin'    => $c['fabric']->ufmSpkiPin,
				'ufm_credential'  => $c['fabric']->ufmCredentialId,
			], $this->fabricChanges),
			'errors' => $this->errors,
		];

		return substr(hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)), 0, 16);
	}

	/**
	 * @return list<string>
	 */
	public function lines(): array {
		$lines = [];

		foreach ($this->errors as $error) {
			$lines[] = 'ERROR: ' . $error;
		}

		foreach ($this->notes as $note) {
			$lines[] = 'NOTE: ' . $note;
		}

		foreach ($this->settingsChanges as $name => $value) {
			$lines[] = sprintf('~ setting %s = %s', $name, $value);
		}

		foreach ($this->fabricChanges as $change) {
			$prefix  = $change['action'] === 'create' ? '+' : '~';
			$fabric  = $change['fabric'];
			$lines[] = sprintf('%s fabric %s (backend=%s poller=%d interval=%d state=%s)', $prefix, $fabric->name, $fabric->backend->value, $fabric->pollerId, $fabric->interval, $fabric->adminState->value);
		}

		return $lines;
	}
}
