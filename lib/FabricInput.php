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
 * Validates the fabric form (REQ-FAB-001, REQ-FAB-003). Values arrive as
 * the raw request strings the page script read through Cacti's request
 * helpers. Each backend has its own parameters; the diagnostics backend's
 * adapter name and port reach the tools' -C and -P options, so they are
 * held to the patterns CommandBuilder checks again.
 */
final readonly class FabricInput {
	/**
	 * @param array<string, string> $errors field => message
	 */
	private function __construct(public ?Fabric $fabric, public array $errors) {
	}

	/**
	 * @param array<string, string> $input
	 * @param array<int, string>    $pollers poller id => name
	 */
	public static function parse(array $input, int $id, array $pollers, bool $allowRemote = true): self {
		$errors   = [];
		$name     = trim($input['name'] ?? '');
		$backend  = Backend::tryFrom(trim($input['backend'] ?? ''));
		$poller   = Row::int($input, 'poller_id', 0, 1);
		$every    = Row::int($input, 'sweep_interval', 0, 1);
		$adapter  = trim($input['adapter'] ?? '');
		$port     = Row::int($input, 'adapter_port', 0, 0);
		$ufmUrl   = trim($input['ufm_url'] ?? '');
		$ufmCa    = trim($input['ufm_ca_file'] ?? '');
		$ufmPin   = trim($input['ufm_spki_pin'] ?? '');
		$ufmCred  = Row::int($input, 'ufm_credential_id', 0, 0);

		if (preg_match(Fabric::NAME_PATTERN, $name) !== 1) {
			$errors['name'] = __('Name: use 1 to 64 letters, digits, spaces, dots, dashes, or underscores, starting with a letter or digit.', 'fabric');
		}

		if ($backend === null) {
			$errors['backend'] = __('Backend: choose one of the listed backends.', 'fabric');
		}

		if (!isset($pollers[$poller])) {
			$errors['poller_id'] = __('Poller: choose one of the listed pollers.', 'fabric');
		} elseif (!$allowRemote && $poller !== Fabric::MAIN_POLLER) {
			$errors['poller_id'] = __('Poller: only the main poller can sweep a fabric until remote poller support is added.', 'fabric');
		}

		if ($every < Fabric::MIN_INTERVAL || $every > Fabric::MAX_INTERVAL) {
			$errors['sweep_interval'] = __('Sweep Interval: enter whole seconds from %d to %d.', Fabric::MIN_INTERVAL, Fabric::MAX_INTERVAL, 'fabric');
		}

		if ($backend === Backend::Diags && preg_match(Fabric::ADAPTER_PATTERN, $adapter) !== 1) {
			$errors['adapter'] = __('Adapter: enter the local adapter name as the kernel names it, such as mlx5_0.', 'fabric');
		}

		if ($backend === Backend::Diags && ($port < 1 || $port > PortRef::MAX_PORT)) {
			$errors['adapter_port'] = __('Adapter Port: enter the port number of the local adapter, from 1 to %d.', PortRef::MAX_PORT, 'fabric');
		}

		if ($backend === Backend::Ufm) {
			if (UfmEndpoint::parse($ufmUrl) === null) {
				$errors['ufm_url'] = 'UFM URL: enter an https:// URL without embedded credentials or query strings.';
			}

			if ($ufmCa !== '' && !SecureFile::pathAcceptable($ufmCa)) {
				$errors['ufm_ca_file'] = 'UFM CA Bundle: enter an absolute path without traversal.';
			}

			if ($ufmPin !== '' && UfmSocketTransport::normalizeSpkiPin($ufmPin) === null) {
				$errors['ufm_spki_pin'] = 'UFM SPKI Pin: enter sha256/<base64> or 64 hex characters.';
			}
		}

		if ($errors !== [] || $backend === null) {
			return new self(null, $errors);
		}

		return new self(new Fabric(
			$id,
			$name,
			$backend,
			$poller,
			$every,
			$adapter,
			$port,
			Row::bool($input, 'enabled') ? AdminState::Enabled : AdminState::Disabled,
			$ufmUrl,
			$ufmCa,
			$ufmPin,
			$ufmCred,
		), []);
	}
}
