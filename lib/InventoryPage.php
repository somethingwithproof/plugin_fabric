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
 * The inventory page (Fabric View). It shows what the last sweeps stored
 * and never starts a sweep or runs a tool (REQ-SWEEP-005).
 *
 * A node mapped to a Cacti device is shown only to a user whose device
 * permissions allow that device; an unmapped node only to a Fabric Admin
 * user (REQ-UI-005). The caller supplies both checks, so this class stays
 * free of Cacti's session.
 *
 * Every stored string, including fabric-supplied descriptions, is escaped
 * where it is printed.
 *
 * Graph links appear only for graphs Cacti's own graph permissions allow
 * (REQ-PERM-001); the page has no permission layer of its own for them.
 */
final class InventoryPage {
	public const FILE = 'fabric.php';

	/** Rows on the congestion ranking. */
	public const RANKED = 50;

	/**
	 * @param \Closure(int): bool      $deviceAllowed host id => whether the user may see it
	 * @param (\Closure(int): bool)|null $graphAllowed local graph id => whether the user may see it
	 */
	public function __construct(
		private readonly FabricRepository $fabrics,
		private readonly NodeRepository $nodes,
		private readonly SampleRepository $samples,
		private readonly Clock $clock,
		private readonly \Closure $deviceAllowed,
		private readonly bool $admin,
		private readonly ?PortReadingRepository $readings = null,
		private readonly ?CactiObjectRepository $objects = null,
		private readonly ?\Closure $graphAllowed = null,
	) {
	}

	public function visible(Node $node): bool {
		return $node->hostId === 0 ? $this->admin : ($this->deviceAllowed)($node->hostId);
	}

	public function renderList(): void {
		$fabrics = $this->fabrics->all();
		$nodes   = $this->nodes->all();

		if ($fabrics === null || $nodes === null) {
			$this->failure();

			return;
		}

		$now = $this->clock->now();

		foreach ($fabrics as $fabric) {
			$shown = array_values(array_filter($nodes, fn (Node $n): bool => $n->fabricId === $fabric->id && $this->visible($n)));

			html_start_box(html_escape(__('Fabric: %s', $fabric->name, 'fabric')), '100%', '', '3', 'center', '');
			print Format::header([__('Node', 'fabric'), __('GUID', 'fabric'), __('Type', 'fabric'), __('LID', 'fabric'), __('Device', 'fabric'), __('Presence', 'fabric'), __('Identity', 'fabric'), __('Last Seen', 'fabric')]);

			if ($shown === []) {
				print '<tr><td colspan="8"><em>' . html_escape(__('No nodes to show.', 'fabric')) . '</em></td></tr>';
			}

			foreach ($shown as $node) {
				print '<tr class="tableRow">'
					. '<td><a class="linkEditMain" href="' . html_escape(self::FILE . '?node=' . $node->id) . '">' . html_escape($node->label()) . '</a></td>'
					. '<td>' . html_escape($node->guid->text) . '</td>'
					. '<td>' . html_escape($node->type->label()) . '</td>'
					. '<td>' . ($node->lid > 0 ? $node->lid : '-') . '</td>'
					. '<td>' . $this->device($node) . '</td>'
					. '<td>' . html_escape($node->presence->value) . '</td>'
					. '<td>' . html_escape($node->identity->value) . '</td>'
					. '<td>' . html_escape(Format::age($node->lastSeen, $now)) . '</td>'
					. '</tr>';
			}

			html_end_box();
		}

		if ($fabrics === []) {
			html_start_box(__('Fabrics', 'fabric'), '100%', '', '3', 'center', '');
			print '<tr><td><em>' . html_escape(__('No fabrics are defined yet.', 'fabric')) . '</em></td></tr>';
			html_end_box();
		} else {
			print '<p><a class="linkEditMain" href="' . html_escape(self::FILE . '?view=congestion') . '">' . html_escape(__('Ports with the highest transmit wait', 'fabric')) . '</a></p>';
		}
	}

	/**
	 * The ports with the highest transmit-wait rate over their last two
	 * samples, among nodes the user may see (REQ-CONG-003). A rate needs a
	 * current sample; ports without one are left out.
	 */
	public function renderCongestion(): void {
		$nodes    = $this->nodes->all();
		$readings = $nodes === null || $this->readings === null ? null : $this->readings->forNodes(array_values(array_filter($nodes, fn (Node $n): bool => $this->visible($n))));

		html_start_box(__('Ports with the Highest Transmit Wait', 'fabric'), '100%', '', '3', 'center', '');

		if ($readings === null) {
			print '<tr><td class="textError">' . html_escape(__('The fabric inventory could not be read. See the Cacti log.', 'fabric')) . '</td></tr>';
			html_end_box();

			return;
		}

		print '<tr><td colspan="5"><em>' . html_escape(__('Transmit wait counts ticks in which a port had data to send but could not send it, for lack of credits or arbitration. A high value shows congestion toward the remote end; it does not show that the port or its cable is faulty. The tick length is not published for current link speeds, so values are ticks per second, not time.', 'fabric')) . '</em></td></tr>';
		print Format::header([__('Node', 'fabric'), __('Port', 'fabric'), __('Transmit Wait (ticks per second)', 'fabric'), __('Transmit Discards (per second)', 'fabric'), __('Remote', 'fabric')]);

		$ranked = [];

		foreach ($readings as $reading) {
			$rate = $reading->lastRate('PortXmitWait');

			if ($rate !== null && $reading->port->number > 0) {
				$ranked[] = [$rate, $reading];
			}
		}

		// Highest first; ties in index order, so the list is stable.
		usort($ranked, static fn (array $a, array $b): int => [$b[0], $a[1]->ref()->key()] <=> [$a[0], $b[1]->ref()->key()]);

		if ($ranked === []) {
			print '<tr><td colspan="5"><em>' . html_escape(__('No port has a current transmit wait rate.', 'fabric')) . '</em></td></tr>';
		}

		foreach (array_slice($ranked, 0, self::RANKED) as [$rate, $reading]) {
			$discards = $reading->lastRate('PortXmitDiscards');

			print Format::row([
				$reading->node->label(),
				(string) $reading->port->number,
				self::rate($rate),
				$discards === null ? __('Unknown', 'fabric') : self::rate($discards),
				$reading->port->remote?->key() ?? '-',
			]);
		}

		html_end_box();
	}

	private static function rate(float $value): string {
		return number_format($value, $value >= 100 ? 0 : 2);
	}

	public function renderNode(int $id): void {
		$node = $this->nodes->find($id);

		if ($node === null || !$this->visible($node)) {
			html_start_box(__('Fabric Node', 'fabric'), '100%', '', '3', 'center', '');
			print '<tr><td>' . html_escape(__('The node does not exist or you may not see it.', 'fabric')) . '</td></tr>';
			html_end_box();

			return;
		}

		$fabric  = $this->fabrics->find($node->fabricId);
		$ports   = $this->nodes->ports($node->id);
		$samples = $this->samples->forNode($node->id);

		if ($ports === null || $samples === null) {
			$this->failure();

			return;
		}

		$observed = [];
		$validity = [];

		foreach ($samples as $row) {
			if (Row::string($row, 'counter_group') === CounterGroup::Basic->value) {
				$observed[Row::int($row, 'port_number')] = Row::int($row, 'observed_at');
			}

			$sample = Sample::fromRow($row);

			if ($sample !== null) {
				$validity[$sample->port][] = $sample;
			}
		}

		$graphs = $node->hostId > 0 && $this->objects !== null ? $this->objects->portGraphs($node->hostId) : [];

		$now       = $this->clock->now();
		$evaluator = new LinkStateEvaluator();
		$freshness = new FreshnessEvaluator($this->clock);
		$interval  = $fabric->interval ?? Fabric::DEFAULT_INTERVAL;

		html_start_box(html_escape(__('Fabric Node: %s', $node->label(), 'fabric')), '100%', '', '3', 'center', '');
		print Format::header([__('Port', 'fabric'), __('Link', 'fabric'), __('State', 'fabric'), __('Active', 'fabric'), __('Best Both Ends Support', 'fabric'), __('Remote', 'fabric'), __('Counters', 'fabric'), __('Counter Validity', 'fabric'), __('Graphs', 'fabric')]);

		if ($ports === []) {
			print '<tr><td colspan="9"><em>' . html_escape(__('No ports recorded yet.', 'fabric')) . '</em></td></tr>';
		}

		foreach ($ports as $port) {
			$link  = $port->link;
			$state = $link->logical === null ? '-' : $link->logical->value . '/' . ($link->physical->value ?? '-');
			$seen  = $observed[$port->number] ?? 0;

			$cells = Format::row([
				(string) $port->number,
				$port->presence === Presence::Present ? $evaluator->evaluate($link)->label() : __('Missing', 'fabric'),
				$state,
				self::widthSpeed($link->activeWidth, $link->activeSpeed),
				self::widthSpeed($link->bestWidth, $link->bestSpeed),
				$port->remote?->key() ?? '-',
				$seen > 0 ? $freshness->evaluate($seen, $interval)->value . ', ' . Format::age($seen, $now) : __('Never', 'fabric'),
				self::validity($validity[$port->number] ?? [], $now),
			]);

			print substr($cells, 0, -5) . '<td>' . $this->graphLinks($graphs[(new PortRef($node->guid, $port->number))->key()] ?? []) . '</td></tr>';
		}

		html_end_box();
	}

	/**
	 * The device cell; for a Fabric Admin user, a link to change the mapping.
	 */
	private function device(Node $node): string {
		$text = html_escape($node->hostId > 0 ? __('Device %d', $node->hostId, 'fabric') : __('Unmapped', 'fabric'));

		return $this->admin ? '<a href="' . html_escape(AdminPage::FILE . '?action=edit_node&id=' . $node->id) . '">' . $text . '</a>' : $text;
	}

	/**
	 * Saturated counters and discontinuities of a port's samples
	 * (REQ-CTR-005, REQ-DISC-003).
	 *
	 * @param list<Sample> $samples
	 */
	private static function validity(array $samples, int $now): string {
		$parts = [];

		foreach ($samples as $sample) {
			$saturated = $sample->saturatedCounters();

			if ($saturated !== []) {
				$parts[] = __('%s counters saturated, published as unknown: %s', $sample->group->value, implode(', ', $saturated), 'fabric');
			}

			if ($sample->discontinuities > 0) {
				$parts[] = __('%s counters: %d discontinuities, the last %s', $sample->group->value, $sample->discontinuities, Format::age($sample->discontinuityAt, $now), 'fabric');
			}
		}

		return $parts === [] ? __('Valid', 'fabric') : implode('; ', $parts);
	}

	/**
	 * @param list<array{id: int, template: string}> $graphs
	 */
	private function graphLinks(array $graphs): string {
		$links = [];

		foreach ($graphs as $graph) {
			if ($this->graphAllowed !== null && ($this->graphAllowed)($graph['id'])) {
				$links[] = '<a href="' . html_escape('../../graph.php?local_graph_id=' . $graph['id'] . '&rra_id=all') . '">' . html_escape(str_replace('Fabric Port ', '', $graph['template'])) . '</a>';
			}
		}

		return $links === [] ? '-' : implode(', ', $links);
	}

	private static function widthSpeed(?LinkWidth $width, ?LinkSpeed $speed): string {
		return $width === null && $speed === null ? '-' : ($width?->label() ?? '?') . ' ' . ($speed?->label() ?? '?');
	}

	private function failure(): void {
		html_start_box(__('Fabrics', 'fabric'), '100%', '', '3', 'center', '');
		print '<tr><td class="textError">' . html_escape(__('The fabric inventory could not be read. See the Cacti log.', 'fabric')) . '</td></tr>';
		html_end_box();
	}
}
