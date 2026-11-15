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
use Somethingwithproof\Fabric\CommandBuilder;
use Somethingwithproof\Fabric\Fabric;
use Somethingwithproof\Fabric\Settings;
use Somethingwithproof\Fabric\Tool;

if (PHP_SAPI !== 'cli') {
	exit(1);
}

/*
 * The command boundary (REQ-EXEC-001 to REQ-EXEC-003, ADR 0009): every
 * argument vector the builder can make, and every way to push a value
 * into one, checked against the allowlist.
 */

function fabric_settings_with(array $row = []): Settings {
	return Settings::fromRow($row + ['fabric_tool_dir' => '/usr/sbin']);
}

function fabric_record(string $adapter = 'mlx5_0', int $port = 1): Fabric {
	return new Fabric(1, 'ib0', Backend::Diags, 1, 300, $adapter, $port, AdminState::Enabled);
}

/**
 * Every command the builder can produce for a representative input set.
 *
 * @return list<list<string>>
 */
function fabric_all_argv(Settings $settings): array {
	$builder = new CommandBuilder($settings, fabric_record());
	$out     = [$builder->discovery()->argv, $builder->linkInfo()->argv];

	foreach ([1, 49151] as $lid) {
		foreach ([[1], [1, 2, 3], range(1, 64)] as $ports) {
			foreach ([false, true] as $extended) {
				$out[] = $builder->counters($lid, $ports, $extended)->argv;
			}
		}
	}

	return $out;
}

it('composes exactly the expected argument vectors', function () {
	$builder = new CommandBuilder(fabric_settings_with(['fabric_mad_timeout' => '500']), fabric_record('hfi1_0', 2));

	expect($builder->discovery()->argv)->toBe(['/usr/sbin/ibnetdiscover', '-t', '500', '-C', 'hfi1_0', '-P', '2'])
		->and($builder->linkInfo()->argv)->toBe(['/usr/sbin/iblinkinfo', '--line', '-t', '500', '-C', 'hfi1_0', '-P', '2'])
		->and($builder->counters(7, [1, 5, 36], false)->argv)->toBe(['/usr/sbin/perfquery', '-t', '500', '-C', 'hfi1_0', '-P', '2', '7', '1,5,36'])
		->and($builder->counters(7, [3], true)->argv)->toBe(['/usr/sbin/perfquery', '-x', '-t', '500', '-C', 'hfi1_0', '-P', '2', '7', '3'])
		->and($builder->counters(7, [3], true)->tool)->toBe(Tool::Perfquery);
});

it('passes a node name map only as its own validated argument', function () {
	$builder = new CommandBuilder(fabric_settings_with(['fabric_node_name_map' => '/etc/rdma/ib-node-name-map']), fabric_record());

	expect($builder->discovery()->argv)->toContain('--node-name-map')
		->and(array_slice($builder->linkInfo()->argv, -2))->toBe(['--node-name-map', '/etc/rdma/ib-node-name-map'])
		->and($builder->counters(1, [1], false)->argv)->not->toContain('--node-name-map');
});

it('never produces a reset, clear, or unlisted option, and never a third perfquery positional', function () {
	$forbidden = ['-r', '-R', '-k', '-K', '-y', '--reset_after_read', '--Reset_only', '--clear-errors', '--clear-counts', '-o', '-s', '-g', '-p', '-l', '--diff', '--cache', '--load-cache', '-D', '-G', '-L'];

	foreach ([fabric_settings_with(), fabric_settings_with(['fabric_node_name_map' => '/etc/map'])] as $settings) {
		foreach (fabric_all_argv($settings) as $argv) {
			$tool    = basename($argv[0]);
			$options = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => str_starts_with($a, '-')));

			expect(array_intersect($argv, $forbidden))->toBe([])
				->and(array_diff($options, CommandBuilder::OPTIONS[$tool]))->toBe([])
				->and(Tool::tryFrom($tool))->not->toBeNull();

			// Positionals: values after an option that takes one are not positional.
			$positional = [];

			for ($i = 1; $i < count($argv); $i++) {
				if (in_array($argv[$i], ['-t', '-C', '-P', '--node-name-map'], true)) {
					$i++;
				} elseif (!str_starts_with($argv[$i], '-')) {
					$positional[] = $argv[$i];
				}
			}

			expect(count($positional))->toBe($tool === 'perfquery' ? 2 : 0, implode(' ', $argv));
		}
	}
});

it('refuses values that could become an option, a path, or a second argument', function (Closure $build) {
	expect($build)->toThrow(InvalidArgumentException::class);
})->with([
	'adapter as option'   => [fn () => (new CommandBuilder(fabric_settings_with(), fabric_record('-r')))->discovery()],
	'adapter with space'  => [fn () => (new CommandBuilder(fabric_settings_with(), fabric_record('mlx5_0 -k')))->discovery()],
	'adapter with comma'  => [fn () => (new CommandBuilder(fabric_settings_with(), fabric_record('mlx5_0,1')))->discovery()],
	'adapter newline'     => [fn () => (new CommandBuilder(fabric_settings_with(), fabric_record("mlx5_0\n")))->discovery()],
	'adapter port 0'      => [fn () => (new CommandBuilder(fabric_settings_with(), fabric_record('mlx5_0', 0)))->discovery()],
	'lid 0'               => [fn () => (new CommandBuilder(fabric_settings_with(), fabric_record()))->counters(0, [1], false)],
	'multicast lid'       => [fn () => (new CommandBuilder(fabric_settings_with(), fabric_record()))->counters(0xC000, [1], false)],
	'port 0'              => [fn () => (new CommandBuilder(fabric_settings_with(), fabric_record()))->counters(1, [0], false)],
	'aggregate port 255'  => [fn () => (new CommandBuilder(fabric_settings_with(), fabric_record()))->counters(1, [255], false)],
	'repeated port'       => [fn () => (new CommandBuilder(fabric_settings_with(), fabric_record()))->counters(1, [1, 1], false)],
	'too many ports'      => [fn () => (new CommandBuilder(fabric_settings_with(), fabric_record()))->counters(1, range(1, 65), false)],
	'no ports'            => [fn () => (new CommandBuilder(fabric_settings_with(), fabric_record()))->counters(1, [], false)],
	'relative tool dir'   => [fn () => (new CommandBuilder(new Settings('usr/sbin', '', 1000, 60, 8192, 30, 3, true), fabric_record()))->discovery()],
	'dotdot tool dir'     => [fn () => (new CommandBuilder(new Settings('/usr/../tmp', '', 1000, 60, 8192, 30, 3, true), fabric_record()))->discovery()],
	'shell in tool dir'   => [fn () => (new CommandBuilder(new Settings('/usr/sbin;sh', '', 1000, 60, 8192, 30, 3, true), fabric_record()))->discovery()],
	'map as option'       => [fn () => (new CommandBuilder(new Settings('/usr/sbin', '-r', 1000, 60, 8192, 30, 3, true), fabric_record()))->discovery()],
	'timeout out of range' => [fn () => (new CommandBuilder(new Settings('/usr/sbin', '', 50, 60, 8192, 30, 3, true), fabric_record()))->discovery()],
]);

it('reads an unusable stored tool directory or map as the safe default', function () {
	$settings = Settings::fromRow(['fabric_tool_dir' => '/tmp;rm -rf /', 'fabric_node_name_map' => '--clear-counts']);

	expect((new CommandBuilder($settings, fabric_record()))->discovery()->argv)->toBe(['/usr/sbin/ibnetdiscover', '-t', '1000', '-C', 'mlx5_0', '-P', '1']);
});
