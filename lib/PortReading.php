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
 * The values one port publishes through the Fabric Port data query, from
 * the cache only (REQ-IDX-002, REQ-ALERT-001 to REQ-ALERT-003).
 *
 * Every value that cannot be stated is null, which the query prints as U,
 * never 0 (REQ-CTR-006, REQ-ALERT-002):
 *
 * - A counter or rate needs a current sample (observed within one and a
 *   half intervals): a repeated old value would read as an idle port. It is
 *   unknown when the port does not support the counter, when the counter
 *   holds its cap, and in the sample at which it went down.
 * - Each counter comes from one group, chosen by what the port supports,
 *   not by which read succeeded last, so a failed read never switches a
 *   data source between widths (REQ-CTR-001): the extended group when the
 *   port's extended sample has the counter, or, for the data and packet
 *   counters, when the port says it supports extended counters at all.
 * - The state values need a link state that is not stale; degraded is
 *   unknown for a link below best with no enabled values to explain it.
 * - age is the seconds since the port's last basic sample and is numeric
 *   whenever the port was ever sampled.
 */
final readonly class PortReading {
	/** Wide constant for PortReading. */
	private const WIDE = ['PortXmitData', 'PortRcvData', 'PortXmitPkts', 'PortRcvPkts'];

	/**
	 * Construct a new PortReading instance.
	 *
	 * @param Node $node
	 * @param Port $port
	 * @param int $interval
	 * @param ?Sample $basic
	 * @param ?Sample $extended
	 * @param Clock $clock
	 */
	public function __construct(
		public Node $node,
		public Port $port,
		public int $interval,
		public ?Sample $basic,
		public ?Sample $extended,
		private Clock $clock,
	) {
	}

	/**
	 * Ref.
	 *
	 * @return PortRef
	 */
	public function ref(): PortRef {
		return new PortRef($this->node->guid, $this->port->number);
	}

	/**
	 * Listed as a data query index: every port but port 0, which is a
	 * switch's management port; a port that is down with no remote end
	 * only when the setting asks for it (REQ-IDX-003).
	 */
	public function indexed(bool $downPorts): bool {
		if ($this->port->number < 1) {
			return false;
		}

		$link = $this->port->link;
		$down = $this->port->presence !== Presence::Present || $link->logical === null || $link->logical === LogicalState::Down;

		return $downPorts || $this->port->remote !== null || !$down;
	}

	/**
	 * Value.
	 *
	 * @param string $name
	 * @return ?string
	 */
	public function value(string $name): ?string {
		if (PortValues::isCounter($name)) {
			return $this->counter(PortValues::COUNTERS[$name]);
		}

		if (isset(PortValues::RATE_OF[$name])) {
			return $this->rate(PortValues::RATE_OF[$name]);
		}

		return match ($name) {
			'active_lanes' => $this->state(fn (LinkState $l): ?int => $l->activeWidth?->lanes()),
			'speed_rank'   => $this->state(fn (LinkState $l): ?int => $l->activeSpeed?->rank()),
			'degraded'     => $this->state(fn (LinkState $l): ?int => (new LinkStateEvaluator())->evaluate($l)->degradedValue()),
			'port_state'   => $this->state(fn (LinkState $l): ?int => $l->logical?->number()),
			'age'          => $this->age(),
			default        => null,
		};
	}

	/**
	 * The sample a counter is read from, or null when the port has none.
	 */
	public function source(string $counter): ?Sample {
		if ($this->extended !== null && $this->extended->has($counter)) {
			return $this->extended;
		}

		if (in_array($counter, self::WIDE, true) && $this->basic !== null && $this->basic->extendedSupported()) {
			return $this->extended;
		}

		return $this->basic;
	}

	/**
	 * The counter's per-second rate as last computed, or null; for pages.
	 */
	public function lastRate(string $counter): ?float {
		$sample = $this->source($counter);

		return $sample === null || !$this->current($sample) ? null : $sample->rates[$counter] ?? null;
	}

	/**
	 * Counter.
	 *
	 * @param string $counter
	 * @return ?string
	 */
	private function counter(string $counter): ?string {
		$sample = $this->source($counter);

		if ($sample === null || !$this->current($sample) || in_array($counter, $sample->reset, true) || $sample->saturated($counter)) {
			return null;
		}

		return $sample->counters[$counter] ?? null;
	}

	/**
	 * Rate.
	 *
	 * @param string $counter
	 * @return ?string
	 */
	private function rate(string $counter): ?string {
		$rate = $this->lastRate($counter);

		return $rate === null ? null : self::number($rate);
	}

	/**
	 * @param \Closure(LinkState): ?int $read
	 */
	private function state(\Closure $read): ?string {
		$freshness = (new FreshnessEvaluator($this->clock))->evaluate($this->port->linkObservedAt, $this->interval);

		if (!$freshness->usable()) {
			return null;
		}

		$value = $read($this->port->link);

		return $value === null ? null : (string) $value;
	}

	/**
	 * Age.
	 *
	 * @return ?string
	 */
	private function age(): ?string {
		$at = $this->basic->observedAt ?? 0;

		return $at > 0 ? (string) max(0, $this->clock->now() - $at) : null;
	}

	/**
	 * Current.
	 *
	 * @param Sample $sample
	 * @return bool
	 */
	private function current(Sample $sample): bool {
		return (new FreshnessEvaluator($this->clock))->evaluate($sample->observedAt, $this->interval) === Freshness::Current;
	}

	/**
	 * Number.
	 *
	 * @param float $value
	 * @return string
	 */
	private static function number(float $value): string {
		$text = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

		return $text === '' || $text === '-0' ? '0' : $text;
	}
}
