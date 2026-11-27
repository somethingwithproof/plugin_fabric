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
 * Composes the only argument vectors the plugin runs (REQ-EXEC-001 to
 * REQ-EXEC-003, ADR 0009).
 *
 * - The program is the allowlisted tool's name under the administrator's
 *   tool directory, which must be an absolute plain path.
 * - Options come from a fixed per-tool allowlist. Nothing that resets or
 *   clears counters (-r, -R, -k, -K, --reset_after_read, --Reset_only,
 *   --clear-errors, --clear-counts), sets a key, or writes a file is ever
 *   composed, and perfquery's third positional, a reset mask, never is.
 * - The only values from configuration or the fabric are the adapter name
 *   and port, the datagram timeout, the node name map path, a LID, and port
 *   numbers. Each is checked against a strict pattern or range here, again,
 *   whatever validated it before; a value that fails is an
 *   InvalidArgumentException and nothing runs.
 * - ibnetdiscover and iblinkinfo get no positional argument: ibnetdiscover's
 *   first one names a file to write.
 */
final class CommandBuilder {
	/** Maximum ports. */
	public const MAX_PORTS = 64;
	/** Maximum lid. */
	public const MAX_LID   = 0xBFFF;

	/** Every option each tool may receive. */
	public const OPTIONS = [
		'perfquery'     => ['-x', '-t', '-C', '-P'],
		'ibnetdiscover' => ['-t', '-C', '-P', '--node-name-map'],
		'iblinkinfo'    => ['--line', '-t', '-C', '-P', '--node-name-map'],
	];

	/**
	 * Construct a new CommandBuilder instance.
	 *
	 * @param Settings $settings
	 * @param Fabric $fabric
	 */
	public function __construct(private readonly Settings $settings, private readonly Fabric $fabric) {
	}

	/**
	 * Discovery.
	 *
	 * @return Command
	 */
	public function discovery(): Command {
		return $this->build(Tool::Ibnetdiscover, [...$this->common(), ...$this->nameMap()]);
	}

	/**
	 * Link info.
	 *
	 * @return Command
	 */
	public function linkInfo(): Command {
		return $this->build(Tool::Iblinkinfo, ['--line', ...$this->common(), ...$this->nameMap()]);
	}

	/**
	 * perfquery for one or more ports of the node at $lid. A switch takes
	 * a comma-separated list; an adapter port is queried at its own LID.
	 *
	 * @param list<int> $ports
	 */
	public function counters(int $lid, array $ports, bool $extended): Command {
		if ($lid < 1 || $lid > self::MAX_LID) {
			throw new \InvalidArgumentException('LID out of range');
		}

		if ($ports === [] || count($ports) > self::MAX_PORTS || count(array_unique($ports)) !== count($ports)) {
			throw new \InvalidArgumentException('port list empty, too long, or repeated');
		}

		foreach ($ports as $port) {
			if ($port < 1 || $port > PortRef::MAX_PORT) {
				throw new \InvalidArgumentException('port out of range');
			}
		}

		$options = $extended ? ['-x', ...$this->common()] : $this->common();

		return $this->build(Tool::Perfquery, [...$options, (string) $lid, implode(',', array_map('strval', $ports))]);
	}

	/**
	 * @return list<string>
	 */
	private function common(): array {
		$timeout = $this->settings->madTimeoutMs;

		if ($timeout < 100 || $timeout > 10000) {
			throw new \InvalidArgumentException('datagram timeout out of range');
		}

		if (preg_match(Fabric::ADAPTER_PATTERN, $this->fabric->adapter) !== 1) {
			throw new \InvalidArgumentException('adapter name invalid');
		}

		if ($this->fabric->adapterPort < 1 || $this->fabric->adapterPort > PortRef::MAX_PORT) {
			throw new \InvalidArgumentException('adapter port out of range');
		}

		return ['-t', (string) $timeout, '-C', $this->fabric->adapter, '-P', (string) $this->fabric->adapterPort];
	}

	/**
	 * @return list<string>
	 */
	private function nameMap(): array {
		$map = $this->settings->nodeNameMap;

		if ($map === '') {
			return [];
		}

		if (!Settings::validPath($map)) {
			throw new \InvalidArgumentException('node name map path invalid');
		}

		return ['--node-name-map', $map];
	}

	/**
	 * @param list<string> $arguments
	 */
	private function build(Tool $tool, array $arguments): Command {
		$dir = $this->settings->toolDir;

		if (!Settings::validPath($dir)) {
			throw new \InvalidArgumentException('tool directory invalid');
		}

		$allowed = self::OPTIONS[$tool->value];

		foreach ($arguments as $argument) {
			if (str_starts_with($argument, '-') && !in_array($argument, $allowed, true)) {
				throw new \InvalidArgumentException('option not allowed');
			}
		}

		return Command::fromBuilder($this, $tool, [rtrim($dir, '/') . '/' . $tool->value, ...$arguments]);
	}
}
