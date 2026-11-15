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
 * One stored sample row, typed: a port's latest values of one counter
 * group with their rates and discontinuities (SampleRepository).
 */
final readonly class Sample {
	/**
	 * @param array<string, ?string> $counters
	 * @param array<string, ?float>  $rates
	 * @param list<string>           $reset
	 */
	public function __construct(
		public int $port,
		public CounterGroup $group,
		public int $observedAt,
		public int $attemptedAt,
		public int $capMask,
		public array $counters,
		public array $rates,
		public array $reset,
		public int $discontinuities,
		public int $discontinuityAt,
		public string $error,
	) {
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$group = CounterGroup::tryFrom(Row::string($row, 'counter_group'));

		if ($group === null) {
			return null;
		}

		return new self(
			port: Row::int($row, 'port_number', 0, 0),
			group: $group,
			observedAt: Row::int($row, 'observed_at', 0, 0),
			attemptedAt: Row::int($row, 'attempted_at', 0, 0),
			capMask: Row::int($row, 'cap_mask', 0, 0),
			// A damaged column reads as no values, so every value is unknown.
			counters: Counters::valuesFromJson(Row::string($row, 'counters')) ?? [],
			rates: CounterDelta::ratesFromJson(Row::string($row, 'rates')),
			reset: CounterDelta::resetFromJson(Row::string($row, 'reset')),
			discontinuities: Row::int($row, 'discontinuities', 0, 0),
			discontinuityAt: Row::int($row, 'discontinuity_at', 0, 0),
			error: Row::string($row, 'error'),
		);
	}

	public function has(string $counter): bool {
		return $this->observedAt > 0 && array_key_exists($counter, $this->counters);
	}

	/**
	 * Whether the counter holds its cap: a basic counter stops there and
	 * no longer counts (REQ-CTR-005).
	 */
	public function saturated(string $counter): bool {
		$value = $this->counters[$counter] ?? null;
		$cap   = PerfqueryParser::cap(PerfqueryParser::width($this->group, $counter));

		return $value !== null && $cap !== '' && $value === $cap;
	}

	/**
	 * Counters of this sample that hold their cap.
	 *
	 * @return list<string>
	 */
	public function saturatedCounters(): array {
		return array_values(array_filter(array_keys($this->counters), fn (string $name): bool => $this->saturated($name)));
	}

	public function extendedSupported(): bool {
		return ($this->capMask & (Counters::CAP_EXT_WIDTH | Counters::CAP_EXT_WIDTH_NOIETF)) !== 0;
	}
}
