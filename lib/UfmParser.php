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
 * Parses NVIDIA UFM REST JSON responses (resources/ports and
 * monitoring/session/0/data) into the plugin's shared Topology, LinkReport,
 * and Counters models so UFM and infiniband-diags feed the same tables.
 */
final class UfmParser {
	/** Counter map constant for UfmParser. */
	private const COUNTER_MAP = [
		'Infiniband_MBOut'               => 'PortXmitData',
		'Infiniband_MBIn'                => 'PortRcvData',
		'Infiniband_PckOut'              => 'PortXmitPkts',
		'Infiniband_PckIn'               => 'PortRcvPkts',
		'Infiniband_SymbolErrors'        => 'SymbolErrorCounter',
		'Infiniband_LinkRecovers'        => 'LinkErrorRecoveryCounter',
		'Infiniband_LinkDowned'          => 'LinkDownedCounter',
		'Infiniband_RcvErrors'           => 'PortRcvErrors',
		'Infiniband_RcvRemotePhysErrors' => 'PortRcvRemotePhysicalErrors',
		'Infiniband_RcvSwRelayErrors'    => 'PortRcvSwitchRelayErrors',
		'Infiniband_XmtDiscards'         => 'PortXmitDiscards',
		'Infiniband_XmtConstraintErrors' => 'PortXmitConstraintErrors',
		'Infiniband_RcvConstraintErrors' => 'PortRcvConstraintErrors',
		'Infiniband_LinkIntegrityErrors' => 'LocalLinkIntegrityErrors',
		'Infiniband_ExcBufOverrunErrors' => 'ExcessiveBufferOverrunErrors',
		'Infiniband_VL15Dropped'         => 'VL15Dropped',
		'Infiniband_XmitWait'            => 'PortXmitWait',
	];

	/**
	 * Parse UFM /ufmRest/resources/ports response (paged envelope or raw list)
	 * into a Topology and LinkReport.
	 *
	 * @param array<array-key, mixed> $payload
	 *
	 * @return array{topology: Topology, links: LinkReport}
	 *
	 * @throws ParseFailure
	 */
	public function parsePorts(array $payload): array {
		$items = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;

		if (!array_is_list($items)) {
			throw ParseFailure::at(0, 'UFM ports payload is not a list');
		}

		/** @var array<string, array{guid: Guid, type: NodeType, desc: string, lid: int, ports: array<int, TopologyPort>, maxPort: int}> $nodes */
		$nodes   = [];
		$records = [];

		foreach ($items as $index => $item) {
			if (!is_array($item)) {
				throw ParseFailure::at($index + 1, 'UFM port entry is not an object');
			}

			$rawSysGuid = self::str($item, 'systemID');
			$rawGuid    = self::str($item, 'guid');
			$sysGuid    = Guid::tryFromString($rawSysGuid !== '' ? $rawSysGuid : $rawGuid);
			$portNum    = self::int($item, 'number');

			if ($sysGuid === null || $portNum < 1 || $portNum > PortRef::MAX_PORT) {
				continue;
			}

			$rawType  = self::str($item, 'system_type');
			$nodeType = self::nodeType($rawType !== '' ? $rawType : self::str($item, 'tier'));
			$rawDesc  = self::str($item, 'system_name');
			$desc     = self::cleanDesc($rawDesc !== '' ? $rawDesc : self::str($item, 'description'));
			$lid      = max(0, self::int($item, 'lid'));
			$portGuid = ($rawGuid !== '' ? Guid::tryFromString($rawGuid) : null) ?? $sysGuid;

			if (!isset($nodes[$sysGuid->text])) {
				$nodes[$sysGuid->text] = [
					'guid'    => $sysGuid,
					'type'    => $nodeType,
					'desc'    => $desc,
					'lid'     => $nodeType === NodeType::Switch ? $lid : 0,
					'ports'   => [],
					'maxPort' => 0,
				];
			}

			$nodes[$sysGuid->text]['maxPort'] = max($nodes[$sysGuid->text]['maxPort'], $portNum);

			$activeWidth = LinkWidth::fromText(self::str($item, 'active_width'));
			$activeSpeed = LinkSpeed::fromName(self::str($item, 'active_speed'));
			$bestWidth   = self::maxWidth($item['enabled_width'] ?? $item['supported_width'] ?? null);
			$bestSpeed   = self::maxSpeed($item['enabled_speed'] ?? $item['supported_speed'] ?? null);
			$logical     = LogicalState::fromText(self::str($item, 'logical_state'));
			$phys        = PhysState::fromText(self::str($item, 'physical_state'));

			$rawPeerGuid = self::str($item, 'peer_node_guid');
			$peerGuid    = Guid::tryFromString($rawPeerGuid !== '' ? $rawPeerGuid : self::str($item, 'peer_guid'));
			$peerPort = self::parsePeerPort($item);
			$hasPeer  = $peerGuid !== null && $peerPort !== null;

			if ($hasPeer) {
				$nodes[$sysGuid->text]['ports'][$portNum] = new TopologyPort(
					number: $portNum,
					portGuid: $portGuid,
					lid: $lid,
					remote: new PortRef($peerGuid, $peerPort),
					width: $activeWidth,
					speed: $activeSpeed,
				);
			}

			$records[] = new LinkRecord(
				portGuid: $portGuid,
				lid: $lid,
				port: $portNum,
				remotePortGuid: $hasPeer ? $peerGuid : null,
				remotePort: $hasPeer ? $peerPort : null,
				link: new LinkState(
					logical: $logical,
					physical: $phys,
					activeWidth: $activeWidth,
					activeSpeed: $activeSpeed,
					bestWidth: $bestWidth,
					bestSpeed: $bestSpeed,
					enabledWidth: $bestWidth,
					enabledSpeed: $bestSpeed,
					hasPeer: $hasPeer,
				),
			);
		}

		$topologyNodes = [];

		foreach ($nodes as $guidText => $entry) {
			$topologyNodes[$guidText] = new TopologyNode(
				guid: $entry['guid'],
				type: $entry['type'],
				portCount: $entry['maxPort'],
				deviceId: 0,
				vendorId: 0,
				sysimgGuid: $entry['guid'],
				port0Guid: $entry['type'] === NodeType::Switch ? $entry['guid'] : null,
				description: $entry['desc'],
				lid: $entry['lid'],
				ports: $entry['ports'],
			);
		}

		return [
			'topology' => new Topology($topologyNodes, null, []),
			'links'    => new LinkReport($records, []),
		];
	}

	/**
	 * Parse UFM /ufmRest/monitoring/session/0/data response into per-port Counters
	 * keyed by "<canonical_guid>:<port_number>".
	 *
	 * @param array<array-key, mixed> $payload
	 *
	 * @return array<string, Counters>
	 */
	public function parseSessionCounters(array $payload): array {
		$out = [];

		foreach ($payload as $bucket) {
			if (!is_array($bucket)) {
				continue;
			}

			$ports = isset($bucket['Ports']) && is_array($bucket['Ports']) ? $bucket['Ports'] : $bucket;

			foreach ($ports as $portKey => $entry) {
				if (!is_array($entry)) {
					continue;
				}

				$ref = self::parsePortName(is_string($portKey) ? $portKey : self::str($entry, 'name'));

				if ($ref === null) {
					continue;
				}

				$stats = isset($entry['statistics']) && is_array($entry['statistics']) ? $entry['statistics'] : $entry;
				$vals  = [];

				foreach (self::COUNTER_MAP as $ufmName => $ibName) {
					if (!array_key_exists($ufmName, $stats)) {
						continue;
					}

					$raw = $stats[$ufmName];

					if ($raw === null) {
						$vals[$ibName] = null;
					} elseif (is_int($raw) && $raw >= 0) {
						$vals[$ibName] = (string) $raw;
					} elseif (is_string($raw) && preg_match('/^[0-9]{1,20}$/D', $raw) === 1) {
						$vals[$ibName] = $raw;
					}
				}

				if ($vals !== []) {
					$out[$ref->key()] = new Counters(
						group: CounterGroup::Extended,
						lid: 0,
						port: $ref->port,
						capMask: Counters::CAP_EXT_WIDTH,
						capMask2: null,
						values: $vals,
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Parse UFM cable-info or transceiver telemetry payload into optical DOM
	 * samples keyed by canonical port identifier.
	 *
	 * @param array<array-key, mixed> $payload decoded UFM cable-info JSON
	 *
	 * @return array<string, OpticalDomSample>
	 *
	 * @throws ParseFailure
	 */
	public function parseOpticalDom(array $payload): array {
		return (new OpticalDomParser())->parseUfmCableInfo($payload);
	}

	/**
	 * Parse port name.
	 *
	 * @param string $name
	 * @return ?PortRef
	 */
	private static function parsePortName(string $name): ?PortRef {
		if (preg_match('/^(?:0x)?([0-9A-Fa-f]{1,16})_([0-9]{1,3})$/D', trim($name), $m) !== 1) {
			return null;
		}

		$guid = Guid::tryFromString($m[1]);
		$port = (int) $m[2];

		if ($guid === null || $port < 1 || $port > PortRef::MAX_PORT) {
			return null;
		}

		return new PortRef($guid, $port);
	}

	/**
	 * @param array<array-key, mixed> $item
	 */
	private static function parsePeerPort(array $item): ?int {
		$direct = self::int($item, 'peer_port');

		if ($direct >= 1 && $direct <= PortRef::MAX_PORT) {
			return $direct;
		}

		$peer = self::str($item, 'peer');

		if (preg_match('/_([0-9]{1,3})$/', $peer, $m) === 1) {
			$port = (int) $m[1];

			return $port >= 1 && $port <= PortRef::MAX_PORT ? $port : null;
		}

		return null;
	}

	/**
	 * Node type.
	 *
	 * @param string $raw
	 * @return NodeType
	 */
	private static function nodeType(string $raw): NodeType {
		$lower = strtolower(trim($raw));

		return match (true) {
			str_contains($lower, 'switch') => NodeType::Switch,
			str_contains($lower, 'router') => NodeType::Router,
			default                        => NodeType::Adapter,
		};
	}

	/**
	 * Max width.
	 *
	 * @param mixed $raw
	 * @return ?LinkWidth
	 */
	private static function maxWidth(mixed $raw): ?LinkWidth {
		if (!is_array($raw)) {
			return is_string($raw) ? LinkWidth::fromText($raw) : null;
		}

		$best = null;

		foreach ($raw as $entry) {
			$w = is_string($entry) ? LinkWidth::fromText($entry) : null;

			if ($w !== null && ($best === null || $w->lanes() > $best->lanes())) {
				$best = $w;
			}
		}

		return $best;
	}

	/**
	 * Max speed.
	 *
	 * @param mixed $raw
	 * @return ?LinkSpeed
	 */
	private static function maxSpeed(mixed $raw): ?LinkSpeed {
		if (!is_array($raw)) {
			return is_string($raw) ? LinkSpeed::fromName($raw) : null;
		}

		$best = null;

		foreach ($raw as $entry) {
			$s = is_string($entry) ? LinkSpeed::fromName($entry) : null;

			if ($s !== null && ($best === null || $s->rank() > $best->rank())) {
				$best = $s;
			}
		}

		return $best;
	}

	/**
	 * Clean desc.
	 *
	 * @param string $value
	 * @return string
	 */
	private static function cleanDesc(string $value): string {
		$clean = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value);

		return substr(trim(is_string($clean) ? $clean : ''), 0, 64);
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	private static function str(array $row, string $key): string {
		$value = $row[$key] ?? '';

		return is_scalar($value) ? trim((string) $value) : '';
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	private static function int(array $row, string $key): int {
		$value = $row[$key] ?? 0;

		return is_numeric($value) ? (int) $value : 0;
	}
}
