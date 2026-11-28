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
 * Sweep claims (ADR 0008). A claim is taken only when no unexpired one is
 * held by another sweep, in one conditional update, so two script server
 * calls that find the same node due cannot both sweep it. A claim expires
 * on its own, so a sweep that died never blocks the next cycle for longer
 * than its time limit.
 *
 * register_process_start() is not used: it checks and inserts in two
 * statements under a key that includes the process ID, and it signals a
 * process it believes timed out, which in the script server would be the
 * long-lived server itself.
 */
final class ClaimRepository {
	/** Topology constant for ClaimRepository. */
	public const TOPOLOGY = 'topology';
	/** Node constant for ClaimRepository. */
	public const NODE     = 'node';

	/**
	 * Construct a new ClaimRepository instance.
	 *
	 * @param \PDO|false $connection
	 * @param Clock $clock
	 */
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	/**
	 * Acquire.
	 *
	 * @param string $scope
	 * @param int $key
	 * @param string $holder
	 * @param int $seconds
	 * @return bool
	 */
	public function acquire(string $scope, int $key, string $holder, int $seconds): bool {
		$now = $this->clock->now();

		db_execute_prepared('INSERT IGNORE INTO plugin_fabric_claim (scope, claim_key, holder, expires_at) VALUES (?, ?, ?, 0)', [$scope, $key, ''], true, $this->connection);

		$ok = db_execute_prepared('UPDATE plugin_fabric_claim SET holder = ?, expires_at = ? WHERE scope = ? AND claim_key = ? AND (holder = ? OR expires_at < ?)', [$holder, $now + $seconds, $scope, $key, $holder, $now], true, $this->connection);

		return $ok !== false && $this->holds($scope, $key, $holder);
	}

	/**
	 * Holds.
	 *
	 * @param string $scope
	 * @param int $key
	 * @param string $holder
	 * @return bool
	 */
	public function holds(string $scope, int $key, string $holder): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_fabric_claim WHERE scope = ? AND claim_key = ? AND holder = ? AND expires_at >= ?', [$scope, $key, $holder, $this->clock->now()], '', true, $this->connection) === 1;
	}

	/**
	 * Extend a claim this holder still has. False when it expired or another
	 * sweep took it.
	 */
	public function renew(string $scope, int $key, string $holder, int $seconds): bool {
		$now = $this->clock->now();
		$ok  = db_execute_prepared('UPDATE plugin_fabric_claim SET expires_at = ? WHERE scope = ? AND claim_key = ? AND holder = ? AND expires_at >= ?', [$now + $seconds, $scope, $key, $holder, $now], true, $this->connection);

		return $ok !== false && $this->holds($scope, $key, $holder);
	}

	/**
	 * holds() for a caller inside a transaction, just before it commits. The
	 * locking read sees the latest claim rather than the transaction's
	 * snapshot, and keeps another sweep from taking the claim until the
	 * transaction ends, so a sweep that lost its claim never commits.
	 */
	public function holdsForCommit(string $scope, int $key, string $holder): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_fabric_claim WHERE scope = ? AND claim_key = ? AND holder = ? AND expires_at >= ? FOR UPDATE', [$scope, $key, $holder, $this->clock->now()], '', true, $this->connection) === 1;
	}

	/**
	 * Release.
	 *
	 * @param string $scope
	 * @param int $key
	 * @param string $holder
	 * @return void
	 */
	public function release(string $scope, int $key, string $holder): void {
		db_execute_prepared('UPDATE plugin_fabric_claim SET expires_at = 0 WHERE scope = ? AND claim_key = ? AND holder = ?', [$scope, $key, $holder], true, $this->connection);
	}

	/**
	 * A holder name unique to this sweep.
	 */
	public static function holder(): string {
		return sprintf('%s:%d:%s', substr((string) gethostname(), 0, 32), getmypid(), bin2hex(random_bytes(6)));
	}
}
