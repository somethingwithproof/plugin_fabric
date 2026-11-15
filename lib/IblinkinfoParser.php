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
 * Parses iblinkinfo --line, the source of port state and of the
 * "Could be <width>" and "Could be <speed>" hints that encode the
 * supported-intersection rule (docs/research/link-downgrade.md, decision 2).
 * Formats are those of rdma-core v66.0 infiniband-diags/iblinkinfo.c
 * print_port() in line mode.
 *
 * A down port prints no width or speed, by C14-24.2.1; neither is recorded.
 * The best width and speed are the hint when one is printed and the active
 * value otherwise, and exist only for a port with a peer. A hint the parser
 * cannot read leaves the best value unknown, never the active one
 * (REQ-LINK-006).
 *
 * Any line that does not match is a ParseFailure: link state is replaced as
 * a whole or not at all.
 */
final class IblinkinfoParser {
	private const LINE   = '/^0x([0-9a-f]{16}) "(.*?)" +(\d{1,5}) +(\d{1,3})\[[ 0-9]{2}\] ==\((.*?)\)==>  (.*)$/';
	private const LINK   = '/^ *(?:(undefined \(\d+\)|\S+) +(.+?) +)?(\S+)\/ *(\S+)$/';
	private const REMOTE = '/^0x([0-9a-f]{16}) +(\d{1,5}) +(\d{1,3})\[[ 0-9]{2}\] "(.*)" \(((?:Could be \S+)?) ((?:Could be .+)?)\)$/';

	/**
	 * @return list<LinkRecord>
	 */
	public function parse(string $output): array {
		$records = [];
		$seen    = [];

		foreach (preg_split('/\r?\n/', $output) ?: [] as $index => $line) {
			$number = $index + 1;

			if ($line === '') {
				continue;
			}

			if (preg_match(self::LINE, $line, $m) !== 1 || preg_match(self::LINK, $m[5], $l) !== 1) {
				throw ParseFailure::at($number, 'unrecognized line');
			}

			$guid = Guid::tryFromString($m[1]);
			$port = (int) $m[4];

			if ($guid === null || $port > PortRef::MAX_PORT || isset($seen[$guid->text . ':' . $port])) {
				throw ParseFailure::at($number, 'port GUID or number invalid or repeated');
			}

			$seen[$guid->text . ':' . $port] = true;
			$logical = LogicalState::fromText($l[3]);
			$down    = $logical === LogicalState::Down;

			// A down port prints state only; any other prints width and speed.
			if ($down === ($l[1] !== '')) {
				throw ParseFailure::at($number, 'link fields do not match the port state');
			}

			$activeWidth = $down ? null : LinkWidth::fromText($l[1]);
			$activeSpeed = $down ? null : LinkSpeed::fromRate($l[2]);
			$remote      = $m[6];

			if (preg_match('/^ +\[  \] "" \( \)$/', $remote) === 1 || preg_match('/^ +"Port not available"$/', $remote) === 1) {
				$records[] = new LinkRecord($guid, (int) $m[3], $port, null, null, new LinkState($logical, PhysState::fromText($l[4]), $activeWidth, $activeSpeed, null, null, null, null, false));

				continue;
			}

			if (preg_match(self::REMOTE, $remote, $r) !== 1) {
				throw ParseFailure::at($number, 'remote end');
			}

			$remoteGuid = Guid::tryFromString($r[1]);

			if ($remoteGuid === null || (int) $r[3] > PortRef::MAX_PORT) {
				throw ParseFailure::at($number, 'remote port GUID or number invalid');
			}

			$bestWidth = $r[5] === '' ? $activeWidth : LinkWidth::fromText(substr($r[5], strlen('Could be ')));
			$bestSpeed = $r[6] === '' ? $activeSpeed : self::hintSpeed(substr($r[6], strlen('Could be ')));

			$records[] = new LinkRecord($guid, (int) $m[3], $port, $remoteGuid, (int) $r[3], new LinkState($logical, PhysState::fromText($l[4]), $activeWidth, $activeSpeed, $bestWidth, $bestSpeed, null, null, true));
		}

		return $records;
	}

	/**
	 * "25.78125 Gbps", "10.0 Gbps", "FDR10", or
	 * "FDR10 (Found link at QDR but expected speed is FDR10)".
	 */
	private static function hintSpeed(string $text): ?LinkSpeed {
		$text = (string) preg_replace('/ \(Found link at .*\)$/', '', $text);

		return LinkSpeed::fromRate($text) ?? LinkSpeed::fromName($text);
	}
}
