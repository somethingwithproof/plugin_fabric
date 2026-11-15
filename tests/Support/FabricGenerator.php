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

namespace Somethingwithproof\Fabric\Tests;

if (PHP_SAPI !== 'cli') {
	exit(1);
}

/**
 * A synthetic fabric and the tool output it would produce (REQ-TEST-004).
 *
 * The output follows the printf formats of rdma-core v66.0:
 * infiniband-diags/ibnetdiscover.c out_ids(), out_switch(), out_ca(),
 * out_switch_port(), and out_ca_port(); iblinkinfo.c print_port() in line
 * mode with ibdiag_common.c get_max_msg(); and perfquery.c
 * dump_perfcounters() with libibmad's field dump. It is generated, not
 * captured from a real fabric: recorded captures are a separate gap
 * (issue #14, REQ-TEST-001).
 *
 * Shape: leaf switches with 36 ports, adapters on ports 1 to $perSwitch,
 * and a chain of switch-to-switch links on ports 35 and 36. Every adapter
 * has two ports; port 2 is cabled to nothing and Down.
 */
final class FabricGenerator {
	public const SWITCH_BASE = 0x0002c90300a00000;
	public const CA_BASE     = 0x0002c90300b00000;

	/** @var array<int, array{type: string, ports: int, lid: int, desc: string, devid: int, vendid: int, sysimg: int, port0: int}> node GUID => node */
	public array $nodes = [];

	/** @var array<string, array{0: int, 1: int}> "guid:port" => remote [guid, port] */
	public array $links = [];

	/** @var array<string, array{width: string, speed: string, logical: string, phys: string, couldWidth: string, couldSpeed: string}> "guid:port" => link state */
	public array $state = [];

	/** @var array<int, array<int, int>> adapter GUID => port => port GUID */
	public array $portGuids = [];

	/** @var array<int, array<int, int>> adapter GUID => port => LID */
	public array $portLids = [];

	public static function forCas(int $cas, int $perSwitch = 32): self {
		$fabric   = new self();
		$switches = max(1, (int) ceil($cas / $perSwitch));
		$lid      = 1;

		for ($s = 0; $s < $switches; $s++) {
			$guid                  = self::SWITCH_BASE + $s;
			$fabric->nodes[$guid]  = ['type' => 'switch', 'ports' => 36, 'lid' => $lid++, 'desc' => sprintf('leaf%02d mlx5_0', $s), 'devid' => 0xd2f0, 'vendid' => 0x2c9, 'sysimg' => $guid, 'port0' => $guid];

			if ($s > 0) {
				$fabric->link($guid - 1, 36, $guid, 35);
			}
		}

		for ($c = 0; $c < $cas; $c++) {
			$guid                         = self::CA_BASE + ($c << 4);
			$fabric->nodes[$guid]         = ['type' => 'ca', 'ports' => 2, 'lid' => 0, 'desc' => sprintf('node%04d HCA-1', $c), 'devid' => 0x101b, 'vendid' => 0x2c9, 'sysimg' => $guid, 'port0' => 0];
			$fabric->portGuids[$guid]     = [1 => $guid + 1, 2 => $guid + 2];
			$fabric->portLids[$guid]      = [1 => $lid++, 2 => 0];
			$fabric->link($guid, 1, self::SWITCH_BASE + intdiv($c, $perSwitch), ($c % $perSwitch) + 1);
			$fabric->state[$guid . ':2'] = ['width' => '', 'speed' => '', 'logical' => 'Down', 'phys' => 'Polling', 'couldWidth' => '', 'couldSpeed' => ''];
		}

		return $fabric;
	}

	/**
	 * Connected port ends, counted on both sides, plus the down adapter
	 * ports: what a sweep reads.
	 */
	public function portCount(): int {
		return count($this->links) + count($this->portGuids);
	}

	public function link(int $a, int $pa, int $b, int $pb): void {
		$this->links[$a . ':' . $pa] = [$b, $pb];
		$this->links[$b . ':' . $pb] = [$a, $pa];

		foreach ([$a . ':' . $pa, $b . ':' . $pb] as $key) {
			$this->state[$key] = ['width' => '4X', 'speed' => 'EDR', 'logical' => 'Active', 'phys' => 'LinkUp', 'couldWidth' => '', 'couldSpeed' => ''];
		}
	}

	/**
	 * Make a link run below what both ends support, as iblinkinfo reports it.
	 */
	public function degrade(int $guid, int $port, string $width, string $speed, string $couldWidth, string $couldSpeed): void {
		[$rg, $rp] = $this->links[$guid . ':' . $port];

		foreach ([$guid . ':' . $port, $rg . ':' . $rp] as $key) {
			$this->state[$key] = ['width' => $width, 'speed' => $speed, 'logical' => 'Active', 'phys' => 'LinkUp', 'couldWidth' => $couldWidth, 'couldSpeed' => $couldSpeed];
		}
	}

	public function ibnetdiscover(): string {
		$out  = "#\n# Topology file: generated on Sat Nov 14 12:00:00 2026\n#\n";
		$out .= sprintf("# Initiated from node %016x port %016x\n", self::CA_BASE, self::CA_BASE + 1);

		foreach ($this->nodes as $guid => $node) {
			$out .= sprintf("\nvendid=0x%x\ndevid=0x%x\n", $node['vendid'], $node['devid']);

			if ($node['sysimg'] !== 0) {
				$out .= sprintf("sysimgguid=0x%x\n", $node['sysimg']);
			}

			if ($node['type'] === 'switch') {
				$out .= sprintf("switchguid=0x%x(%x)\n", $guid, $node['port0']);
				$out .= sprintf("Switch\t%d %s\t\t# \"%s\" enhanced port 0 lid %d lmc 0\n", $node['ports'], $this->name($guid), $node['desc'], $node['lid']);
			} else {
				$out .= sprintf("caguid=0x%x\n", $guid);
				$out .= sprintf("Ca\t%d %s\t\t# \"%s\"\n", $node['ports'], $this->name($guid), $node['desc']);
			}

			for ($p = 1; $p <= $node['ports']; $p++) {
				$remote = $this->links[$guid . ':' . $p] ?? null;

				if ($remote === null) {
					continue;
				}

				[$rg, $rp] = $remote;
				$link      = $this->state[$guid . ':' . $p];
				$compat    = strtolower($link['width']) . $link['speed'];
				$rnode     = $this->nodes[$rg];
				$rlid      = $rnode['type'] === 'switch' ? $rnode['lid'] : $this->portLids[$rg][$rp];

				if ($node['type'] === 'switch') {
					$out .= sprintf("[%d]\t%s[%d]", $p, $this->name($rg), $rp);
					$out .= $rnode['type'] !== 'switch' ? sprintf('(%x) ', $this->portGuids[$rg][$rp]) : '';
					$out .= sprintf("\t\t# \"%s\" lid %d %s\n", $rnode['desc'], $rlid, $compat);
				} else {
					$out .= sprintf("[%d](%x) \t%s[%d]", $p, $this->portGuids[$guid][$p], $this->name($rg), $rp);
					$out .= $rnode['type'] !== 'switch' ? sprintf(' (%x) ', $this->portGuids[$rg][$rp]) : '';
					$out .= sprintf("\t\t# lid %d lmc 0 \"%s\" lid %d %s\n", $this->portLids[$guid][$p], $rnode['desc'], $rlid, $compat);
				}
			}
		}

		return $out;
	}

	public function iblinkinfo(): string {
		$out = '';

		foreach ($this->nodes as $guid => $node) {
			for ($p = 1; $p <= $node['ports']; $p++) {
				$key  = $guid . ':' . $p;
				$link = $this->state[$key] ?? ['width' => '', 'speed' => '', 'logical' => 'Down', 'phys' => 'Polling', 'couldWidth' => '', 'couldSpeed' => ''];
				$own  = $node['type'] === 'switch' ? $node['port0'] : $this->portGuids[$guid][$p];
				$lid  = $node['type'] === 'switch' ? $node['lid'] : $this->portLids[$guid][$p];

				$linkStr = $link['logical'] === 'Down'
					? sprintf('(              %6s/%8s)', $link['logical'], $link['phys'])
					: sprintf('(%3s %18s %6s/%8s)', $link['width'], self::rate($link['speed']), $link['logical'], $link['phys']);

				$remote = $this->links[$key] ?? null;

				if ($remote !== null) {
					[$rg, $rp] = $remote;
					$rnode     = $this->nodes[$rg];
					$rguid     = $rnode['type'] === 'switch' ? $rnode['port0'] : $this->portGuids[$rg][$rp];
					$rlid      = $rnode['type'] === 'switch' ? $rnode['lid'] : $this->portLids[$rg][$rp];
					$width     = $link['couldWidth'] !== '' ? 'Could be ' . $link['couldWidth'] : '';
					$speed     = $link['couldSpeed'] !== '' ? 'Could be ' . $link['couldSpeed'] : '';
					$remoteStr = sprintf('0x%016x %6d %4d[%2s] "%s" (%s %s)', $rguid, $rlid, $rp, '', $rnode['desc'], $width, $speed);
				} elseif ($link['logical'] === 'Down') {
					$remoteStr = '           [  ] "" ( )';
				} else {
					$remoteStr = '    "Port not available"';
				}

				$out .= sprintf("0x%016x \"%30s\" %6d %4d[%2s] ==%s==>  %s\n", $own, $node['desc'], $lid, $p, '', $linkStr, $remoteStr);
			}
		}

		return $out;
	}

	/**
	 * perfquery output for ports of the node at $lid, stopping before
	 * $failAt as perfquery does on the first port that does not answer.
	 *
	 * @param list<int> $ports
	 */
	public function perfquery(int $lid, array $ports, bool $extended, ?int $failAt = null, int $capMask = 0x1600, int $capMask2 = 0x2): string {
		$out = '';

		foreach ($ports as $port) {
			if ($port === $failAt) {
				break;
			}

			$base = $lid * 1000 + $port;

			if (!$extended) {
				$fields = ['PortSelect' => (string) $port, 'CounterSelect' => '0x0000'];

				foreach (array_keys(\Somethingwithproof\Fabric\PerfqueryParser::BASIC) as $name) {
					$fields[$name] ??= $name === 'CounterSelect2' ? '0x00' : (string) ($name === 'PortXmitData' ? 4294967295 : ($base % 7));
				}

				$out .= sprintf("# Port counters: Lid %d port %d (CapMask: 0x%02X)\n", $lid, $port, $capMask);
			} else {
				$fields = ['PortSelect' => (string) $port, 'CounterSelect' => '0x0000', 'PortXmitData' => '18446744073709551615', 'PortRcvData' => (string) ($base * 1000003), 'PortXmitPkts' => (string) $base, 'PortRcvPkts' => (string) $base];

				if (($capMask & 0x200) !== 0) {
					foreach (array_keys(\Somethingwithproof\Fabric\PerfqueryParser::EXTENDED_CAST) as $name) {
						$fields[$name] = (string) $base;
					}
				}

				if (($capMask2 & 0x2) !== 0) {
					foreach (array_keys(\Somethingwithproof\Fabric\PerfqueryParser::EXTENDED_ERRORS) as $name) {
						$fields[$name] = $name === 'CounterSelect2' ? '0x00000000' : '0';
					}
				}

				$out .= sprintf("# Port extended counters: Lid %d port %d (CapMask: 0x%02X CapMask2: 0x%07X)\n", $lid, $port, $capMask, $capMask2);
			}

			foreach ($fields as $name => $value) {
				$out .= $name . ':' . str_repeat('.', 32 - strlen($name)) . $value . "\n";
			}
		}

		return $out;
	}

	/**
	 * The LID perfquery is pointed at for a node's port.
	 */
	public function lidOf(int $guid, int $port): int {
		return $this->nodes[$guid]['type'] === 'switch' ? $this->nodes[$guid]['lid'] : $this->portLids[$guid][$port];
	}

	/**
	 * Node GUID and port numbers queried at a LID.
	 *
	 * @return array{0: int, 1: string}|null
	 */
	public function nodeAtLid(int $lid): ?array {
		foreach ($this->nodes as $guid => $node) {
			if ($node['type'] === 'switch' && $node['lid'] === $lid) {
				return [$guid, 'switch'];
			}

			foreach ($this->portLids[$guid] ?? [] as $portLid) {
				if ($portLid === $lid) {
					return [$guid, 'ca'];
				}
			}
		}

		return null;
	}

	private function name(int $guid): string {
		return sprintf('"%s-%016x"', $this->nodes[$guid]['type'] === 'switch' ? 'S' : 'H', $guid);
	}

	private static function rate(string $speed): string {
		return match ($speed) {
			'SDR'   => '2.5 Gbps',
			'DDR'   => '5.0 Gbps',
			'QDR'   => '10.0 Gbps',
			'FDR10' => '10.0 Gbps (FDR10)',
			'FDR'   => '14.0625 Gbps',
			'EDR'   => '25.78125 Gbps',
			'HDR'   => '53.125 Gbps',
			'NDR'   => '106.25 Gbps',
			default => 'Extended speed',
		};
	}
}
