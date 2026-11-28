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
 * One InnoDB transaction on the plugin's connection. InnoDB ends a
 * transaction on a deadlock, and Cacti's db_execute_prepared() then retries
 * the failed statement outside it, so a statement that "succeeded" may have
 * run after the transaction was gone. write() therefore checks after every
 * statement that the transaction is still open, and a caller abandons its
 * writes when it is not.
 */
final class TransactionRepository {
	/** Failed state for TransactionRepository. */
	private bool $failed = false;

	/** Insert id state for TransactionRepository. */
	private int $insertId = 0;

	/**
	 * Construct a new TransactionRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Begin.
	 *
	 * @return bool
	 */
	public function begin(): bool {
		$this->failed = false;

		return (bool) db_begin_transaction($this->connection);
	}

	/**
	 * Run one statement inside the transaction. Once one fails, the rest are
	 * skipped and commit() rolls back.
	 *
	 * @param list<int|float|string> $params
	 */
	public function write(string $sql, array $params, ?int $expectRows = null): bool {
		if ($this->failed) {
			return false;
		}

		// The driver's last insert id and affected rows describe the most
		// recent statement, so both are read before the transaction check
		// runs a query of its own.
		$ok             = db_execute_prepared($sql, $params, true, $this->connection) !== false;
		$this->insertId = $ok ? (int) db_fetch_insert_id($this->connection) : 0;
		$ok             = $ok
			&& ($expectRows === null || db_affected_rows($this->connection) === $expectRows)
			&& $this->active();

		$this->failed = !$ok;

		return $ok;
	}

	/**
	 * The id the last write() inserted, 0 for none.
	 */
	public function insertId(): int {
		return $this->insertId;
	}

	/**
	 * Commit when every statement succeeded inside the transaction, else
	 * roll back. True only for a commit.
	 */
	public function commit(): bool {
		if (!$this->failed && $this->active() && db_commit_transaction($this->connection)) {
			return true;
		}

		$this->rollback();

		return false;
	}

	/**
	 * Rollback.
	 *
	 * @return void
	 */
	public function rollback(): void {
		if ($this->active()) {
			db_rollback_transaction($this->connection);
		}

		$this->failed = false;
	}

	/**
	 * Active.
	 *
	 * @return bool
	 */
	private function active(): bool {
		return (int) db_fetch_cell_prepared('SELECT @@in_transaction', [], '', true, $this->connection) === 1;
	}
}
