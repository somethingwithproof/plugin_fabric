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
use Somethingwithproof\Fabric\Command;
use Somethingwithproof\Fabric\CommandError;
use Somethingwithproof\Fabric\CounterSweep;
use Somethingwithproof\Fabric\Fabric;
use Somethingwithproof\Fabric\FabricRepository;
use Somethingwithproof\Fabric\FixedClock;
use Somethingwithproof\Fabric\IblinkinfoParser;
use Somethingwithproof\Fabric\LinkStateRepository;
use Somethingwithproof\Fabric\Node;
use Somethingwithproof\Fabric\ProcessResult;
use Somethingwithproof\Fabric\ProcessRunner;
use Somethingwithproof\Fabric\Settings;
use Somethingwithproof\Fabric\SweepOutcome;
use Somethingwithproof\Fabric\Tests\FabricGenerator;
use Somethingwithproof\Fabric\Tests\Scratch;

require_once dirname(__DIR__) . '/Support/FabricGenerator.php';

/**
 * Plays perfquery from a FabricGenerator, records every command, and fails
 * where a test says.
 */
final class ScriptedRunner implements ProcessRunner {
	/** @var list<list<string>> */
	public array $argv = [];

	/**
	 * @param array<int, int>      $failAt LID => first port that fails
	 * @param array<int, string>   $modes  LID => timeout|garbage
	 */
	public function __construct(private readonly FabricGenerator $fabric, private readonly array $failAt = [], private readonly array $modes = [], private readonly int $capMask = 0x1600) {
	}

	public function run(Command $command, float $timeout, int $maxBytes): ProcessResult {
		$this->argv[] = $command->argv;
		$lid          = (int) $command->argv[count($command->argv) - 2];
		$ports        = array_map('intval', explode(',', $command->argv[count($command->argv) - 1]));
		$failAt       = $this->failAt[$lid] ?? null;
		$output       = $this->fabric->perfquery($lid, $ports, in_array('-x', $command->argv, true), $failAt, $this->capMask);

		return match ($this->modes[$lid] ?? '') {
			'timeout' => new ProcessResult(null, '', '', true, false, true, $timeout),
			'garbage' => new ProcessResult(0, "<html>\n", '', false, false, true, 0.1),
			default   => new ProcessResult($failAt !== null && in_array($failAt, $ports, true) ? 255 : 0, $output, "ibwarn: mad_rpc failed\n", false, false, true, 0.1),
		};
	}
}

function fabric_sweep_fabric(): Fabric {
	return new Fabric(1, 'ib0', Backend::Diags, 1, 300, 'mlx5_0', 1, AdminState::Enabled);
}

/**
 * Queue the node's ports for NodeRepository::ports(), the node row, and a
 * held claim.
 *
 * @param array<int, int> $ports port number => LID (0 for a switch port)
 */
function fabric_queue_ports(array $ports): void {
	$rows = [];

	foreach ($ports as $number => $lid) {
		$rows[] = ['node_id' => 9, 'port_number' => $number, 'lid' => $lid, 'presence' => 'present'];
	}

	$GLOBALS['__fabric_db_rows'] = [
		'FROM plugin_fabric_port'             => $rows,
		'FROM plugin_fabric_claim'            => [['n' => 1]],
		'FROM plugin_fabric_node WHERE id = ' => [['id' => 9, 'fabric_id' => 1, 'guid' => '0x0002c90300a00000', 'node_type' => 'switch', 'presence' => 'present', 'identity' => 'ok']],
	];
}

function fabric_node(string $type, int $lid): Node {
	$node = Node::fromRow(['id' => 9, 'fabric_id' => 1, 'guid' => '0x0002c90300a00000', 'node_type' => $type, 'lid' => $lid, 'presence' => 'present', 'identity' => 'ok']);
	assert($node !== null);

	return $node;
}

/**
 * A sweeper whose tool directory is a private scratch directory that passes
 * ToolDirectory's checks for the test account. The scripted runner never
 * runs anything from it.
 */
function fabric_sweeper(ProcessRunner $runner, array $settings = []): CounterSweep {
	$dir = (string) realpath($GLOBALS['__fabric_scratch']->dir);

	return new CounterSweep(false, new FixedClock(1_800_000_000), $runner, Settings::fromRow($settings + ['fabric_tool_dir' => $dir]), (int) fileowner($dir));
}

/**
 * @return list<array{sql: string, params: array<array-key, mixed>}>
 */
function fabric_writes(string $needle): array {
	return array_values(array_filter($GLOBALS['__fabric_db_exec'], static fn (array $e): bool => str_contains($e['sql'], $needle)));
}

beforeEach(function () {
	$GLOBALS['__fabric_scratch'] = new Scratch();
	$GLOBALS['__fabric_db_exec'] = [];
	$GLOBALS['__fabric_db_rows'] = [];
	$GLOBALS['__fabric_log']     = [];
	$GLOBALS['__fabric_db_fail'] = [];
});

// REQ-SWEEP-003: one command per counter group, switch ports in chunks.
afterEach(function () {
	$GLOBALS['__fabric_scratch']->remove();
});

it('fails a port whose sample write is lost as storage, not as read', function () {
	fabric_queue_ports([1 => 0, 2 => 0]);
	$GLOBALS['__fabric_db_fail'] = ["counters = VALUES(counters), error = ''"];

	$result = fabric_sweeper(new ScriptedRunner(FabricGenerator::forCas(1)))->run(fabric_sweep_fabric(), fabric_node('switch', 1), 'test');

	expect($result?->error)->toBe(CommandError::Storage)
		->and($result?->read)->toBe(0)
		->and($result?->failed)->toBe(2);
});

it('starts no tool once the sweep deadline is spent, and caps each run at the time left', function () {
	fabric_queue_ports([1 => 0]);
	$dir    = (string) realpath($GLOBALS['__fabric_scratch']->dir);
	$runner = new ScriptedRunner(FabricGenerator::forCas(1));
	$late   = new CounterSweep(false, new FixedClock(1_800_000_000), $runner, Settings::fromRow(['fabric_tool_dir' => $dir]), (int) fileowner($dir), microtime(true) - 1);

	expect($late->run(fabric_sweep_fabric(), fabric_node('switch', 1), 'test')?->read)->toBe(0)
		->and($runner->argv)->toBe([]);

	$limits = new class () implements ProcessRunner {
		/** @var list<float> */
		public array $timeouts = [];

		public function run(Command $command, float $timeout, int $maxBytes): ProcessResult {
			$this->timeouts[] = $timeout;

			return new ProcessResult(0, '', '', false, false, true, 0.0);
		}
	};
	$settings = Settings::fromRow(['fabric_command_timeout' => 60, 'fabric_tool_dir' => $dir]);
	$tools    = new \Somethingwithproof\Fabric\ToolRunner($limits, $settings, microtime(true) + 8);
	$tools->run((new \Somethingwithproof\Fabric\CommandBuilder($settings, fabric_sweep_fabric()))->counters(1, [1], false));

	expect($limits->timeouts[0])->toBeLessThanOrEqual(8.0)->toBeGreaterThan(6.0);
});

it('runs no tool from a tool directory that fails the checks', function () {
	fabric_queue_ports([1 => 0]);
	$runner = new ScriptedRunner(FabricGenerator::forCas(1));
	$dir    = (string) realpath($GLOBALS['__fabric_scratch']->dir);
	chmod($dir, 0770);

	$result = fabric_sweeper($runner)->run(fabric_sweep_fabric(), fabric_node('switch', 1), 'test');

	expect($result?->error)->toBe(CommandError::Config)
		->and($result?->skipped)->toBe(1)
		->and($runner->argv)->toBe([])
		->and(implode("\n", array_column($GLOBALS['__fabric_log'], 'message')))->toContain('can be written by its group');
});

it('reads a switch in one command per group per 64 ports', function () {
	fabric_queue_ports(array_fill_keys(range(1, 70), 0));
	$runner = new ScriptedRunner(FabricGenerator::forCas(1));

	$result = fabric_sweeper($runner)->run(fabric_sweep_fabric(), fabric_node('switch', 1), 'test');

	expect($result?->outcome())->toBe(SweepOutcome::Completed)
		->and($result->read)->toBe(70)
		->and($runner->argv)->toHaveCount(4)
		->and(end($runner->argv[0]))->toBe(implode(',', range(1, 64)))
		->and(end($runner->argv[1]))->toBe('65,66,67,68,69,70')
		->and($runner->argv[2])->toContain('-x')
		->and(fabric_writes('INSERT INTO plugin_fabric_sample'))->toHaveCount(140)
		->and(fabric_writes('UPDATE plugin_fabric_claim SET expires_at = 0'))->toHaveCount(1);
});

it('queries each adapter port at its own LID', function () {
	fabric_queue_ports([1 => 3, 2 => 4]);
	$runner = new ScriptedRunner(FabricGenerator::forCas(1));

	fabric_sweeper($runner)->run(fabric_sweep_fabric(), fabric_node('ca', 0), 'test');

	expect(array_map(static fn (array $a): array => array_slice($a, -2), $runner->argv))->toBe([['3', '1'], ['4', '2'], ['3', '1'], ['4', '2']]);
});

it('skips the extended group when a port does not advertise it', function () {
	fabric_queue_ports([1 => 0, 2 => 0]);
	$runner = new ScriptedRunner(FabricGenerator::forCas(1), capMask: 0x0000);

	$result = fabric_sweeper($runner)->run(fabric_sweep_fabric(), fabric_node('switch', 1), 'test');

	expect($runner->argv)->toHaveCount(1)
		->and($result?->outcome())->toBe(SweepOutcome::Completed);
});

// REQ-SWEEP-004: a failure is recorded as a failure, never as zeros.
it('keeps the ports printed before a failure, fails that port, and retries the rest', function () {
	fabric_queue_ports(array_fill_keys(range(1, 6), 0));
	$runner = new ScriptedRunner(FabricGenerator::forCas(1), failAt: [1 => 3]);

	$result = fabric_sweeper($runner, ['fabric_failure_budget' => '5'])->run(fabric_sweep_fabric(), fabric_node('switch', 1), 'test');
	$basic  = array_values(array_filter($runner->argv, static fn (array $a): bool => !in_array('-x', $a, true)));

	expect(end($basic[0]))->toBe('1,2,3,4,5,6')
		->and(end($basic[1]))->toBe('4,5,6')
		->and($result?->outcome())->toBe(SweepOutcome::Partial)
		->and($result->read)->toBe(5)
		->and($result->failed)->toBe(1)
		->and($result->error)->toBe(CommandError::ExitStatus);

	$failure = fabric_writes("error = VALUES(error)");

	expect($failure)->not->toBe([])
		->and($failure[0]['params'])->toContain(3)
		->and($failure[0]['params'])->toContain('exit_status');
});

it('stops a node sweep when its failure budget is spent', function () {
	fabric_queue_ports([1 => 3, 2 => 4, 3 => 5, 4 => 6]);
	$runner = new ScriptedRunner(FabricGenerator::forCas(1), modes: [3 => 'timeout', 4 => 'timeout', 5 => 'timeout', 6 => 'timeout']);

	$result = fabric_sweeper($runner, ['fabric_failure_budget' => '2'])->run(fabric_sweep_fabric(), fabric_node('ca', 0), 'test');

	expect($runner->argv)->toHaveCount(2)
		->and($result?->failed)->toBe(2)
		->and($result->skipped)->toBe(2)
		->and($result->outcome())->toBe(SweepOutcome::Failed)
		->and($result->error)->toBe(CommandError::Timeout);
});

it('stores nothing from output it cannot parse', function () {
	fabric_queue_ports([1 => 0]);
	$runner = new ScriptedRunner(FabricGenerator::forCas(1), modes: [1 => 'garbage']);

	$result = fabric_sweeper($runner)->run(fabric_sweep_fabric(), fabric_node('switch', 1), 'test');

	expect($result?->error)->toBe(CommandError::Parse)
		->and(fabric_writes('counters = VALUES(counters)'))->toBe([])
		->and(fabric_writes('error = VALUES(error)'))->toHaveCount(1);
});

it('fails a port without a LID as configuration, without running a tool', function () {
	fabric_queue_ports([1 => 0]);
	$runner = new ScriptedRunner(FabricGenerator::forCas(1));

	$result = fabric_sweeper($runner)->run(fabric_sweep_fabric(), fabric_node('ca', 0), 'test');

	expect($runner->argv)->toBe([])
		->and($result?->error)->toBe(CommandError::Config);
});

it('does nothing when another sweep holds the node', function () {
	fabric_queue_ports([1 => 0]);
	$GLOBALS['__fabric_db_rows']['FROM plugin_fabric_claim'] = [['n' => 0]];
	$runner = new ScriptedRunner(FabricGenerator::forCas(1));

	expect(fabric_sweeper($runner)->run(fabric_sweep_fabric(), fabric_node('switch', 1), 'test'))->toBeNull()
		->and($runner->argv)->toBe([]);
});

it('keeps reading an addressable sibling across sweeps with a port without a LID', function () {
	fabric_queue_ports([1 => 3, 2 => 0]);
	$rows = [];
	$dir = (string) realpath($GLOBALS['__fabric_scratch']->dir);
	for ($cycle = 1; $cycle <= 4; $cycle++) {
		$GLOBALS['__fabric_db_rows']['FROM plugin_fabric_sample'] = array_values($rows);
		$GLOBALS['__fabric_db_exec'] = [];
		$runner = new ScriptedRunner(FabricGenerator::forCas(1), capMask: 0);
		$sweep = new CounterSweep(false, new FixedClock(1_800_000_000 + $cycle * 300), $runner, Settings::fromRow(['fabric_tool_dir' => $dir, 'fabric_failure_budget' => '1']), (int) fileowner($dir));
		$result = $sweep->run(fabric_sweep_fabric(), fabric_node('ca', 0), 'cycle-' . $cycle);
		expect($result?->read)->toBe(1)
			->and($result->failed)->toBe(1)
			->and($runner->argv)->toHaveCount(1);
		foreach (fabric_writes('INSERT INTO plugin_fabric_sample') as $write) {
			$p = $write['params'];
			$rows[$p[1]] = ['port_number' => $p[1], 'counter_group' => $p[2], 'attempted_at' => $p[3]];
		}
		expect($rows[2]['attempted_at'])->toBe(1_800_000_000 + $cycle * 300);
	}
});

it('logs the failure with a cleaned stderr excerpt and never the output', function () {
	fabric_queue_ports([1 => 0, 2 => 0]);
	$runner = new ScriptedRunner(FabricGenerator::forCas(1), failAt: [1 => 2]);

	fabric_sweeper($runner)->run(fabric_sweep_fabric(), fabric_node('switch', 1), 'test');

	$messages = implode("\n", array_column($GLOBALS['__fabric_log'], 'message'));

	expect($messages)->toContain('perfquery: exit_status, exit 255')
		->and($messages)->toContain('ibwarn: mad_rpc failed')
		->and($messages)->not->toContain('PortXmitData');
});

it('sweeps nothing when another sweep finished the node between the due check and the claim', function () {
	fabric_queue_ports([1 => 0]);
	// The claim is taken; the node was swept just now by the other caller.
	$GLOBALS['__fabric_db_rows']['FROM plugin_fabric_node_sweep'] = [['last_attempt' => 1_800_000_000]];
	$runner = new ScriptedRunner(FabricGenerator::forCas(1));

	expect(fabric_sweeper($runner)->run(fabric_sweep_fabric(), fabric_node('switch', 1), 'test', true))->toBeNull()
		->and($runner->argv)->toBe([])
		->and(fabric_writes('UPDATE plugin_fabric_claim SET expires_at = 0'))->toHaveCount(1);
});

it('sweeps nothing for a node deleted with its fabric after the caller read it', function () {
	fabric_queue_ports([1 => 0]);
	unset($GLOBALS['__fabric_db_rows']['FROM plugin_fabric_node WHERE id = ']);
	$runner = new ScriptedRunner(FabricGenerator::forCas(1));

	expect(fabric_sweeper($runner)->run(fabric_sweep_fabric(), fabric_node('switch', 1), 'test'))->toBeNull()
		->and($runner->argv)->toBe([])
		->and(fabric_writes('plugin_fabric_node_sweep'))->toBe([]);
});

it('deletes a fabric in one transaction, and not at all while a sweep holds a claim', function () {
	$fabrics = new FabricRepository(false, new FixedClock(1_800_000_000));

	$GLOBALS['__fabric_db_rows'] = ['FROM plugin_fabric_fabric' => [['n' => 1]], 'FROM plugin_fabric_claim' => [['n' => 1]]];

	expect($fabrics->sweeping(1))->toBeTrue()
		->and($fabrics->delete(1))->toBeFalse()
		->and(fabric_writes('DELETE'))->toBe([])
		->and(fabric_writes('ROLLBACK'))->toHaveCount(1);

	$GLOBALS['__fabric_db_exec'] = [];
	$GLOBALS['__fabric_db_rows'] = ['FROM plugin_fabric_fabric' => [['n' => 1]], 'FROM plugin_fabric_claim' => [['n' => 0]]];

	expect($fabrics->delete(1))->toBeTrue()
		->and(array_column($GLOBALS['__fabric_db_exec'], 'sql')[0])->toBe('BEGIN')
		->and(fabric_writes('DELETE'))->toHaveCount(9)
		->and(end($GLOBALS['__fabric_db_exec'])['sql'])->toBe('COMMIT');
});

it('rolls link states back when the sweep no longer holds its claim at commit', function () {
	$GLOBALS['__fabric_db_rows'] = ['FROM plugin_fabric_node' => []];
	$report                      = (new IblinkinfoParser())->parse((string) file_get_contents(dirname(__DIR__) . '/fixtures/iblinkinfo-line.txt'));

	$applied = (new LinkStateRepository(false, new FixedClock(1_800_000_000)))->import(fabric_sweep_fabric(), $report, static fn (): bool => false);

	expect($applied)->toBeNull()
		->and(fabric_writes('ROLLBACK'))->toHaveCount(1)
		->and(fabric_writes('COMMIT'))->toBe([]);
});

it('resumes with ports left unread by a previous capped sweep', function () {
	fabric_queue_ports([1 => 3, 2 => 4]);
	$runner = new ScriptedRunner(FabricGenerator::forCas(1), modes: [3 => 'timeout', 4 => 'timeout']);
	fabric_sweeper($runner, ['fabric_failure_budget' => '1'])->run(fabric_sweep_fabric(), fabric_node('ca', 0), 'test');
	$attempt = fabric_writes('INSERT INTO plugin_fabric_sample')[0]['params'];
	$GLOBALS['__fabric_db_rows']['FROM plugin_fabric_sample'] = [[
		'port_number' => $attempt[1], 'counter_group' => $attempt[2], 'attempted_at' => $attempt[3],
	]];
	$next = new ScriptedRunner(FabricGenerator::forCas(1));
	fabric_sweeper($next)->run(fabric_sweep_fabric(), fabric_node('ca', 0), 'next');
	expect(array_slice($runner->argv[0], -2))->toBe(['3', '1'])
		->and(array_slice($next->argv[0], -2))->toBe(['4', '2']);
});

it('reads extended counters before repeating a newer basic sweep', function () {
	fabric_queue_ports([1 => 0, 2 => 0]);
	$GLOBALS['__fabric_db_rows']['FROM plugin_fabric_sample'] = [
		['port_number' => 1, 'counter_group' => 'basic', 'attempted_at' => 100, 'cap_mask' => 0x1600],
		['port_number' => 2, 'counter_group' => 'basic', 'attempted_at' => 100, 'cap_mask' => 0x1600],
		['port_number' => 1, 'counter_group' => 'extended', 'attempted_at' => 50],
	];
	$runner = new ScriptedRunner(FabricGenerator::forCas(1));
	fabric_sweeper($runner)->run(fabric_sweep_fabric(), fabric_node('switch', 1), 'test');
	expect($runner->argv[0])->toContain('-x')
		->and(end($runner->argv[0]))->toBe('2,1')
		->and($runner->argv[1])->not->toContain('-x');
});

it('reserves counter time when topology is due on every script-server call', function () {
	fabric_queue_ports([1 => 0]);
	$node = ['id' => 9, 'fabric_id' => 1, 'guid' => '0x0002c90300a00000', 'node_type' => 'switch', 'lid' => 1, 'presence' => 'present', 'identity' => 'ok'];
	$GLOBALS['__fabric_db_rows']['FROM plugin_fabric_node WHERE host_id'] = [$node];
	$GLOBALS['__fabric_db_rows']['FROM plugin_fabric_node WHERE id = '] = [$node];
	$GLOBALS['__fabric_db_rows']['FROM plugin_fabric_fabric'] = [['id' => 1, 'name' => 'ib0', 'backend' => 'diags', 'poller_id' => 1, 'sweep_interval' => 300, 'adapter' => 'mlx5_0', 'adapter_port' => 1, 'admin_state' => 'enabled']];
	$runner = new class () implements ProcessRunner {
		public array $limits = [];
		public function run(Command $command, float $timeout, int $maxBytes): ProcessResult {
			$this->limits[$command->tool->value][] = $timeout;
			return new ProcessResult(null, '', '', true, false, true, $timeout);
		}
	};
	$dir = (string) realpath($GLOBALS['__fabric_scratch']->dir);
	foreach (['ibnetdiscover', 'iblinkinfo', 'perfquery'] as $tool) {
		file_put_contents($dir . '/' . $tool, '');
		chmod($dir . '/' . $tool, 0755);
	}
	$sweep = new \Somethingwithproof\Fabric\Sweep(false, new FixedClock(1_800_000_000), $runner, Settings::fromRow(['fabric_tool_dir' => $dir, 'fabric_command_timeout' => 60]), 1, true, (int) fileowner($dir), microtime(true) + 12);
	$sweep->forDevice(7);
	expect($runner->limits['ibnetdiscover'][0])->toBeLessThanOrEqual(4.0)
		->and($runner->limits['perfquery'][0])->toBeGreaterThan(8.0);
});
