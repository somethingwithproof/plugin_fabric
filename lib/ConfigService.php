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
 * Configuration-as-Code export, validation, dry-run diff planning, and
 * confirmation-gated apply for plugin_fabric.
 *
 * UFM vault secrets and key file material are always redacted on export and
 * never accepted inline in CasC documents (only named credential references
 * are exported and resolved).
 */
final class ConfigService {
	public const REDACTED = '[REDACTED]';

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly FabricRepository $fabrics,
		private readonly CredentialRepository $credentials,
		private readonly ConfigRepository $configRepo,
	) {
	}

	/**
	 * Build the canonical export array with all secret fields redacted.
	 *
	 * @return array<string, mixed>
	 */
	public function exportData(): array {
		$current = $this->settings->load();
		$data    = [
			'schema_version' => ConfigCodec::VERSION,
			'settings'       => [
				'fabric_tool_dir'              => $current->toolDir,
				'fabric_node_name_map'         => $current->nodeNameMap,
				'fabric_mad_timeout'           => $current->madTimeoutMs,
				'fabric_command_timeout'       => $current->commandTimeout,
				'fabric_max_output'            => $current->maxOutputKib,
				'fabric_node_budget'           => $current->nodeBudget,
				'fabric_failure_budget'        => $current->failureBudget,
				'fabric_index_down_ports'      => $current->indexDownPorts,
				'fabric_uninstall_keep_tables' => $current->keepTablesOnUninstall,
				'fabric_vault_key_file'        => $current->vaultKeyFile !== '' ? self::REDACTED : '',
			],
		];

		$credNames = [];

		foreach ($this->credentials->listMetadata() ?? [] as $meta) {
			$credNames[$meta['id']] = $meta['name'];
		}

		$exportedFabrics = [];

		foreach ($this->fabrics->all() ?? [] as $fabric) {
			$entry = [
				'name'           => $fabric->name,
				'backend'        => $fabric->backend->value,
				'poller_id'      => $fabric->pollerId,
				'sweep_interval' => $fabric->interval,
				'admin_state'    => $fabric->adminState->value,
			];

			if ($fabric->backend === Backend::Diags) {
				$entry['adapter']      = $fabric->adapter;
				$entry['adapter_port'] = $fabric->adapterPort;
			} else {
				$entry['ufm_url'] = $fabric->ufmUrl;

				if ($fabric->ufmCaFile !== '') {
					$entry['ufm_ca_file'] = $fabric->ufmCaFile;
				}

				if ($fabric->ufmSpkiPin !== '') {
					$entry['ufm_spki_pin'] = $fabric->ufmSpkiPin;
				}

				if ($fabric->ufmCredentialId > 0) {
					$entry['ufm_credential_ref'] = $credNames[$fabric->ufmCredentialId] ?? self::REDACTED;
				}
			}

			$exportedFabrics[] = $entry;
		}

		if ($exportedFabrics !== []) {
			$data['fabrics'] = $exportedFabrics;
		}

		return $data;
	}

	public function export(string $format): string {
		return ConfigCodec::write($format, $this->exportData());
	}

	/**
	 * Validate a parsed configuration tree and return a list of errors.
	 *
	 * @return list<string>
	 */
	public function validate(ConfigTree $tree): array {
		return $this->plan($tree)->errors;
	}

	public function plan(ConfigTree $tree): ConfigPlan {
		$errors          = [];
		$notes           = [];
		$settingsChanges = [];
		$fabricChanges   = [];
		$data            = $tree->data;

		$version = $data['schema_version'] ?? null;

		if ($version !== ConfigCodec::VERSION) {
			$errors[] = sprintf('Unsupported schema_version; expected %d', ConfigCodec::VERSION);
		}

		foreach (array_keys($data) as $topKey) {
			if (!in_array((string) $topKey, ['schema_version', 'settings', 'fabrics'], true)) {
				$errors[] = sprintf('Unknown top-level key "%s"', (string) $topKey);
			}
		}

		$current = $this->settings->load();
		$curMap  = [
			'fabric_tool_dir'              => $current->toolDir,
			'fabric_node_name_map'         => $current->nodeNameMap,
			'fabric_mad_timeout'           => (string) $current->madTimeoutMs,
			'fabric_command_timeout'       => (string) $current->commandTimeout,
			'fabric_max_output'            => (string) $current->maxOutputKib,
			'fabric_node_budget'           => (string) $current->nodeBudget,
			'fabric_failure_budget'        => (string) $current->failureBudget,
			'fabric_index_down_ports'      => $current->indexDownPorts ? 'on' : '',
			'fabric_uninstall_keep_tables' => $current->keepTablesOnUninstall ? 'on' : '',
		];

		if (isset($data['settings'])) {
			if (!is_array($data['settings']) || array_is_list($data['settings'])) {
				$errors[] = 'settings must be a map';
			} else {
				foreach ($data['settings'] as $name => $rawVal) {
					$key = (string) $name;

					if (!in_array($key, Settings::NAMES, true)) {
						$errors[] = sprintf('Unknown setting "%s"', $key);

						continue;
					}

					if ($key === 'fabric_vault_key_file') {
						if ($rawVal === self::REDACTED || $rawVal === '') {
							$notes[] = 'Skipped redacted setting fabric_vault_key_file (configure locally on each poller)';
						} else {
							$errors[] = 'fabric_vault_key_file cannot be set inline via CasC; configure it locally on each poller';
						}

						continue;
					}

					$encoded = $this->validateSetting($key, $rawVal, $errors);

					if ($encoded !== null && $curMap[$key] !== $encoded) {
						$settingsChanges[$key] = $encoded;
					}
				}
			}
		}

		$existingByName = [];

		foreach ($this->fabrics->all() ?? [] as $existing) {
			$existingByName[$existing->name] = $existing;
		}

		if (isset($data['fabrics'])) {
			if (!is_array($data['fabrics']) || !array_is_list($data['fabrics'])) {
				$errors[] = 'fabrics must be a list';
			} else {
				$seenNames = [];

				foreach ($data['fabrics'] as $i => $item) {
					if (!is_array($item)) {
						$errors[] = sprintf('fabrics[%d] must be a map', $i);

						continue;
					}

					if (isset($item['ufm_token']) || isset($item['ufm_password']) || isset($item['secret'])) {
						$errors[] = sprintf('fabrics[%d]: inline secrets are forbidden; use ufm_credential_ref', $i);

						continue;
					}

					$name = is_string($item['name'] ?? null) ? trim($item['name']) : '';

					if ($name === '' || preg_match(Fabric::NAME_PATTERN, $name) !== 1) {
						$errors[] = sprintf('fabrics[%d]: invalid fabric name', $i);

						continue;
					}

					if (isset($seenNames[$name])) {
						$errors[] = sprintf('Duplicate fabric name "%s"', $name);

						continue;
					}

					$seenNames[$name] = true;
					$backend          = is_string($item['backend'] ?? null) ? Backend::tryFrom($item['backend']) : null;

					if ($backend === null) {
						$errors[] = sprintf('fabrics[%d] (%s): invalid backend', $i, $name);

						continue;
					}

					$pollerId = is_int($item['poller_id'] ?? null) ? $item['poller_id'] : 1;
					$interval = is_int($item['sweep_interval'] ?? null) ? $item['sweep_interval'] : Fabric::DEFAULT_INTERVAL;
					$state    = is_string($item['admin_state'] ?? null) ? AdminState::tryFrom($item['admin_state']) : AdminState::Enabled;

					if ($pollerId < 1) {
						$errors[] = sprintf('fabrics[%d] (%s): poller_id must be >= 1', $i, $name);

						continue;
					}

					if ($interval < Fabric::MIN_INTERVAL || $interval > Fabric::MAX_INTERVAL) {
						$errors[] = sprintf('fabrics[%d] (%s): sweep_interval out of range (%d..%d)', $i, $name, Fabric::MIN_INTERVAL, Fabric::MAX_INTERVAL);

						continue;
					}

					if ($state === null) {
						$errors[] = sprintf('fabrics[%d] (%s): invalid admin_state', $i, $name);

						continue;
					}

					$adapter     = is_string($item['adapter'] ?? null) ? trim($item['adapter']) : '';
					$adapterPort = is_int($item['adapter_port'] ?? null) ? $item['adapter_port'] : 0;
					$ufmUrl      = is_string($item['ufm_url'] ?? null) ? trim($item['ufm_url']) : '';
					$ufmCaFile   = is_string($item['ufm_ca_file'] ?? null) ? trim($item['ufm_ca_file']) : '';
					$ufmSpkiPin  = is_string($item['ufm_spki_pin'] ?? null) ? trim($item['ufm_spki_pin']) : '';
					$credRef     = is_string($item['ufm_credential_ref'] ?? null) ? trim($item['ufm_credential_ref']) : '';
					$credId      = 0;

					if ($backend === Backend::Diags) {
						if (preg_match(Fabric::ADAPTER_PATTERN, $adapter) !== 1 || $adapterPort < 1 || $adapterPort > PortRef::MAX_PORT) {
							$errors[] = sprintf('fabrics[%d] (%s): diags backend requires valid adapter and adapter_port', $i, $name);

							continue;
						}
					} else {
						if (UfmEndpoint::parse($ufmUrl) === null) {
							$errors[] = sprintf('fabrics[%d] (%s): ufm backend requires a valid https:// ufm_url', $i, $name);

							continue;
						}

						if ($ufmCaFile !== '' && !SecureFile::pathAcceptable($ufmCaFile)) {
							$errors[] = sprintf('fabrics[%d] (%s): invalid ufm_ca_file path', $i, $name);

							continue;
						}

						if ($ufmSpkiPin !== '' && UfmSocketTransport::normalizeSpkiPin($ufmSpkiPin) === null) {
							$errors[] = sprintf('fabrics[%d] (%s): invalid ufm_spki_pin', $i, $name);

							continue;
						}

						if ($credRef !== '' && $credRef !== self::REDACTED) {
							$credId = $this->credentials->findIdByName($credRef);

							if ($credId < 1) {
								$errors[] = sprintf('fabrics[%d] (%s): ufm_credential_ref "%s" does not exist in vault', $i, $name, $credRef);

								continue;
							}
						} elseif (isset($existingByName[$name])) {
							$credId = $existingByName[$name]->ufmCredentialId;
						}
					}

					$existing = $existingByName[$name] ?? null;
					$desired  = new Fabric(
						id: $existing !== null ? $existing->id : 0,
						name: $name,
						backend: $backend,
						pollerId: $pollerId,
						interval: $interval,
						adapter: $adapter,
						adapterPort: $adapterPort,
						adminState: $state,
						ufmUrl: $ufmUrl,
						ufmCaFile: $ufmCaFile,
						ufmSpkiPin: $ufmSpkiPin,
						ufmCredentialId: $credId,
					);

					if ($existing === null) {
						$fabricChanges[] = ['action' => 'create', 'fabric' => $desired];
					} elseif ($this->fabricDiffers($existing, $desired)) {
						$fabricChanges[] = ['action' => 'update', 'fabric' => $desired];
					}
				}
			}
		}

		ksort($settingsChanges);

		return new ConfigPlan($settingsChanges, $fabricChanges, $errors, $notes);
	}

	/**
	 * Apply a validated CasC document only when $confirmHash matches the fresh
	 * plan hash.
	 *
	 * @return list<string> refusals (empty on success)
	 */
	public function apply(ConfigTree $tree, string $confirmHash): array {
		$plan = $this->plan($tree);

		if ($plan->hasErrors()) {
			return $plan->errors;
		}

		if ($confirmHash === '' || !hash_equals($plan->id(), $confirmHash)) {
			return [sprintf('Confirmation hash mismatch (expected %s)', $plan->id())];
		}

		$refusals = [];

		foreach ($plan->settingsChanges as $name => $value) {
			if (!$this->configRepo->saveSetting($name, $value)) {
				$refusals[] = sprintf('Failed to save setting %s', $name);
			}
		}

		foreach ($plan->fabricChanges as $change) {
			if ($this->fabrics->save($change['fabric']) < 1) {
				$refusals[] = sprintf('Failed to %s fabric %s', $change['action'], $change['fabric']->name);
			}
		}

		return $refusals;
	}

	/**
	 * @param list<string> $errors
	 */
	private function validateSetting(string $key, mixed $value, array &$errors): ?string {
		$ranges = [
			'fabric_mad_timeout'     => [100, 10000],
			'fabric_command_timeout' => [5, 600],
			'fabric_max_output'      => [64, 65536],
			'fabric_node_budget'     => [5, 300],
			'fabric_failure_budget'  => [1, 64],
		];

		if (isset($ranges[$key])) {
			if (!is_int($value) || $value < $ranges[$key][0] || $value > $ranges[$key][1]) {
				$errors[] = sprintf('Setting %s must be an integer in %d..%d', $key, $ranges[$key][0], $ranges[$key][1]);

				return null;
			}

			return (string) $value;
		}

		if ($key === 'fabric_index_down_ports' || $key === 'fabric_uninstall_keep_tables') {
			if (!is_bool($value)) {
				$errors[] = sprintf('Setting %s must be a boolean', $key);

				return null;
			}

			return $value ? 'on' : '';
		}

		if (!is_string($value)) {
			$errors[] = sprintf('Setting %s must be a string', $key);

			return null;
		}

		if ($key === 'fabric_tool_dir' && !Settings::validPath($value)) {
			$errors[] = 'Setting fabric_tool_dir must be a valid absolute path';

			return null;
		}

		if ($key === 'fabric_node_name_map' && $value !== '' && !Settings::validPath($value)) {
			$errors[] = 'Setting fabric_node_name_map must be empty or a valid absolute path';

			return null;
		}

		return $value;
	}

	private function fabricDiffers(Fabric $a, Fabric $b): bool {
		return $a->backend !== $b->backend
			|| $a->pollerId !== $b->pollerId
			|| $a->interval !== $b->interval
			|| $a->adapter !== $b->adapter
			|| $a->adapterPort !== $b->adapterPort
			|| $a->adminState !== $b->adminState
			|| $a->ufmUrl !== $b->ufmUrl
			|| $a->ufmCaFile !== $b->ufmCaFile
			|| $a->ufmSpkiPin !== $b->ufmSpkiPin
			|| $a->ufmCredentialId !== $b->ufmCredentialId;
	}
}
