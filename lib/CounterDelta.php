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
 * What changed between two samples of one port's counter group
 * (REQ-CTR-005, REQ-DISC-001, ADR 0011).
 *
 * - A counter lower than in the previous sample is a discontinuity: a
 *   reset by another tool, a reboot, or a retrained link. Its rate is
 *   unknown, and the data query publishes it as unknown until the next
 *   sample (REQ-DISC-002).
 * - A counter at its cap, now or before, is saturated: it no longer
 *   counts, so its rate is unknown, never 0 (REQ-CTR-005).
 * - A counter the port does not support has no rate.
 * - Anything else has a rate per second over the time between the two
 *   observations.
 */
final readonly class CounterDelta {
	/**
	 * @param array<string, ?float> $rates  counter => per second, or null when unknown
	 * @param list<string>          $reset  counters that went down
	 * @param int                   $since  observation time of the previous sample, 0 for none
	 */
	public function __construct(public array $rates, public array $reset, public int $since) {
	}

	/**
	 * @param array<string, ?string>|null $previous the previous sample's counters, null for none
	 */
	public static function between(?array $previous, int $previousAt, Counters $current, int $now): self {
		$usable = $previous !== null && $previousAt > 0 && $previousAt < $now;
		$rates  = [];
		$reset  = [];

		foreach ($current->values as $name => $value) {
			$before = $usable ? ($previous[$name] ?? null) : null;

			if ($value === null || $before === null || !Decimal::valid($value) || !Decimal::valid($before)) {
				$rates[$name] = null;

				continue;
			}

			if (Decimal::compare($value, $before) < 0) {
				$rates[$name] = null;
				$reset[]      = $name;

				continue;
			}

			$cap = PerfqueryParser::cap(PerfqueryParser::width($current->group, $name));

			$rates[$name] = $cap === '' || $value === $cap || $before === $cap ? null : Decimal::difference($value, $before) / ($now - $previousAt);
		}

		return new self($rates, $reset, $usable ? $previousAt : 0);
	}

	public function ratesJson(): string {
		return json_encode($this->rates, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
	}

	public function resetJson(): string {
		return json_encode($this->reset, JSON_THROW_ON_ERROR);
	}

	/**
	 * Stored rates back from the sample table: each a number or null.
	 *
	 * @return array<string, ?float>
	 */
	public static function ratesFromJson(string $json): array {
		$data = json_decode($json, true);
		$out  = [];

		foreach (is_array($data) ? $data : [] as $name => $rate) {
			if (is_string($name) && ($rate === null || is_int($rate) || is_float($rate)) && ($rate === null || $rate >= 0)) {
				$out[$name] = $rate === null ? null : (float) $rate;
			}
		}

		return $out;
	}

	/**
	 * @return list<string>
	 */
	public static function resetFromJson(string $json): array {
		$data = json_decode($json, true);

		return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
	}
}
