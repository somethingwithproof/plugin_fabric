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
 * Creates or maps the Cacti device of a fabric node and attaches the
 * Fabric Port data query to it (ADR 0010, REQ-MAP-001 to REQ-MAP-004).
 *
 * Everything goes through Cacti's own functions: api_device_save() for a
 * new device, api_device_dq_add() for the data query, and
 * run_data_query() for a reindex. The plugin writes only its own mapping.
 * An existing device keeps every setting; only the data query is added.
 * No graph is created: an operator chooses the ports to graph on Cacti's
 * New Graphs page, as for any data query.
 *
 * A device is polled by the poller that sweeps its fabric, because the
 * script server answers from that poller's cache (REQ-ALERT-004). Every
 * fabric runs on the main poller in this phase, so a device on another
 * poller is refused.
 */
final class DeviceProvisioner {
	public function __construct(
		private readonly NodeRepository $nodes,
		private readonly FabricRepository $fabrics,
		private readonly CactiObjectRepository $objects,
		private readonly DeviceAccess $access,
		private readonly string $cactiPath,
	) {
	}

	/**
	 * Why a device cannot be created for the node now, or null.
	 */
	public function createRefusal(Node $node): ?string {
		if ($this->objects->deviceTemplateId() === 0 || $this->objects->queryId() === 0) {
			return __('The Fabric templates are not installed. Reinstall them from the health page.', 'fabric');
		}

		if ($node->hostId !== 0) {
			return __('The node is already mapped to a device.', 'fabric');
		}

		$fabric = $this->fabrics->find($node->fabricId);

		if ($fabric === null || !$fabric->onMainPoller()) {
			return __('The node\'s fabric is not swept by the main poller, so its device could not be polled.', 'fabric');
		}

		return null;
	}

	/**
	 * Why the node cannot be mapped to the device, or null.
	 */
	public function mapRefusal(Node $node, int $hostId): ?string {
		$device = $this->objects->device($hostId);
		$fabric = $this->fabrics->find($node->fabricId);

		if ($device === null) {
			return __('The device does not exist.', 'fabric');
		}

		if ($fabric === null || $device['poller_id'] !== $fabric->pollerId) {
			return __('The device is polled by another poller than the one that sweeps this fabric, so it could not read the fabric cache.', 'fabric');
		}

		if ($this->objects->queryId() === 0) {
			return __('The Fabric templates are not installed. Reinstall them from the health page.', 'fabric');
		}

		return null;
	}

	/**
	 * What creating a device would add, for the confirmation page
	 * (REQ-MAP-002).
	 *
	 * @return list<string>
	 */
	public function plan(Node $node): array {
		$fabric = $this->fabrics->find($node->fabricId);

		return [
			__('A device "%s" from the %s device template, host name %s, polled by poller %d, with availability checks and SNMP off.', $this->description($node), TemplateInstaller::DEVICE_TEMPLATE, $this->hostname($node), $fabric->pollerId ?? Fabric::MAIN_POLLER, 'fabric'),
			__('The %s data query on it, listing the node\'s ports. It creates no graph and no data source by itself.', TemplateInstaller::QUERY, 'fabric'),
		];
	}

	/**
	 * Create a device for an unmapped node, map the node to it, and
	 * attach the data query. A device the user cannot see afterwards, as
	 * with device permissions that deny new devices, is removed again.
	 *
	 * @return int the new device id, or 0 with the reason logged
	 */
	public function create(Node $node): int {
		$fabric     = $this->fabrics->find($node->fabricId);
		$templateId = $this->objects->deviceTemplateId();

		if ($fabric === null || $this->createRefusal($node) !== null) {
			return 0;
		}

		include_once($this->cactiPath . '/lib/api_device.php');

		$hostId = (int) api_device_save(0, $templateId, $this->description($node), $this->hostname($node), '', 0, '', '', 161, 500, '', 0, 0, 23, 400, 1,
			__('Created by the Fabric plugin for node %s. Its values come from the plugin cache; the host name is a reserved name that is never contacted.', $node->guid->text, 'fabric'),
			'MD5', '', 'DES', '', '', 5, 1, $fabric->pollerId, 0);

		if ($hostId <= 0) {
			Log::error(sprintf('Cacti refused the device for node %s', $node->guid->text));

			return 0;
		}

		if (!$this->access->host($hostId)) {
			api_device_remove($hostId);
			Log::warning(sprintf('The device created for node %s was removed again: the user\'s device permissions do not show it', $node->guid->text));

			return 0;
		}

		if (!$this->nodes->setHost($node, $hostId)) {
			api_device_remove($hostId);
			Log::error(sprintf('The device created for node %s was removed again: the node changed while it was created', $node->guid->text));

			return 0;
		}

		$this->attach($hostId);

		return $hostId;
	}

	/**
	 * Map a node to an existing device, or with 0 unmap it, and reindex the
	 * devices involved. The device's own settings are left alone.
	 */
	public function map(Node $node, int $hostId): bool {
		if ($hostId !== 0 && $this->mapRefusal($node, $hostId) !== null) {
			return false;
		}

		$old = $node->hostId;

		if (!$this->nodes->setHost($node, $hostId)) {
			return false;
		}

		if ($hostId !== 0) {
			$this->attach($hostId);
		}

		if ($old !== 0 && $old !== $hostId) {
			$this->reindex($old);
		}

		return true;
	}

	/**
	 * Add the data query to a device, or reindex it when it has the query.
	 * Safe to repeat. The query's reindex method is "Index Count Changed",
	 * which needs arg_num_indexes; the plugin reindexes itself whenever a
	 * mapping changes, which also covers a replacement of the same size.
	 */
	public function attach(int $hostId): void {
		$queryId = $this->objects->queryId();

		if ($queryId === 0 || $this->objects->device($hostId) === null) {
			return;
		}

		include_once($this->cactiPath . '/lib/api_device.php');
		include_once($this->cactiPath . '/lib/data_query.php');

		if ($this->objects->hasQuery($hostId)) {
			// The device template attaches the query with the site's
			// default reindex method; set Index Count Changed, which also
			// reindexes the device.
			api_device_dq_change($hostId, $queryId, DATA_QUERY_AUTOINDEX_INDEX_NUM_CHANGE);
		} else {
			api_device_dq_add($hostId, $queryId, DATA_QUERY_AUTOINDEX_INDEX_NUM_CHANGE);
		}
	}

	/**
	 * Reindex a device that has the data query, as after a node left it.
	 * Data sources of ports that are gone stay until an operator removes
	 * them (REQ-IDX-004).
	 *
	 * With no node left on the device the query lists no ports, and Cacti
	 * 1.2.31 treats an empty script query as a failed reindex and keeps the
	 * old cache. The device's Fabric Port cache is then emptied directly
	 * through Cacti's own cache writer; graphs, data sources, and RRD files
	 * stay.
	 */
	public function reindex(int $hostId): void {
		if ($this->objects->device($hostId) === null || !$this->objects->hasQuery($hostId)) {
			return;
		}

		include_once($this->cactiPath . '/lib/data_query.php');
		$queryId = $this->objects->queryId();

		if ($this->nodes->forHost($hostId) === []) {
			$none  = [];
			$empty = [];
			data_query_update_host_cache_from_buffer($hostId, $queryId, $none, $empty);

			return;
		}

		run_data_query($hostId, $queryId);
	}

	/**
	 * The node's label, cleaned like every data query input, since Cacti
	 * substitutes the device description into titles.
	 */
	public function description(Node $node): string {
		return substr(PortQuery::clean($node->label()), 0, 150);
	}

	/**
	 * A name in the reserved .invalid domain (RFC 6761), so no lookup or
	 * availability check of the device can reach a real host.
	 */
	public function hostname(Node $node): string {
		return substr($node->guid->text, 2) . '.fabric.invalid';
	}
}
