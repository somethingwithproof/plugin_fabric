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
 * Lease-locked NVIDIA UFM REST sweep runner. Feeds the exact same inventory,
 * link-state, sample, and health tables as the diagnostics backend.
 */
final class UfmSweep {
	public function __construct(
		private readonly \PDO|false $connection,
		private readonly Clock $clock,
		private readonly Settings $settings,
		private readonly ?UfmHttpTransport $transport = null,
		private readonly ?Vault $vault = null,
		private readonly ?float $deadline = null,
	) {
	}

	/**
	 * Run topology, link-state, and counter sweep for a UFM-backed fabric.
	 *
	 * @return int|null count of nodes whose counters were updated, or null on claim loss
	 */
	public function run(Fabric $fabric, string $holder, bool $ifDue = false): ?int {
		$claims = new ClaimRepository($this->connection, $this->clock);
		$health = new FabricHealthRepository($this->connection);

		if (!$claims->acquire(ClaimRepository::TOPOLOGY, $fabric->id, $holder, $this->settings->commandTimeout + 120)) {
			return null;
		}

		try {
			$fabrics = new FabricRepository($this->connection, $this->clock);

			if ($fabrics->find($fabric->id) === null) {
				return null;
			}

			if ($ifDue) {
				$row = $health->find($fabric->id);

				if ($row !== null && Row::int($row, 'discovery_attempt') > $this->clock->now() - intdiv($fabric->interval, 2)) {
					return 0;
				}
			}

			$client = $this->buildClient($fabric);
			$owned  = static fn (): bool => $claims->holdsForCommit(ClaimRepository::TOPOLOGY, $fabric->id, $holder) && $fabrics->existsForCommit($fabric->id);
			$parser = new UfmParser();

			$start  = microtime(true);
			$now    = $this->clock->now();
			$limit  = $this->deadline ?? (microtime(true) + $this->settings->commandTimeout);

			try {
				$rawPorts = $client->fetchPorts($limit);
				$parsed   = $parser->parsePorts($rawPorts);
				$imported = (new InventoryRepository($this->connection, $this->clock))->import($fabric, $parsed['topology'], $owned);

				if ($imported === null) {
					$health->discovery($fabric->id, $now, CommandError::Storage, microtime(true) - $start, 0, 0, null);

					return 0;
				}

				$duration = microtime(true) - $start;
				$health->discovery($fabric->id, $now, null, $duration, count($parsed['topology']->nodes), $parsed['topology']->portCount(), $parsed['topology']->local);

				$unmatched = (new LinkStateRepository($this->connection, $this->clock))->import($fabric, $parsed['links'], $owned);

				if ($unmatched === null) {
					$health->links($fabric->id, $now, CommandError::Storage, microtime(true) - $start, 0);
				} else {
					$health->links($fabric->id, $now, null, microtime(true) - $start, $unmatched, count($parsed['links']->skipped));
				}
			} catch (UfmTransportError $e) {
				Log::warning(sprintf('Fabric %d: UFM ports fetch failed: %s', $fabric->id, $e->getMessage()));
				$health->discovery($fabric->id, $now, $e->toCommandError(), microtime(true) - $start, 0, 0, null);

				return 0;
			} catch (ParseFailure $e) {
				Log::warning(sprintf('Fabric %d: UFM ports payload invalid: %s', $fabric->id, $e->getMessage()));
				$health->discovery($fabric->id, $now, CommandError::Parse, microtime(true) - $start, 0, 0, null);

				return 0;
			}

			return $this->sweepCounters($fabric, $client, $parser, $limit);
		} catch (CredentialError $e) {
			Log::error(sprintf('Fabric %d: UFM credentials unavailable: %s', $fabric->id, $e->getMessage()));
			$health->discovery($fabric->id, $this->clock->now(), CommandError::Config, 0.0, 0, 0, null);

			return 0;
		} finally {
			$claims->release(ClaimRepository::TOPOLOGY, $fabric->id, $holder);
		}
	}

	private function sweepCounters(Fabric $fabric, UfmClient $client, UfmParser $parser, float $limit): int {
		try {
			$raw      = $client->fetchSessionCounters($limit);
			$counters = $parser->parseSessionCounters($raw);
		} catch (UfmTransportError $e) {
			Log::warning(sprintf('Fabric %d: UFM session counters failed: %s', $fabric->id, $e->getMessage()));

			return 0;
		}

		$nodes   = (new NodeRepository($this->connection))->forFabric($fabric->id);
		$samples = new SampleRepository($this->connection);
		$sweeps  = new NodeSweepRepository($this->connection);

		if ($nodes === null) {
			return 0;
		}

		$now   = $this->clock->now();
		$swept = 0;

		foreach ($nodes as $node) {
			if (!$node->sweepable()) {
				continue;
			}

			$previous = $samples->keyed($node->id);

			if ($previous === null) {
				continue;
			}

			$result = new NodeSweepResult();
			$start  = microtime(true);

			for ($portNum = 1; $portNum <= max(1, $node->portCount); $portNum++) {
				$key = $node->guid->text . ':' . $portNum;

				if (!isset($counters[$key])) {
					continue;
				}

				$result->total++;
				$delta = $samples->record($node->id, $counters[$key], Backend::Ufm, $now, $previous[$portNum . ':' . CounterGroup::Extended->value] ?? null);

				if ($delta === null) {
					$result->failed++;
					$result->error = CommandError::Storage;
				} else {
					$result->read++;
				}
			}

			if ($result->total > 0) {
				$sweeps->start($node->id, $now);
				$result->duration = microtime(true) - $start;
				$sweeps->finish($node->id, $result, $now);
				$swept++;
			}
		}

		return $swept;
	}

	/**
	 * @throws CredentialError
	 */
	private function buildClient(Fabric $fabric): UfmClient {
		$endpoint = UfmEndpoint::parse($fabric->ufmUrl);

		if ($endpoint === null) {
			throw CredentialError::because('fabric UFM URL is missing or invalid');
		}

		$vault = $this->vault;

		if ($vault === null) {
			$keyPath = $this->settings->vaultKeyFile;

			if ($keyPath === '') {
				throw CredentialError::because('vault key file setting is not configured');
			}

			$vault = new Vault(VaultKey::fromFile($keyPath));
		}

		$creds     = (new CredentialRepository($this->connection, $this->clock))->decrypt($fabric->ufmCredentialId, $vault);
		$transport = $this->transport ?? new UfmSocketTransport($this->settings->maxOutputBytes(), (float) min(10, $this->settings->commandTimeout));

		return new UfmClient(
			$transport,
			$endpoint,
			$creds['auth_type'],
			$creds['username'],
			$creds['secret'],
			$fabric->ufmCaFile !== '' ? $fabric->ufmCaFile : null,
			$fabric->ufmSpkiPin !== '' ? $fabric->ufmSpkiPin : null,
		);
	}
}
