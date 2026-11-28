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
 * Two fields are free text: the local node description, quoted after the
 * port GUID, and the remote one, quoted near the end. Either may hold
 * quotes, "==(", ")==>", or GUID-like text, so a line is not split at the
 * first or last of them. The port GUID and opening quote at the start are
 * fixed. Every quote after them is tried as the end of the local
 * description; the remote description is read greedily, as only the fixed
 * hint fields follow it. A line that reads in exactly one way is a record.
 * A line that starts like a port line but reads in none or in more than
 * one is skipped and its port GUID reported, so one hostile description
 * cannot hide the rest of the fabric's link states. Any other unknown
 * line, a repeated port, fields that contradict the port state, or output
 * that does not end in a newline (cut short) is a ParseFailure: link
 * state is then replaced as a whole or not at all.
 */
final class IblinkinfoParser {
	/** Prefix constant for IblinkinfoParser. */
	private const PREFIX = '/^0x([0-9a-f]{16}) "/';
	// From the quote that ends the local description: LID, port, and the
	// link fields, which hold no parenthesis except "undefined (n)".
	/** Tail constant for IblinkinfoParser. */
	private const TAIL   = '/\G" +(\d{1,5}) +(\d{1,3})\[[ 0-9]{2}\] ==\(((?:[^()]|\(\d+\))*)\)==>  (.*)$/D';
	/** Link constant for IblinkinfoParser. */
	private const LINK   = '/^ *(?:(undefined \(\d+\)|\S+) +(.+?) +)?(\S+)\/ *(\S+)$/D';
	/** Remote constant for IblinkinfoParser. */
	private const REMOTE = '/^0x([0-9a-f]{16}) +(\d{1,5}) +(\d{1,3})\[[ 0-9]{2}\] "(.*)" \(((?:Could be \S+)?) ((?:Could be [^"]+)?)\)$/D';
	/** None constant for IblinkinfoParser. */
	private const NONE   = ['/^ +\[  \] "" \( \)$/D', '/^ +"Port not available"$/D'];

	/**
	 * Parse.
	 *
	 * @param string $output
	 * @return LinkReport
	 */
	public function parse(string $output): LinkReport {
		if ($output !== '' && !str_ends_with($output, "\n")) {
			throw ParseFailure::at(substr_count($output, "\n") + 1, 'output does not end with a newline');
		}

		$records = [];
		$skipped = [];
		$seen    = [];

		foreach (preg_split('/\r?\n/', $output) ?: [] as $index => $line) {
			$number = $index + 1;

			if ($line === '') {
				continue;
			}

			if (preg_match(self::PREFIX, $line, $p) !== 1 || ($guid = Guid::tryFromString($p[1])) === null) {
				throw ParseFailure::at($number, 'unrecognized line');
			}

			$readings = self::readings($line, strlen($p[0]));

			if (count($readings) !== 1) {
				$skipped[] = $guid;

				continue;
			}

			[$lid, $port, $l, $r] = $readings[0];

			if ($port > PortRef::MAX_PORT || isset($seen[$guid->text . ':' . $port])) {
				throw ParseFailure::at($number, 'port number invalid or repeated');
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

			if ($r === null) {
				$records[] = new LinkRecord($guid, $lid, $port, null, null, new LinkState($logical, PhysState::fromText($l[4]), $activeWidth, $activeSpeed, null, null, null, null, false));

				continue;
			}

			$remoteGuid = Guid::tryFromString($r[1]);

			if ($remoteGuid === null || (int) $r[3] > PortRef::MAX_PORT) {
				throw ParseFailure::at($number, 'remote port GUID or number invalid');
			}

			$bestWidth = $r[5] === '' ? $activeWidth : LinkWidth::fromText(substr($r[5], strlen('Could be ')));
			$bestSpeed = $r[6] === '' ? $activeSpeed : self::hintSpeed(substr($r[6], strlen('Could be ')));

			$records[] = new LinkRecord($guid, $lid, $port, $remoteGuid, (int) $r[3], new LinkState($logical, PhysState::fromText($l[4]), $activeWidth, $activeSpeed, $bestWidth, $bestSpeed, null, null, true));
		}

		return new LinkReport($records, $skipped);
	}

	/**
	 * Every way the line reads, trying each quote after $from as the end of
	 * the local description.
	 *
	 * @return list<array{int, int, list<string>, list<string>|null}> LID, port, link fields, remote fields or null for no peer
	 */
	private static function readings(string $line, int $from): array {
		$found = [];

		for ($at = strpos($line, '"', $from); $at !== false; $at = strpos($line, '"', $at + 1)) {
			if (preg_match(self::TAIL, $line, $m, 0, $at) !== 1 || preg_match(self::LINK, $m[3], $l) !== 1) {
				continue;
			}

			if (preg_match(self::NONE[0], $m[4]) === 1 || preg_match(self::NONE[1], $m[4]) === 1) {
				$found[] = [(int) $m[1], (int) $m[2], $l, null];
			} elseif (preg_match(self::REMOTE, $m[4], $r) === 1) {
				$found[] = [(int) $m[1], (int) $m[2], $l, $r];
			}
		}

		return $found;
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
