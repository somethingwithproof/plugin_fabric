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
 * Parses ibnetdiscover's default topology output, the one form that prints
 * node GUIDs (docs/research/identity.md). The line formats are those of
 * rdma-core v66.0 infiniband-diags/ibnetdiscover.c (out_ids, out_switch,
 * out_ca, out_switch_port, out_ca_port), unchanged in their node and port
 * parts since v39.0. Options that change the layout (-g, -s, -f, -p, -l)
 * are never passed, so their forms are rejected.
 *
 * Every line must match a rule, and the result must be whole: each node
 * block complete, each port of a node at most once and within its port
 * count, and each link printed from both ends. Output cut short, or with
 * any line the parser does not know, is a ParseFailure and imports nothing
 * (REQ-INV-004). Descriptions are fabric text; they are cleaned and cut to
 * 64 characters and never used for identity (REQ-INV-002, REQ-INV-007).
 */
final class IbnetdiscoverParser {
	private const GUID = '([0-9a-fA-F]{1,16})';
	private const NAME = '"([SHR])-([0-9a-f]{16})"';
	private const EXT  = '(?:\[ext \d+\])?';

	/** @var array<string, TopologyNode> */
	private array $nodes = [];

	/** @var list<string> */
	private array $duplicates = [];

	/** @var array{type: NodeType, guid: Guid, port0: ?Guid, vendid: int, devid: int, sysimg: ?Guid, count: int, desc: string, lid: int}|null */
	private ?array $current = null;

	/** @var array<int, TopologyPort> */
	private array $ports = [];

	public function parse(string $output): Topology {
		$this->nodes      = [];
		$this->duplicates = [];
		$this->current    = null;
		$this->ports      = [];

		$local   = null;
		$ids     = [];
		$pending = null;
		$types   = [];

		foreach (preg_split('/\r?\n/', $output) ?: [] as $index => $line) {
			$number = $index + 1;

			if ($line === '') {
				continue;
			}

			if ($line[0] === '#') {
				if (preg_match('/^# Initiated from node ([0-9a-f]{16}) port [0-9a-f]{16}$/', $line, $m) === 1) {
					$local = Guid::tryFromString($m[1]);
				}

				continue;
			}

			if (preg_match('/^(vendid|devid|sysimgguid)=0x([0-9a-fA-F]{1,16})$/', $line, $m) === 1) {
				$this->finish();

				if ($pending !== null) {
					throw ParseFailure::at($number, 'identifiers of a node block without its GUID line');
				}

				$ids[$m[1]] = $m[2];

				continue;
			}

			if (preg_match('/^(switch|ca|rt)guid=0x' . self::GUID . '(?:\(' . self::GUID . '\))?$/', $line, $m) === 1) {
				$this->finish();
				$guid = Guid::tryFromString($m[2]);

				if ($guid === null || !isset($ids['vendid'], $ids['devid']) || ($m[1] === 'switch') !== isset($m[3])) {
					throw ParseFailure::at($number, 'incomplete node identifiers');
				}

				$pending = [
					'type'   => match ($m[1]) {
						'switch' => NodeType::Switch,
						'ca'     => NodeType::Adapter,
						default  => NodeType::Router,
					},
					'guid'   => $guid,
					'port0'  => isset($m[3]) ? Guid::tryFromString($m[3]) : null,
					'vendid' => (int) hexdec($ids['vendid']),
					'devid'  => (int) hexdec($ids['devid']),
					'sysimg' => isset($ids['sysimgguid']) ? Guid::tryFromString($ids['sysimgguid']) : null,
				];
				$ids = [];

				continue;
			}

			if (preg_match('/^(Switch|Ca|Rt)\t(\d{1,3}) ' . self::NAME . '\t\t# "(.*)"(?: (?:enhanced|base) port 0 lid (\d{1,5}) lmc \d{1,2})?$/', $line, $m) === 1) {
				$type = ['Switch' => NodeType::Switch, 'Ca' => NodeType::Adapter, 'Rt' => NodeType::Router][$m[1]];

				if ($pending === null || $pending['type'] !== $type || $pending['guid']->text !== '0x' . $m[4] || self::letter($type) !== $m[3] || ($type === NodeType::Switch) !== isset($m[6])) {
					throw ParseFailure::at($number, 'node line does not match its GUID line');
				}

				$this->current = $pending + ['count' => (int) $m[2], 'desc' => self::description($m[5]), 'lid' => isset($m[6]) ? (int) $m[6] : 0];
				$pending = null;

				continue;
			}

			if (preg_match('/^\[(\d{1,3})\]' . self::EXT . '(?:\(' . self::GUID . '\) )?\t' . self::NAME . '\[(\d{1,3})\]' . self::EXT . ' ?(?:\(' . self::GUID . '\) )?\t\t# (.*)$/', $line, $m) === 1) {
				if ($this->current === null) {
					throw ParseFailure::at($number, 'port line outside a node block');
				}

				$port = (int) $m[1];

				if ($port < 1 || $port > $this->current['count'] || isset($this->ports[$port]) || (int) $m[5] > PortRef::MAX_PORT) {
					throw ParseFailure::at($number, 'port number out of range or repeated');
				}

				// Adapter and router ports print their own GUID; switch ports never do.
				if (($this->current['type'] === NodeType::Switch) === ($m[2] !== '')) {
					throw ParseFailure::at($number, 'port GUID where none belongs, or missing');
				}

				$remoteGuid = Guid::tryFromString($m[4]);

				if ($remoteGuid === null || ($m[3] === 'S') === ($m[6] !== '')) {
					throw ParseFailure::at($number, 'remote port GUID where none belongs, or missing');
				}

				$remote = new PortRef($remoteGuid, (int) $m[5]);

				$comment = $this->current['type'] === NodeType::Switch
					? preg_match('/^"(.*)" lid (\d{1,5}) (\S+)$/', $m[7], $c)
					: preg_match('/^lid (\d{1,5}) lmc \d{1,2} "(.*)" lid (\d{1,5}) (\S+)$/', $m[7], $c);

				if ($comment !== 1) {
					throw ParseFailure::at($number, 'port comment');
				}

				[$width, $speed] = self::widthSpeed($this->current['type'] === NodeType::Switch ? $c[3] : $c[4]);

				$this->ports[$port] = new TopologyPort($port, $m[2] !== '' ? Guid::tryFromString($m[2]) : null, $this->current['type'] === NodeType::Switch ? 0 : (int) $c[1], $remote, $width, $speed);
				$types[$remote->node->text] = $m[3];

				continue;
			}

			throw ParseFailure::at($number, 'unrecognized line');
		}

		$this->finish();

		if ($pending !== null || $ids !== []) {
			throw ParseFailure::at(0, 'output ends inside a node block');
		}

		if ($this->nodes === []) {
			throw ParseFailure::at(0, 'no nodes');
		}

		self::checkLinks($this->nodes, $this->duplicates, $types);

		return new Topology($this->nodes, $local, array_values(array_unique($this->duplicates)));
	}

	private function finish(): void {
		$current = $this->current;

		if ($current === null) {
			return;
		}

		$node = new TopologyNode($current['guid'], $current['type'], $current['count'], $current['devid'], $current['vendid'], $current['sysimg'], $current['port0'], $current['desc'], $current['lid'], $this->ports);

		if (isset($this->nodes[$node->guid->text])) {
			$this->duplicates[] = $node->guid->text;
		} else {
			$this->nodes[$node->guid->text] = $node;
		}

		$this->current = null;
		$this->ports   = [];
	}

	/**
	 * Every link must be printed from both ends, as libibnetdisc links both
	 * ports of each pair. A one-sided link means output that was cut short
	 * or altered. Links touching a GUID printed twice cannot be checked and
	 * are left to the conflict that GUID raises.
	 *
	 * @param array<string, TopologyNode> $nodes
	 * @param list<string>                $duplicates
	 * @param array<string, string>       $types GUID text => the type letter its remote ends printed
	 */
	private static function checkLinks(array $nodes, array $duplicates, array $types): void {
		foreach ($nodes as $guid => $node) {
			foreach ($node->ports as $port) {
				$remoteGuid = $port->remote->node->text;

				if (in_array($guid, $duplicates, true) || in_array($remoteGuid, $duplicates, true)) {
					continue;
				}

				$peer = $nodes[$remoteGuid] ?? null;
				$back = $peer?->ports[$port->remote->port] ?? null;

				if ($peer === null || self::letter($peer->type) !== ($types[$remoteGuid] ?? '') || $back === null || $back->remote->node->text !== $guid || $back->remote->port !== $port->number) {
					throw ParseFailure::at(0, 'a link is not printed from both ends');
				}
			}
		}
	}

	private static function letter(NodeType $type): string {
		return match ($type) {
			NodeType::Switch  => 'S',
			NodeType::Adapter => 'H',
			NodeType::Router  => 'R',
		};
	}

	/**
	 * "4xEDR" as ibnetdiscover prints a link; "??" and "???" are its unknowns.
	 *
	 * @return array{0: ?LinkWidth, 1: ?LinkSpeed}
	 */
	private static function widthSpeed(string $token): array {
		if (preg_match('/^(1x|2x|4x|8x|12x|\?\?)(.+)$/', $token, $m) !== 1) {
			return [null, null];
		}

		return [LinkWidth::fromText($m[1]), LinkSpeed::fromName($m[2])];
	}

	/**
	 * Fabric-supplied text, kept for display only: printable ASCII, single
	 * spaces, at most 64 characters.
	 */
	public static function description(string $text): string {
		$clean = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\x20-\x7e]/', ' ', $text)));

		return substr($clean, 0, 64);
	}
}
