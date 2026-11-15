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
 * One topology sweep of a fabric: ibnetdiscover into the inventory, then
 * iblinkinfo --line into the port link states (REQ-INV-001, REQ-LINK-001).
 * Each step is recorded on its own in the fabric health row. Output that
 * fails, is cut off, or does not parse changes nothing: the inventory and
 * link states stay as the last good sweep left them (REQ-INV-004).
 */
final class TopologySweep {
	public function __construct(
		private readonly \PDO|false $connection,
		private readonly Clock $clock,
		private readonly ProcessRunner $runner,
		private readonly Settings $settings,
	) {
	}

	/**
	 * Due when the last discovery attempt is at least half an interval old,
	 * so a sweep started from the script server each polling cycle keeps
	 * the inventory no older than the interval.
	 */
	public function due(Fabric $fabric): ?bool {
		$row = (new FabricHealthRepository($this->connection))->find($fabric->id);

		if ($row === null) {
			return null;
		}

		return Row::int($row, 'discovery_attempt') <= $this->clock->now() - intdiv($fabric->interval, 2);
	}

	/**
	 * Seconds an import may take. The claim is renewed before each tool run
	 * to cover that run and the import after it, and each import checks the
	 * claim again before it commits.
	 */
	public const IMPORT_SECONDS = 300;

	/**
	 * Run the sweep under the fabric's topology claim. False when another
	 * sweep holds the claim, or with $ifDue when another sweep finished the
	 * work between the caller's due check and the claim.
	 */
	public function run(Fabric $fabric, string $holder, bool $ifDue = false): bool {
		$claims = new ClaimRepository($this->connection, $this->clock);

		if (!$claims->acquire(ClaimRepository::TOPOLOGY, $fabric->id, $holder, $this->claimSeconds())) {
			return false;
		}

		try {
			if ($ifDue && $this->due($fabric) !== true) {
				return false;
			}

			$this->sweep($fabric, $claims, $holder);
		} finally {
			$claims->release(ClaimRepository::TOPOLOGY, $fabric->id, $holder);
		}

		return true;
	}

	/**
	 * One tool run and the import after it.
	 */
	private function claimSeconds(): int {
		return $this->settings->commandTimeout + self::IMPORT_SECONDS + 30;
	}

	private function sweep(Fabric $fabric, ClaimRepository $claims, string $holder): void {
		$owned  = static fn (): bool => $claims->holdsForCommit(ClaimRepository::TOPOLOGY, $fabric->id, $holder);
		$health = new FabricHealthRepository($this->connection);
		$tools  = new ToolRunner($this->runner, $this->settings);
		$now    = $this->clock->now();

		$health->privilege($fabric->id, (new PrivilegeProbe($this->settings->toolDir))->warnings());

		try {
			$builder   = new CommandBuilder($this->settings, $fabric);
			$discovery = $builder->discovery();
			$linkInfo  = $builder->linkInfo();
		} catch (\InvalidArgumentException $e) {
			Log::error(sprintf('Fabric %d: not swept: %s', $fabric->id, $e->getMessage()));
			$health->discovery($fabric->id, $now, CommandError::Config, 0.0, 0, 0, null);

			return;
		}

		$result = $tools->run($discovery);
		$error  = $result->error();

		if ($error === null) {
			try {
				$topology = (new IbnetdiscoverParser())->parse($result->stdout);
				$imported = (new InventoryRepository($this->connection, $this->clock))->import($fabric, $topology, $owned);

				if ($imported === null) {
					$error = CommandError::Storage;
				} else {
					$health->discovery($fabric->id, $now, null, $result->duration, count($topology->nodes), $topology->portCount(), $topology->local);
					Log::info(sprintf('Fabric %d: discovery %d nodes, %d added, %d missing, %d conflicts, %d proposals', $fabric->id, count($topology->nodes), $imported->added, $imported->missing, $imported->conflicts, $imported->proposals));
				}
			} catch (ParseFailure $e) {
				Log::warning(sprintf('Fabric %d: ibnetdiscover output not applied: %s', $fabric->id, $e->getMessage()));
				$error = CommandError::Parse;
			}
		}

		if ($error !== null) {
			$health->discovery($fabric->id, $now, $error, $result->duration, 0, 0, null);

			// Link states join through what discovery stored; without a
			// current discovery they are left as they were.
			return;
		}

		if (!$claims->renew(ClaimRepository::TOPOLOGY, $fabric->id, $holder, $this->claimSeconds())) {
			Log::warning(sprintf('Fabric %d: link states not read: the sweep claim expired or another sweep took it', $fabric->id));

			return;
		}

		$now    = $this->clock->now();
		$result = $tools->run($linkInfo);
		$error  = $result->error();

		if ($error === null) {
			try {
				$report    = (new IblinkinfoParser())->parse($result->stdout);
				$unmatched = (new LinkStateRepository($this->connection, $this->clock))->import($fabric, $report, $owned);

				if ($unmatched === null) {
					$error = CommandError::Storage;
				} else {
					$health->links($fabric->id, $now, null, $result->duration, $unmatched, count($report->skipped));

					if ($report->skipped !== []) {
						Log::warning(sprintf('Fabric %d: %d iblinkinfo lines could not be read and were skipped; those nodes keep their last link states', $fabric->id, count($report->skipped)));
					}
				}
			} catch (ParseFailure $e) {
				Log::warning(sprintf('Fabric %d: iblinkinfo output not applied: %s', $fabric->id, $e->getMessage()));
				$error = CommandError::Parse;
			}
		}

		if ($error !== null) {
			$health->links($fabric->id, $now, $error, $result->duration, 0);
		}
	}
}
