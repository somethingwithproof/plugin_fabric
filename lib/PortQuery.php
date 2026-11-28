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
 * Answers the Fabric Port data query (resource/script_server/fabric_ports.xml)
 * for one Cacti device, from the inventory and sample cache
 * (REQ-IDX-001 to REQ-IDX-005).
 *
 * - index and num_indexes list "<node GUID>:<port number>" for the ports
 *   of every node mapped to the device, ordered by GUID and then port
 *   number, so an index never depends on a position (ADR 0003).
 * - query prints "<index>!<value>" lines for one field; the delimiter is
 *   "!" because an index contains a colon.
 * - get prints one value, or U.
 *
 * Only get, which the poller sends through the script server, starts a
 * sweep: once per device and process, before the first answer, through the
 * closure the caller passes (ADR 0008). index and query, which Cacti runs
 * when a device is reindexed, never contact the fabric.
 *
 * A device's ports are read once and kept for CACHE_SECONDS, so a poll
 * cycle's calls for one device cost one read of the cache tables.
 */
final class PortQuery {
	/** Delimiter constant for PortQuery. */
	public const DELIMITER    = '!';
	/** Index field constant for PortQuery. */
	public const INDEX_FIELD  = 'fabricPort';
	/** Input fields constant for PortQuery. */
	public const INPUT_FIELDS = ['fabricPort', 'fabricGuid', 'fabricPortNum', 'fabricNode', 'fabricRemote'];

	/** Cache seconds constant for PortQuery. */
	private const CACHE_SECONDS = 30;

	/** @var array<int, array{0: int, 1: array<string, PortReading>}> host id => [read at, index => port] */
	private array $cache = [];

	/** @var array<int, true> */
	private array $swept = [];

	/**
	 * @param (\Closure(int): void)|null $sweep starts a due sweep for a device
	 */
	public function __construct(
		private readonly \PDO|false $connection,
		private readonly Clock $clock,
		private readonly bool $indexDownPorts,
		private readonly ?\Closure $sweep = null,
	) {
	}

	/**
	 * Answer.
	 *
	 * @param int $hostId
	 * @param string $cmd
	 * @param string $field
	 * @param string $index
	 * @return string
	 */
	public function answer(int $hostId, string $cmd, string $field = '', string $index = ''): string {
		if ($hostId < 1) {
			return $cmd === 'get' ? 'U' : '';
		}

		if ($cmd === 'get') {
			return $this->get($hostId, $field, $index);
		}

		$ports = $this->ports($hostId) ?? [];

		return match ($cmd) {
			'index'       => implode('', array_map(static fn (string $i): string => $i . "\n", array_keys($ports))),
			'num_indexes' => (string) count($ports),
			'query'       => $this->query($ports, $field),
			default       => '',
		};
	}

	/**
	 * The device's indexed ports in index order, or null when the cache
	 * could not be read.
	 *
	 * @return array<string, PortReading>|null
	 */
	public function ports(int $hostId): ?array {
		$now = $this->clock->now();

		if (isset($this->cache[$hostId]) && $now - $this->cache[$hostId][0] < self::CACHE_SECONDS) {
			return $this->cache[$hostId][1];
		}

		$readings = (new PortReadingRepository($this->connection, $this->clock))->forHost($hostId);

		if ($readings === null) {
			unset($this->cache[$hostId]);

			return null;
		}

		$out = [];

		foreach ($readings as $reading) {
			if ($reading->indexed($this->indexDownPorts)) {
				$out[$reading->ref()->key()] = $reading;
			}
		}

		$this->cache[$hostId] = [$now, $out];

		return $out;
	}

	/**
	 * Text of an input field: what Cacti stores in its query cache and may
	 * put in titles. Fabric-supplied text loses control characters and
	 * vertical bars, so it can neither break a line nor name a Cacti
	 * substitution variable such as a device's SNMP community.
	 */
	public static function input(PortReading $reading, string $field): ?string {
		return match ($field) {
			'fabricPort'    => $reading->ref()->key(),
			'fabricGuid'    => $reading->node->guid->text,
			'fabricPortNum' => (string) $reading->port->number,
			'fabricNode'    => self::clean($reading->node->label()),
			'fabricRemote'  => $reading->port->remote?->key() ?? '',
			default         => null,
		};
	}

	/**
	 * Clean.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function clean(string $text): string {
		return substr(trim((string) preg_replace('/[^\x20-\x7b\x7d\x7e]+/', ' ', $text)), 0, 96);
	}

	/**
	 * Get.
	 *
	 * @param int $hostId
	 * @param string $field
	 * @param string $index
	 * @return string
	 */
	private function get(int $hostId, string $field, string $index): string {
		if ($this->sweep !== null && !isset($this->swept[$hostId])) {
			$this->swept[$hostId] = true;
			($this->sweep)($hostId);
			unset($this->cache[$hostId]);
		}

		$reading = PortRef::fromKey($index) === null ? null : ($this->ports($hostId)[$index] ?? null);

		if ($reading === null) {
			return 'U';
		}

		$value = in_array($field, self::INPUT_FIELDS, true) ? self::input($reading, $field) : $reading->value($field);

		return $value === null || $value === '' ? 'U' : $value;
	}

	/**
	 * @param array<string, PortReading> $ports
	 */
	private function query(array $ports, string $field): string {
		$known = in_array($field, self::INPUT_FIELDS, true) || in_array($field, PortValues::names(), true);

		if (!$known) {
			return '';
		}

		$out = '';

		foreach ($ports as $index => $reading) {
			$value = in_array($field, self::INPUT_FIELDS, true) ? self::input($reading, $field) : $reading->value($field);
			$out  .= $index . self::DELIMITER . ($value ?? 'U') . "\n";
		}

		return $out;
	}
}
