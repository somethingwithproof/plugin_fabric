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
 * The health page (REQ-HEALTH-001 to REQ-HEALTH-004), for Fabric Admin
 * only: it reveals poller privilege and the plugin's view of every node,
 * mapped or not. It shows what sweeps last recorded and never starts one
 * (REQ-SWEEP-005). Tool output is never printed; errors show as the
 * plugin's own messages, and every cell is escaped.
 *
 * Node rows, sweep outcomes, and conflict and proposal counts cover only
 * nodes on devices the user may see (DeviceAccess). The per-fabric
 * discovery figures stay fabric-wide: they are totals from the last
 * discovery and name no device.
 */
final class HealthPage {
	public const FILE = 'fabric_health.php';

	public function __construct(
		private readonly FabricRepository $fabrics,
		private readonly NodeRepository $nodes,
		private readonly FabricHealthRepository $health,
		private readonly NodeSweepRepository $sweeps,
		private readonly RelinkRepository $relinks,
		private readonly Clock $clock,
		private readonly DeviceAccess $access,
		private readonly ?TemplateInstaller $templates = null,
		private readonly bool $canImport = false,
	) {
	}

	/**
	 * Whether the shipped Cacti objects are in place (REQ-OBJ-003), and a
	 * reinstall for users who may import templates.
	 */
	private function renderObjects(): void {
		if ($this->templates === null) {
			return;
		}

		$problem = $this->templates->queryFileProblem();
		$failure = $this->templates->failure();

		html_start_box(__('Cacti Objects', 'fabric'), '100%', '', '3', 'center', '');
		print Format::row([__('Templates', 'fabric'), $this->templates->present() ? __('Installed', 'fabric') : __('Missing', 'fabric')]);
		print Format::row([__('Data Query File', 'fabric'), $problem ?? __('Installed', 'fabric')]);

		if ($failure !== '') {
			print Format::row([__('Last Install', 'fabric'), $failure]);
		}

		html_end_box();

		if ($this->canImport && !$this->templates->isCurrent()) {
			print '<form method="post" action="' . html_escape(self::FILE) . '">'
				. '<input type="hidden" name="action" value="reinstall_templates">'
				. '<input type="submit" class="ui-button" value="' . html_escape(__('Reinstall the Templates', 'fabric')) . '">'
				. '</form>';
		}
	}

	public function render(): void {
		$fabrics   = $this->fabrics->all();
		$nodes     = $this->nodes->all();
		$sweeps    = $this->sweeps->all();
		$proposals = $this->relinks->open();

		if ($fabrics === null || $nodes === null || $sweeps === null || $proposals === null) {
			html_start_box(__('Fabric Health', 'fabric'), '100%', '', '3', 'center', '');
			print '<tr><td class="textError">' . html_escape(__('The health records could not be read. See the Cacti log.', 'fabric')) . '</td></tr>';
			html_end_box();

			return;
		}

		// Whether a fabric has a mapped node must not depend on which devices
		// this user may see, so count before the permission filter.
		$mapped    = [];

		foreach ($nodes as $node) {
			if ($node->hostId > 0) {
				$mapped[$node->fabricId] = true;
			}
		}

		$nodes     = $this->access->visible($nodes);
		$proposals = array_values(array_filter($proposals, fn (array $r): bool => $this->access->host(Row::int($r, 'old_host_id')) && $this->access->host(Row::int($r, 'new_host_id'))));
		$now       = $this->clock->now();
		$freshness = new FreshnessEvaluator($this->clock);

		$this->renderObjects();

		html_start_box(__('Fabrics', 'fabric'), '100%', '', '3', 'center', '');
		print Format::header([__('Fabric', 'fabric'), __('Last Discovery', 'fabric'), __('Last Successful Discovery', 'fabric'), __('Discovery', 'fabric'), __('Nodes / Ports Seen', 'fabric'), __('Last Link Read', 'fabric'), __('Link Read', 'fabric'), __('Unmatched Link Records', 'fabric'), __('Skipped Link Lines', 'fabric'), __('Counter Sweeps', 'fabric')]);

		if ($fabrics === []) {
			print '<tr><td colspan="10"><em>' . html_escape(__('No fabrics defined yet.', 'fabric')) . '</em></td></tr>';
		}

		$warnings = [];

		// The command is shown with an absolute path so it also works from cron.
		global $config;
		$base = rtrim((string) ($config['base_path'] ?? '/path/to/cacti'), '/');

		foreach ($fabrics as $fabric) {
			$found = $this->health->find($fabric->id);
			$row   = $found ?? [];

			$fabricNodes = array_filter($nodes, static fn (Node $n): bool => $n->fabricId === $fabric->id);
			$outcomes    = [];

			foreach ($fabricNodes as $node) {
				$outcome            = Row::string($sweeps[$node->id] ?? [], 'outcome');
				$outcome            = $outcome !== '' ? $outcome : SweepOutcome::Never->value;
				$outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
			}

			ksort($outcomes);

			$success = Row::int($row, 'discovery_success');
			$state   = $fabric->enabled() ? $freshness->evaluate($success, $fabric->interval) : null;

			if ($state !== null && !$state->usable()) {
				$warnings[] = __('Fabric %s: the inventory is %s; the last successful discovery was %s.', $fabric->name, $state->value, Format::age($success, $now), 'fabric');
			}

			if (!$fabric->onMainPoller()) {
				$warnings[] = __('Fabric %s: it is assigned to poller %d, but only the main poller can sweep a fabric until remote poller support is added. It is not swept; assign it to the main poller.', $fabric->name, $fabric->pollerId, 'fabric');
			}

			// Sweeps start from a mapped node (ADR 0008), so a fabric with no
			// discovery or no mapped node waits for the command line.
			if ($found === null) {
				$warnings[] = __('Fabric %s: its health record could not be read, so its discovery state is unknown. See the Cacti log.', $fabric->name, 'fabric');
			} elseif ($fabric->enabled() && $fabric->onMainPoller()) {
				if (Row::int($row, 'discovery_attempt') === 0) {
					$warnings[] = __('Fabric %s: it has not been discovered. Sweeps start only from a node mapped to a Cacti device, so run "php %s/plugins/fabric/fabric_sweep.php --fabric=%d --force" on the main poller once.', $fabric->name, $base, $fabric->id, 'fabric');
				} elseif (!isset($mapped[$fabric->id])) {
					$warnings[] = __('Fabric %s: no node is mapped to a Cacti device, so no sweep starts and the inventory is not refreshed. Map a node on the Fabric Admin page, or run "php %s/plugins/fabric/fabric_sweep.php --fabric=%d --force" on the main poller.', $fabric->name, $base, $fabric->id, 'fabric');
				}
			}

			if (Row::int($row, 'links_skipped') > 0 && Row::string($row, 'links_error') === '') {
				$warnings[] = __('Fabric %s: %d iblinkinfo lines could not be read, most likely because a node description imitates the tool output. Those nodes keep their last link states.', $fabric->name, Row::int($row, 'links_skipped'), 'fabric');
			}

			foreach (array_filter(explode(',', Row::string($row, 'privilege'))) as $code) {
				$warnings[] = __('Fabric %s: %s', $fabric->name, PrivilegeProbe::message($code), 'fabric');
			}

			print Format::row([
				$fabric->name . ($fabric->enabled() ? '' : ' (' . __('disabled', 'fabric') . ')'),
				Format::age(Row::int($row, 'discovery_attempt'), $now),
				Format::age($success, $now),
				self::step($row, 'discovery'),
				Row::int($row, 'nodes_seen') . ' / ' . Row::int($row, 'ports_seen'),
				Format::age(Row::int($row, 'links_attempt'), $now),
				self::step($row, 'links'),
				(string) Row::int($row, 'links_unmatched'),
				(string) Row::int($row, 'links_skipped'),
				implode(', ', array_map(static fn (string $k, int $v): string => "$k $v", array_keys($outcomes), $outcomes)),
			]);
		}

		html_end_box();

		html_start_box(__('Warnings', 'fabric'), '100%', '', '3', 'center', '');

		foreach ($warnings as $warning) {
			print '<tr><td class="textError">' . html_escape($warning) . '</td></tr>';
		}

		if ($warnings === []) {
			print '<tr><td>' . html_escape(__('No warnings.', 'fabric')) . '</td></tr>';
		}

		html_end_box();

		$this->renderNodes($fabrics, $nodes, $sweeps, $now);

		$conflicts = count(array_filter($nodes, static fn (Node $n): bool => $n->identity === IdentityState::Conflict));

		html_start_box(__('Identity', 'fabric'), '100%', '', '3', 'center', '');
		print '<tr><td>' . html_escape(__('%d identity conflicts and %d open re-link proposals. Resolve them on the Fabric Admin page.', $conflicts, count($proposals), 'fabric')) . ' <a href="' . html_escape(AdminPage::FILE) . '">' . html_escape(__('Fabric Admin', 'fabric')) . '</a></td></tr>';
		html_end_box();
	}

	/**
	 * Nodes whose last counter sweep did not complete, worst first.
	 *
	 * @param list<Fabric>                       $fabrics
	 * @param list<Node>                         $nodes
	 * @param array<int, array<array-key, mixed>> $sweeps
	 */
	private function renderNodes(array $fabrics, array $nodes, array $sweeps, int $now): void {
		$names = [];

		foreach ($fabrics as $fabric) {
			$names[$fabric->id] = $fabric->name;
		}

		html_start_box(__('Counter Sweeps Needing Attention', 'fabric'), '100%', '', '3', 'center', '');
		print Format::header([__('Node', 'fabric'), __('GUID', 'fabric'), __('Fabric', 'fabric'), __('Last Sweep', 'fabric'), __('Last Success', 'fabric'), __('Duration', 'fabric'), __('Ports Read / Failed / Skipped', 'fabric'), __('Outcome', 'fabric'), __('Failure', 'fabric')]);

		$shown = 0;

		foreach ($nodes as $node) {
			$row     = $sweeps[$node->id] ?? null;
			$outcome = $row !== null ? SweepOutcome::tryFrom(Row::string($row, 'outcome')) : null;

			if ($row === null || $outcome === SweepOutcome::Completed || $outcome === SweepOutcome::Never || $shown >= 200) {
				continue;
			}

			$shown++;
			$error = CommandError::tryFrom(Row::string($row, 'error'));

			print Format::row([
				$node->label(),
				$node->guid->text,
				$names[$node->fabricId] ?? '-',
				Format::age(Row::int($row, 'last_attempt'), $now),
				Format::age(Row::int($row, 'last_success'), $now),
				__('%s s', number_format(Row::float($row, 'duration') ?? 0.0, 2), 'fabric'),
				Row::int($row, 'ports_read') . ' / ' . Row::int($row, 'ports_failed') . ' / ' . Row::int($row, 'ports_skipped'),
				$outcome->value ?? '-',
				$error?->message() ?? '-',
			]);
		}

		if ($shown === 0) {
			print '<tr><td colspan="9"><em>' . html_escape(__('Every swept node completed its last sweep.', 'fabric')) . '</em></td></tr>';
		}

		html_end_box();
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	private static function step(array $row, string $prefix): string {
		if (Row::int($row, $prefix . '_attempt') === 0) {
			return '-';
		}

		$error = CommandError::tryFrom(Row::string($row, $prefix . '_error'));

		return $error !== null ? $error->message() : __('OK, %s s', number_format(Row::float($row, $prefix . '_duration') ?? 0.0, 2), 'fabric');
	}
}
