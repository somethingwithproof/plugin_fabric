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
 */
final class InventoryPage {
	public const FILE = 'fabric.php';

	/**
	 * @param \Closure(int): bool $deviceAllowed host id => whether the user may see it
	 */
	public function __construct(
		private readonly FabricRepository $fabrics,
		private readonly NodeRepository $nodes,
		private readonly SampleRepository $samples,
		private readonly Clock $clock,
		private readonly \Closure $deviceAllowed,
		private readonly bool $admin,
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
		}
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

		foreach ($samples as $row) {
			if (Row::string($row, 'counter_group') === CounterGroup::Basic->value) {
				$observed[Row::int($row, 'port_number')] = Row::int($row, 'observed_at');
			}
		}

		$now       = $this->clock->now();
		$evaluator = new LinkStateEvaluator();
		$freshness = new FreshnessEvaluator($this->clock);
		$interval  = $fabric->interval ?? Fabric::DEFAULT_INTERVAL;

		html_start_box(html_escape(__('Fabric Node: %s', $node->label(), 'fabric')), '100%', '', '3', 'center', '');
		print Format::header([__('Port', 'fabric'), __('Link', 'fabric'), __('State', 'fabric'), __('Active', 'fabric'), __('Best Both Ends Support', 'fabric'), __('Remote', 'fabric'), __('Counters', 'fabric')]);

		if ($ports === []) {
			print '<tr><td colspan="7"><em>' . html_escape(__('No ports recorded yet.', 'fabric')) . '</em></td></tr>';
		}

		foreach ($ports as $port) {
			$link  = $port->link;
			$state = $link->logical === null ? '-' : $link->logical->value . '/' . ($link->physical->value ?? '-');
			$seen  = $observed[$port->number] ?? 0;

			print Format::row([
				(string) $port->number,
				$port->presence === Presence::Present ? $evaluator->evaluate($link)->label() : __('Missing', 'fabric'),
				$state,
				self::widthSpeed($link->activeWidth, $link->activeSpeed),
				self::widthSpeed($link->bestWidth, $link->bestSpeed),
				$port->remote?->key() ?? '-',
				$seen > 0 ? $freshness->evaluate($seen, $interval)->value . ', ' . Format::age($seen, $now) : __('Never', 'fabric'),
			]);
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

	private static function widthSpeed(?LinkWidth $width, ?LinkSpeed $speed): string {
		return $width === null && $speed === null ? '-' : ($width?->label() ?? '?') . ' ' . ($speed?->label() ?? '?');
	}

	private function failure(): void {
		html_start_box(__('Fabrics', 'fabric'), '100%', '', '3', 'center', '');
		print '<tr><td class="textError">' . html_escape(__('The fabric inventory could not be read. See the Cacti log.', 'fabric')) . '</td></tr>';
		html_end_box();
	}
}
