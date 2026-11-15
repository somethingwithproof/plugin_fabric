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


use Somethingwithproof\Fabric\AdminState;
use Somethingwithproof\Fabric\Backend;
use Somethingwithproof\Fabric\Fabric;
use Somethingwithproof\Fabric\FixedClock;
use Somethingwithproof\Fabric\IbnetdiscoverParser;
use Somethingwithproof\Fabric\InventoryRepository;
use Somethingwithproof\Fabric\Topology;
use Somethingwithproof\Fabric\Tests\FabricGenerator;

/*
 * REQ-INV-005: an accepted sighting stays accepted until the discovery sees
 * something else.
 */

const FABRIC_DUP_GUID = '0x0002c90300b00000';

/**
 * One switch and one adapter, with the adapter's node block printed twice.
 */
function fabric_duplicated(int $devid = 0x101b): Topology {
	$text  = FabricGenerator::forCas(1)->ibnetdiscover();
	$block = substr($text, (int) strrpos($text, "vendid=0x2c9\ndevid=0x101b"));

	return (new IbnetdiscoverParser())->parse($text . "\n" . str_replace('devid=0x101b', sprintf('devid=0x%x', $devid), $block));
}

/**
 * Stored rows for both nodes; the adapter carries the given accepted signature.
 */
function fabric_stored(string $accepted): void {
	$GLOBALS['__fabric_db_rows'] = ['FROM plugin_fabric_node WHERE fabric_id' => [
		['id' => 1, 'fabric_id' => 1, 'guid' => '0x0002c90300a00000', 'node_type' => 'switch', 'port_count' => 36, 'device_id' => 0xd2f0, 'vendor_id' => 0x2c9, 'sysimg_guid' => '0x0002c90300a00000', 'presence' => 'present', 'identity' => 'ok'],
		['id' => 2, 'fabric_id' => 1, 'guid' => FABRIC_DUP_GUID, 'node_type' => 'ca', 'port_count' => 2, 'device_id' => 0x101b, 'vendor_id' => 0x2c9, 'sysimg_guid' => FABRIC_DUP_GUID, 'presence' => 'present', 'identity' => 'ok', 'accepted_signature' => $accepted],
	]];
}

/**
 * @return list<array{sql: string, params: array<array-key, mixed>}>
 */
function fabric_conflict_writes(): array {
	return array_values(array_filter($GLOBALS['__fabric_db_exec'], static fn (array $e): bool => str_contains($e['sql'], 'conflict_signature = ?')));
}

beforeEach(function () {
	$GLOBALS['__fabric_db_exec'] = [];
	$GLOBALS['__fabric_db_rows'] = [];
	$GLOBALS['__fabric_in_tx']   = [];
});

it('raises a conflict for a GUID printed twice, and not again once that sighting was accepted', function () {
	$fabric   = new Fabric(1, 'ib0', Backend::Diags, 1, 300, 'mlx5_0', 1, AdminState::Enabled);
	$topology = fabric_duplicated();
	$import   = new InventoryRepository(false, new FixedClock(1_800_000_000));

	expect($topology->duplicates)->toBe([FABRIC_DUP_GUID]);

	fabric_stored('');
	expect($import->import($fabric, $topology)?->conflicts)->toBe(1)
		->and(fabric_conflict_writes())->toHaveCount(1)
		->and(fabric_conflict_writes()[0]['params'][2])->toBe($topology->signature(FABRIC_DUP_GUID));

	$GLOBALS['__fabric_db_exec'] = [];
	fabric_stored($topology->signature(FABRIC_DUP_GUID));
	expect($import->import($fabric, $topology)?->conflicts)->toBe(0)
		->and(fabric_conflict_writes())->toBe([]);

	// The second block now reports another device: a new sighting.
	$GLOBALS['__fabric_db_exec'] = [];
	fabric_stored($topology->signature(FABRIC_DUP_GUID));
	expect($import->import($fabric, fabric_duplicated(0x1021))?->conflicts)->toBe(1);
});

it('tells sightings apart by what was printed and how often', function () {
	$a = fabric_duplicated(0x1021);

	expect($a->signature(FABRIC_DUP_GUID))->toHaveLength(64)
		->and($a->signature(FABRIC_DUP_GUID))->not->toBe(fabric_duplicated()->signature(FABRIC_DUP_GUID))
		->and((new Topology($a->nodes, null, []))->signature(FABRIC_DUP_GUID))->not->toBe($a->signature(FABRIC_DUP_GUID))
		->and($a->signature('0x0002c90300b99999'))->toBe('');
});
