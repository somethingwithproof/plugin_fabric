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

/*
 * Fabric Admin (Fabric Admin realm). This file must parse on PHP 8.0: Cacti
 * may include it on an unsupported runtime, and fabric_page_start() refuses
 * there before any plugin class loads. Request values are read through
 * Cacti's request helpers and validated by the input classes before
 * anything is stored (REQ-UI-002). Nothing here runs a tool.
 *
 * The realm alone is not enough to change a record tied to a Cacti
 * device: every action on an existing node, proposal, or fabric checks
 * the user's device permissions on that record before any write, and a
 * new mapping needs the target device too (DeviceAccess).
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/fabric/setup.php');

if (!fabric_page_start()) {
	exit;
}

fabric_require_post('fabric_admin.php', array('save_fabric', 'delete_fabric_confirm', 'accept_conflict', 'confirm_relink', 'reject_relink', 'save_node'));

$fabric_clock   = new \Somethingwithproof\Fabric\SystemClock();
$fabric_fabrics = new \Somethingwithproof\Fabric\FabricRepository(false, $fabric_clock);
$fabric_nodes   = new \Somethingwithproof\Fabric\NodeRepository(false);
$fabric_relinks = new \Somethingwithproof\Fabric\RelinkRepository(false, $fabric_clock);
$fabric_pollers = new \Somethingwithproof\Fabric\PollerRepository(false);
$fabric_hosts   = new \Somethingwithproof\Fabric\HostRepository(false);
// A mapping to a device that was deleted protects no device data, and
// is_device_allowed() refuses an id the host table no longer has, which
// would leave the node uneditable for everyone.
$fabric_access  = new \Somethingwithproof\Fabric\DeviceAccess(static function (int $host) use ($fabric_hosts): bool {
	return fabric_device_allowed($host) || $fabric_hosts->gone($host);
});
$fabric_page    = new \Somethingwithproof\Fabric\AdminPage($fabric_fabrics, $fabric_nodes, $fabric_relinks, $fabric_pollers, $fabric_hosts, $fabric_access);
$fabric_action  = (string) get_nfilter_request_var('action');

/**
 * @param list<string> $fields
 * @return array<string, string>
 */
function fabric_request_fields(array $fields): array {
	$input = array();

	foreach ($fields as $field) {
		$input[$field] = (string) get_nfilter_request_var($field);
	}

	return $input;
}

/**
 * The node named by the request, only if it is unchanged since the form
 * that posted it was drawn.
 */
function fabric_posted_node(\Somethingwithproof\Fabric\NodeRepository $nodes): ?\Somethingwithproof\Fabric\Node {
	$node = $nodes->find((int) get_filter_request_var('id'));

	if ($node === null || $node->revision !== (int) get_filter_request_var('revision')) {
		raise_message('fabric_stale', __('The node changed after the page was drawn, so nothing was done. Review it and try again.', 'fabric'), MESSAGE_LEVEL_ERROR);

		return null;
	}

	return $node;
}

function fabric_denied(): void {
	raise_message('fabric_denied', __('Your device permissions do not allow changing this record.', 'fabric'), MESSAGE_LEVEL_ERROR);
	header('Location: fabric_admin.php');
	exit;
}

/**
 * Stop unless the user may see every device mapped in the fabric.
 */
function fabric_require_fabric_access(\Somethingwithproof\Fabric\DeviceAccess $access, \Somethingwithproof\Fabric\NodeRepository $nodes, int $fabricId): void {
	$all = $nodes->forFabric($fabricId);

	if ($all === null || !$access->fabric($fabricId, $all)) {
		fabric_denied();
	}
}

function fabric_done(bool $ok, string $done, string $failed): void {
	raise_message('fabric_result', $ok ? $done : $failed, $ok ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_ERROR);
	header('Location: fabric_admin.php');
	exit;
}

switch ($fabric_action) {
	case 'save_fabric':
		$fabric_id       = (int) get_filter_request_var('id');
		$fabric_input    = fabric_request_fields(array('name', 'backend', 'poller_id', 'sweep_interval', 'adapter', 'adapter_port', 'enabled'));
		$fabric_existing = $fabric_id > 0 ? $fabric_fabrics->find($fabric_id) : null;

		if ($fabric_id > 0 && $fabric_existing === null) {
			header('Location: fabric_admin.php');
			exit;
		}

		if ($fabric_existing !== null) {
			fabric_require_fabric_access($fabric_access, $fabric_nodes, $fabric_existing->id);
		}

		$fabric_parsed = \Somethingwithproof\Fabric\FabricInput::parse($fabric_input, $fabric_id, $fabric_pollers->names());
		$fabric_errors = $fabric_parsed->errors;

		if ($fabric_parsed->fabric !== null && $fabric_fabrics->nameTaken($fabric_parsed->fabric->name, $fabric_id)) {
			$fabric_errors['name'] = __('Name: another fabric already uses this name.', 'fabric');
		}

		if ($fabric_parsed->fabric !== null && $fabric_fabrics->portTaken($fabric_parsed->fabric)) {
			$fabric_errors['adapter'] = __('Adapter: another fabric already sweeps this adapter port on this poller. One fabric per adapter port.', 'fabric');
		}

		if ($fabric_errors !== array() || $fabric_parsed->fabric === null) {
			top_header();
			$fabric_page->renderFabricEdit($fabric_existing, $fabric_input, $fabric_errors);
			bottom_footer();
			exit;
		}

		fabric_done($fabric_fabrics->save($fabric_parsed->fabric) > 0, __('The fabric was saved.', 'fabric'), __('The fabric could not be saved.', 'fabric'));
	case 'delete_fabric':
		$fabric_existing = $fabric_fabrics->find((int) get_filter_request_var('id'));

		if ($fabric_existing === null) {
			header('Location: fabric_admin.php');
			exit;
		}

		fabric_require_fabric_access($fabric_access, $fabric_nodes, $fabric_existing->id);

		top_header();
		$fabric_page->renderDeleteConfirm($fabric_existing);
		bottom_footer();
		exit;
	case 'delete_fabric_confirm':
		fabric_require_fabric_access($fabric_access, $fabric_nodes, (int) get_filter_request_var('id'));
		fabric_done($fabric_fabrics->delete((int) get_filter_request_var('id')), __('The fabric and its inventory were deleted. No Cacti device was changed and the fabric was not contacted.', 'fabric'), __('The fabric could not be deleted.', 'fabric'));
	case 'accept_conflict':
		$fabric_node = fabric_posted_node($fabric_nodes);

		if ($fabric_node !== null && !$fabric_access->node($fabric_node)) {
			fabric_denied();
		}

		fabric_done($fabric_node !== null && $fabric_nodes->acceptConflict($fabric_node), __('The node identity was updated. It is swept again from the next cycle.', 'fabric'), __('The node identity was not changed.', 'fabric'));
	case 'confirm_relink':
		fabric_done($fabric_relinks->confirm((int) get_filter_request_var('id'), $fabric_access), __('The device mapping moved to the new node.', 'fabric'), __('The proposal could not be confirmed. The nodes may have changed since it was made.', 'fabric'));
	case 'reject_relink':
		$fabric_relink = $fabric_relinks->find((int) get_filter_request_var('id'));

		if ($fabric_relink === null || !$fabric_access->host((int) $fabric_relink['old_host_id']) || !$fabric_access->host((int) $fabric_relink['new_host_id'])) {
			fabric_denied();
		}

		fabric_done($fabric_relinks->reject((int) get_filter_request_var('id')), __('The proposal was rejected.', 'fabric'), __('The proposal could not be rejected.', 'fabric'));
	case 'save_node':
		$fabric_node = fabric_posted_node($fabric_nodes);
		$fabric_host = (int) get_filter_request_var('host_id');

		// The node's current device and the one it moves to.
		if ($fabric_node !== null && (!$fabric_access->node($fabric_node) || !$fabric_access->host($fabric_host))) {
			fabric_denied();
		}

		$fabric_ok   = $fabric_node !== null && ($fabric_host === 0 || $fabric_host === $fabric_node->hostId || $fabric_hosts->exists($fabric_host));

		fabric_done($fabric_ok && $fabric_node !== null && $fabric_nodes->setHost($fabric_node, $fabric_host), __('The node mapping was saved.', 'fabric'), __('The node mapping was not changed.', 'fabric'));
	case 'edit_fabric':
		$fabric_id = (int) get_filter_request_var('id');

		if ($fabric_id > 0) {
			fabric_require_fabric_access($fabric_access, $fabric_nodes, $fabric_id);
		}

		top_header();
		$fabric_page->renderFabricEdit($fabric_id > 0 ? $fabric_fabrics->find($fabric_id) : null);
		bottom_footer();
		exit;
	case 'edit_node':
		$fabric_node = $fabric_nodes->find((int) get_filter_request_var('id'));

		if ($fabric_node === null) {
			header('Location: fabric_admin.php');
			exit;
		}

		if (!$fabric_access->node($fabric_node)) {
			fabric_denied();
		}

		top_header();
		$fabric_page->renderNodeEdit($fabric_node);
		bottom_footer();
		exit;
	case 'export':
		$fabric_info = plugin_fabric_version();
		$fabric_json = $fabric_page->export(isset($fabric_info['version']) ? $fabric_info['version'] : '', $fabric_clock->now());

		if ($fabric_json === null) {
			fabric_done(false, '', __('The inventory could not be read for export.', 'fabric'));
		}

		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="fabric-inventory.json"');
		header('X-Content-Type-Options: nosniff');
		print $fabric_json;
		exit;
	default:
		top_header();
		$fabric_page->renderList();
		bottom_footer();
}
