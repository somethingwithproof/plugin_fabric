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
 * The admin page (Fabric Admin): fabrics, identity conflicts, re-link
 * proposals, device mapping, and the inventory export. Every action that
 * changes state is a POST form that csrf-magic signs (REQ-UI-004). Every
 * stored or fabric-supplied string is escaped where it is printed. Nothing
 * here runs a tool (REQ-SWEEP-005).
 *
 * Device permissions apply on top of the realm (DeviceAccess): conflicts,
 * proposals, the device list, and the export show only nodes on devices
 * the user may see, and a fabric's delete action only when the user may
 * see every device mapped in it. The fabric list itself is admin-wide: a
 * fabric record names an adapter and a poller, not a device.
 */
final class AdminPage {
	public const FILE = 'fabric_admin.php';

	public function __construct(
		private readonly FabricRepository $fabrics,
		private readonly NodeRepository $nodes,
		private readonly RelinkRepository $relinks,
		private readonly PollerRepository $pollers,
		private readonly HostRepository $hosts,
		private readonly DeviceAccess $access,
	) {
	}

	/**
	 * html_start_box() prints its title as markup, so names in titles are
	 * escaped before they go in.
	 */
	public function renderList(): void {
		$fabrics   = $this->fabrics->all();
		$nodes     = $this->nodes->all();
		$proposals = $this->relinks->open();

		if ($fabrics === null || $nodes === null || $proposals === null) {
			html_start_box(__('Fabric Admin', 'fabric'), '100%', '', '3', 'center', '');
			print '<tr><td class="textError">' . html_escape(__('The fabric records could not be read. See the Cacti log.', 'fabric')) . '</td></tr>';
			html_end_box();

			return;
		}

		$pollers = $this->pollers->names();

		html_start_box(__('Fabrics', 'fabric'), '100%', '', '3', 'center', self::FILE . '?action=edit_fabric');
		print Format::header([__('Name', 'fabric'), __('Backend', 'fabric'), __('Poller', 'fabric'), __('Adapter', 'fabric'), __('Sweep Interval', 'fabric'), __('State', 'fabric'), __('Nodes', 'fabric'), __('Actions', 'fabric')]);

		if ($fabrics === []) {
			print '<tr><td colspan="8"><em>' . html_escape(__('No fabrics defined yet.', 'fabric')) . '</em></td></tr>';
		}

		foreach ($fabrics as $fabric) {
			$count = count(array_filter($nodes, static fn (Node $n): bool => $n->fabricId === $fabric->id));

			print '<tr class="tableRow">'
				. '<td><a class="linkEditMain" href="' . html_escape(self::FILE . '?action=edit_fabric&id=' . $fabric->id) . '">' . html_escape($fabric->name) . '</a></td>'
				. '<td>' . html_escape($fabric->backend->label()) . '</td>'
				. '<td>' . html_escape($pollers[$fabric->pollerId] ?? __('Deleted poller (ID %d)', $fabric->pollerId, 'fabric')) . '</td>'
				. '<td>' . html_escape($fabric->adapter . ' ' . __('port %d', $fabric->adapterPort, 'fabric')) . '</td>'
				. '<td>' . html_escape(__('%d seconds', $fabric->interval, 'fabric')) . '</td>'
				. '<td>' . html_escape($fabric->adminState->value) . '</td>'
				. '<td>' . $count . '</td>'
				. '<td>' . ($this->access->fabric($fabric->id, $nodes) ? '<a class="ui-button" href="' . html_escape(self::FILE . '?action=delete_fabric&id=' . $fabric->id) . '">' . html_escape(__('Delete', 'fabric')) . '</a>' : html_escape(__('Holds devices you may not see', 'fabric'))) . '</td>'
				. '</tr>';
		}

		html_end_box();

		$this->renderConflicts($this->access->visible($nodes));
		$this->renderProposals(array_values(array_filter($proposals, fn (array $r): bool => $this->access->host(Row::int($r, 'old_host_id')) && $this->access->host(Row::int($r, 'new_host_id')))));

		html_start_box(__('Export', 'fabric'), '100%', '', '3', 'center', '');
		print '<tr><td>' . html_escape(__('Download the fabrics and node inventory, with device mappings, as JSON before an uninstall that drops the plugin tables. It holds no credential.', 'fabric')) . '</td>'
			. '<td><a class="ui-button" href="' . html_escape(self::FILE . '?action=export') . '">' . html_escape(__('Export', 'fabric')) . '</a></td></tr>';
		html_end_box();
	}

	/**
	 * @param array<string, string> $input  rejected form values, shown again
	 * @param array<string, string> $errors field => message
	 */
	public function renderFabricEdit(?Fabric $fabric, array $input = [], array $errors = []): void {
		$value    = static fn (string $field, string $current): string => array_key_exists($field, $input) ? $input[$field] : $current;
		$backends = [];

		foreach (Backend::cases() as $backend) {
			$backends[$backend->value] = $backend->label();
		}

		print '<form method="post" action="' . html_escape(self::FILE) . '">';
		html_start_box($fabric === null ? __('New Fabric', 'fabric') : html_escape(__('Fabric: %s', $fabric->name, 'fabric')), '100%', '', '3', 'center', '');
		$this->errors($errors);

		$this->text('name', __('Name', 'fabric'), $value('name', $fabric->name ?? ''), 64);
		$this->select('backend', __('Backend', 'fabric'), $backends, $value('backend', $fabric->backend->value ?? Backend::Diags->value));
		$this->select('poller_id', __('Poller', 'fabric'), $this->keepCurrent($this->pollers->names(), $fabric->pollerId ?? null, __('Deleted poller (ID %d)', $fabric->pollerId ?? 0, 'fabric')), $value('poller_id', (string) ($fabric->pollerId ?? 1)));
		$this->text('sweep_interval', __('Sweep Interval (seconds)', 'fabric'), $value('sweep_interval', (string) ($fabric->interval ?? Fabric::DEFAULT_INTERVAL)), 5);
		$this->text('adapter', __('Adapter', 'fabric'), $value('adapter', $fabric->adapter ?? 'mlx5_0'), 32);
		$this->text('adapter_port', __('Adapter Port', 'fabric'), $value('adapter_port', (string) ($fabric->adapterPort ?? 1)), 3);
		$this->checkbox('enabled', __('Enabled', 'fabric'), array_key_exists('enabled', $input) ? Row::bool($input, 'enabled') : ($fabric?->enabled() ?? true));

		html_end_box();
		$this->submit('save_fabric', $fabric->id ?? 0, __('Save', 'fabric'));
	}

	/**
	 * Deleting a fabric asks first and says what goes with it. Cacti devices
	 * mapped to its nodes stay (REQ-FAB-005).
	 */
	public function renderDeleteConfirm(Fabric $fabric): void {
		$counts = $this->fabrics->deleteCounts($fabric->id);

		print '<form method="post" action="' . html_escape(self::FILE) . '">';
		html_start_box(html_escape(__('Delete Fabric: %s', $fabric->name, 'fabric')), '100%', '', '3', 'center', '');
		print '<tr><td>' . html_escape(__('Delete this fabric with its %d nodes, their ports, samples, and proposals? %d nodes are mapped to Cacti devices; those devices, their data sources, and RRD files are kept. The fabric is not contacted.', $counts['nodes'], $counts['mapped'], 'fabric')) . '</td></tr>';
		html_end_box();
		$this->submit('delete_fabric_confirm', $fabric->id, __('Delete', 'fabric'));
	}

	/**
	 * Map a node to a Cacti device. A node whose device was deleted keeps
	 * showing that device, so saving the form without a choice does not
	 * silently remap it.
	 */
	public function renderNodeEdit(Node $node): void {
		$hosts   = array_filter($this->hosts->names() ?? [], fn (int $id): bool => $this->access->host($id), ARRAY_FILTER_USE_KEY);
		$options = [0 => __('Unmapped', 'fabric')] + $hosts;
		$options = $this->keepCurrent($options, $node->hostId > 0 ? $node->hostId : null, __('Deleted device (ID %d)', $node->hostId, 'fabric'));

		print '<form method="post" action="' . html_escape(self::FILE) . '">';
		html_start_box(html_escape(__('Fabric Node: %s', $node->label(), 'fabric')), '100%', '', '3', 'center', '');
		print '<tr><td>' . html_escape(__('GUID', 'fabric')) . '</td><td>' . html_escape($node->guid->text) . '</td></tr>';
		$this->select('host_id', __('Cacti Device', 'fabric'), $options, (string) $node->hostId);
		print '<input type="hidden" name="revision" value="' . $node->revision . '">';
		html_end_box();
		$this->submit('save_node', $node->id, __('Save', 'fabric'));
	}

	/**
	 * Fabrics and nodes for the export. Nodes on devices the user may not
	 * see are left out; fabric records hold no device data and are all
	 * included. JSON_HEX_TAG keeps fabric-supplied descriptions from
	 * closing a tag if the file is ever opened as HTML.
	 */
	public function export(string $version, int $now): ?string {
		$fabrics = $this->fabrics->all();
		$nodes   = $this->nodes->all();

		if ($fabrics === null || $nodes === null) {
			return null;
		}

		$data = [
			'plugin'   => 'fabric',
			'version'  => $version,
			'exported' => gmdate('Y-m-d\TH:i:s\Z', $now),
			'fabrics'  => array_map(static fn (Fabric $f): array => [
				'id' => $f->id, 'name' => $f->name, 'backend' => $f->backend->value, 'poller_id' => $f->pollerId,
				'sweep_interval' => $f->interval, 'adapter' => $f->adapter, 'adapter_port' => $f->adapterPort, 'admin_state' => $f->adminState->value,
			], $fabrics),
			'nodes'    => array_map(static fn (Node $n): array => [
				'fabric_id' => $n->fabricId, 'guid' => $n->guid->text, 'node_type' => $n->type->value, 'port_count' => $n->portCount,
				'device_id' => $n->deviceId, 'vendor_id' => $n->vendorId, 'sysimg_guid' => $n->sysimgGuid->text ?? '', 'description' => $n->description,
				'host_id' => $n->hostId, 'presence' => $n->presence->value, 'identity' => $n->identity->value, 'first_seen' => $n->firstSeen, 'last_seen' => $n->lastSeen,
			], $this->access->visible($nodes)),
		];

		return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
	}

	/**
	 * @param list<Node> $nodes
	 */
	private function renderConflicts(array $nodes): void {
		$conflicts = array_values(array_filter($nodes, static fn (Node $n): bool => $n->identity === IdentityState::Conflict));

		html_start_box(__('Identity Conflicts', 'fabric'), '100%', '', '3', 'center', '');
		print Format::header([__('GUID', 'fabric'), __('Node', 'fabric'), __('Stored', 'fabric'), __('Seen', 'fabric'), __('Actions', 'fabric')]);

		if ($conflicts === []) {
			print '<tr><td colspan="5"><em>' . html_escape(__('No conflicts.', 'fabric')) . '</em></td></tr>';
		}

		foreach ($conflicts as $node) {
			print '<tr class="tableRow">'
				. '<td>' . html_escape($node->guid->text) . '</td>'
				. '<td>' . html_escape($node->label()) . '</td>'
				. '<td>' . html_escape(self::identity($node->identity())) . '</td>'
				. '<td>' . html_escape($node->conflictSeen !== null ? self::identity($node->conflictSeen) : __('Printed twice in one discovery', 'fabric')) . '</td>'
				. '<td>' . ($node->conflictSeen !== null ? $this->actionForm('accept_conflict', $node->id, __('Accept Seen Identity', 'fabric'), $node->revision) : '') . '</td>'
				. '</tr>';
		}

		html_end_box();
	}

	/**
	 * @param list<array<array-key, mixed>> $proposals
	 */
	private function renderProposals(array $proposals): void {
		html_start_box(__('Re-link Proposals', 'fabric'), '100%', '', '3', 'center', '');
		print Format::header([__('Missing Node', 'fabric'), __('New Node', 'fabric'), __('Shared Neighbor Port', 'fabric'), __('Device', 'fabric'), __('Actions', 'fabric')]);

		if ($proposals === []) {
			print '<tr><td colspan="5"><em>' . html_escape(__('No open proposals.', 'fabric')) . '</em></td></tr>';
		}

		foreach ($proposals as $row) {
			$id = Row::int($row, 'id');

			print '<tr class="tableRow">'
				. '<td>' . html_escape(Row::string($row, 'old_guid') . ' ' . Row::string($row, 'old_description')) . '</td>'
				. '<td>' . html_escape(Row::string($row, 'new_guid') . ' ' . Row::string($row, 'new_description')) . '</td>'
				. '<td>' . html_escape(Row::string($row, 'neighbor_guid') . ':' . Row::int($row, 'neighbor_port')) . '</td>'
				. '<td>' . html_escape(Row::int($row, 'old_host_id') > 0 ? (string) Row::int($row, 'old_host_id') : __('Unmapped', 'fabric')) . '</td>'
				. '<td>' . $this->actionForm('confirm_relink', $id, __('Confirm', 'fabric')) . ' ' . $this->actionForm('reject_relink', $id, __('Reject', 'fabric')) . '</td>'
				. '</tr>';
		}

		html_end_box();
	}

	private static function identity(NodeIdentity $identity): string {
		return sprintf('%s, %d ports, device 0x%x, vendor 0x%x, system image %s', $identity->type->label(), $identity->portCount, $identity->deviceId, $identity->vendorId, $identity->sysimgGuid->text ?? '-');
	}

	/**
	 * Options with the stored value kept even when what it names is gone.
	 *
	 * @param array<int, string> $options
	 *
	 * @return array<int, string>
	 */
	private function keepCurrent(array $options, ?int $current, string $label): array {
		if ($current !== null && !isset($options[$current])) {
			$options[$current] = $label;
		}

		return $options;
	}

	/**
	 * @param array<string, string> $errors
	 */
	private function errors(array $errors): void {
		foreach ($errors as $message) {
			print '<tr><td colspan="2" class="textError">' . html_escape($message) . '</td></tr>';
		}
	}

	private function submit(string $action, int $id, string $label): void {
		print '<input type="hidden" name="action" value="' . html_escape($action) . '">'
			. '<input type="hidden" name="id" value="' . $id . '">'
			. '<input type="submit" class="ui-button" value="' . html_escape($label) . '">'
			. '</form>';
	}

	private function actionForm(string $action, int $id, string $label, ?int $revision = null): string {
		return '<form method="post" action="' . html_escape(self::FILE) . '" style="display:inline">'
			. '<input type="hidden" name="action" value="' . html_escape($action) . '">'
			. '<input type="hidden" name="id" value="' . $id . '">'
			. ($revision !== null ? '<input type="hidden" name="revision" value="' . $revision . '">' : '')
			. '<input type="submit" class="ui-button" value="' . html_escape($label) . '">'
			. '</form>';
	}

	private function text(string $name, string $label, string $value, int $length): void {
		print '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label></td>'
			. '<td><input type="text" id="' . html_escape($name) . '" name="' . html_escape($name) . '" value="' . html_escape($value) . '" maxlength="' . $length . '" size="' . min(60, $length) . '"></td></tr>';
	}

	private function checkbox(string $name, string $label, bool $checked): void {
		print '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label></td><td><input type="checkbox" id="' . html_escape($name) . '" name="' . html_escape($name) . '" value="on"' . ($checked ? ' checked' : '') . '></td></tr>';
	}

	/**
	 * @param array<array-key, string> $options value => label
	 */
	private function select(string $name, string $label, array $options, string $current): void {
		print '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label></td><td><select id="' . html_escape($name) . '" name="' . html_escape($name) . '">';

		foreach ($options as $value => $text) {
			print '<option value="' . html_escape((string) $value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . html_escape($text) . '</option>';
		}

		print '</select></td></tr>';
	}
}
