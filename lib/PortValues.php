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
 * The values the Fabric Port data query returns per port, and the data
 * template each belongs to (ADR 0011).
 *
 * Every value is drawn by the graph template of the same name as its data
 * template, because Cacti puts in an RRD file only what a graph draws.
 * Names are at most 19 characters, Cacti's limit for a data source name.
 *
 * - Counter values are the cumulative counters as received, stored as
 *   DERIVE with minimum 0, for graphs only: RRDtool turns them into rates.
 * - Rate values are rates the plugin computed from two consecutive samples
 *   of one counter, GAUGE, so Thold can threshold them: Thold 1.8.2 fails
 *   with a type error on an unknown COUNTER or DERIVE reading and then
 *   disables itself.
 * - State values are the state data source of the plan, GAUGE.
 */
final class PortValues {
	public const TRAFFIC    = 'Fabric Port Traffic';
	public const ERRORS     = 'Fabric Port Errors';
	public const CONGESTION = 'Fabric Port Congestion';
	public const STATE      = 'Fabric Port State';
	public const RATES      = 'Fabric Port Alert Rates';

	/** Data source name => perfquery counter, for the counter values. */
	public const COUNTERS = [
		'xmit_data'        => 'PortXmitData',
		'rcv_data'         => 'PortRcvData',
		'xmit_pkts'        => 'PortXmitPkts',
		'rcv_pkts'         => 'PortRcvPkts',
		'symbol_err'       => 'SymbolErrorCounter',
		'link_err_recov'   => 'LinkErrorRecoveryCounter',
		'link_downed'      => 'LinkDownedCounter',
		'rcv_err'          => 'PortRcvErrors',
		'rcv_remote_phys'  => 'PortRcvRemotePhysicalErrors',
		'rcv_switch_relay' => 'PortRcvSwitchRelayErrors',
		'local_link_integ' => 'LocalLinkIntegrityErrors',
		'buffer_overrun'   => 'ExcessiveBufferOverrunErrors',
		'vl15_dropped'     => 'VL15Dropped',
		'xmit_wait'        => 'PortXmitWait',
		'xmit_discards'    => 'PortXmitDiscards',
		'xmit_constraint'  => 'PortXmitConstraintErrors',
		'rcv_constraint'   => 'PortRcvConstraintErrors',
	];

	/** Data source name => perfquery counter whose rate it is. */
	public const RATE_OF = [
		'symbol_err_rate'   => 'SymbolErrorCounter',
		'rcv_err_rate'      => 'PortRcvErrors',
		'link_downed_rate'  => 'LinkDownedCounter',
		'xmit_discard_rate' => 'PortXmitDiscards',
		'xmit_wait_rate'    => 'PortXmitWait',
	];

	/** The state values, in the plan's order. */
	public const STATE_VALUES = ['active_lanes', 'speed_rank', 'degraded', 'port_state', 'age'];

	/** Data counters count octets divided by four (REQ-CTR-004). */
	public const WORDS = ['PortXmitData', 'PortRcvData'];

	/**
	 * Data template name => its data source names.
	 *
	 * @return array<string, list<string>>
	 */
	public static function templates(): array {
		return [
			self::TRAFFIC    => ['xmit_data', 'rcv_data', 'xmit_pkts', 'rcv_pkts'],
			self::ERRORS     => ['symbol_err', 'link_err_recov', 'link_downed', 'rcv_err', 'rcv_remote_phys', 'rcv_switch_relay', 'local_link_integ', 'buffer_overrun', 'vl15_dropped'],
			self::CONGESTION => ['xmit_wait', 'xmit_discards', 'xmit_constraint', 'rcv_constraint'],
			self::STATE      => self::STATE_VALUES,
			self::RATES      => array_keys(self::RATE_OF),
		];
	}

	/**
	 * Every output field of the data query.
	 *
	 * @return list<string>
	 */
	public static function names(): array {
		return array_merge(...array_values(self::templates()));
	}

	public static function isCounter(string $name): bool {
		return isset(self::COUNTERS[$name]);
	}
}
