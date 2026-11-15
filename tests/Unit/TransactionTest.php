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

use Somethingwithproof\Fabric\TransactionRepository;

beforeEach(function () {
	$GLOBALS['__fabric_db_exec'] = [];
	$GLOBALS['__fabric_in_tx']   = [];
});

it('keeps the insert id of a write although the transaction check runs a query after it', function () {
	$tx = new TransactionRepository(false);
	$tx->begin();

	expect($tx->write('INSERT INTO plugin_fabric_node (guid) VALUES (?)', ['0x0002c90300a00000']))->toBeTrue()
		->and($tx->insertId())->toBeGreaterThan(0);

	$tx->write('UPDATE plugin_fabric_node SET lid = ? WHERE id = ?', [1, 1]);

	expect($tx->insertId())->toBe(0);
});

it('abandons the writes once a deadlock ended the transaction', function () {
	$GLOBALS['__fabric_in_tx'] = [1, 0];
	$tx                        = new TransactionRepository(false);
	$tx->begin();

	expect($tx->write('UPDATE plugin_fabric_node SET lid = ? WHERE id = ?', [1, 1]))->toBeTrue()
		->and($tx->write('UPDATE plugin_fabric_node SET lid = ? WHERE id = ?', [2, 2]))->toBeFalse()
		->and($tx->write('UPDATE plugin_fabric_node SET lid = ? WHERE id = ?', [3, 3]))->toBeFalse()
		->and($tx->commit())->toBeFalse()
		->and(count(array_filter($GLOBALS['__fabric_db_exec'], static fn (array $e): bool => str_starts_with($e['sql'], 'UPDATE'))))->toBe(2);
});
