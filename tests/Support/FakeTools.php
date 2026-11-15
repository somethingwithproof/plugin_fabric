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

namespace Somethingwithproof\Fabric\Tests;

/**
 * Fake perfquery, ibnetdiscover, and iblinkinfo executables for a scratch
 * tool directory (REQ-TEST-002). Each is a PHP script that prints what a
 * FabricGenerator fabric would produce, records its argument vector and
 * environment, and fails, stalls, or prints garbage when the scenario says
 * so. Nothing touches a real fabric.
 *
 * Scenario keys:
 * - "ibnetdiscover", "iblinkinfo": "exit" (stderr and exit 255), "garbage",
 *   "truncate" (half the output), "sleep" (stall past any timeout).
 * - "perfquery": LID => ["failAt" => port] stops before that port with
 *   exit 255, as perfquery does; ["mode" => "garbage"|"sleep"|"exit"].
 * - "capMask", "capMask2": what every perfquery block reports.
 */
final class FakeTools {
	/**
	 * @param array<string, mixed> $scenario
	 */
	public static function install(string $dir, FabricGenerator $fabric, array $scenario = []): void {
		file_put_contents($dir . '/fabric.state', serialize(['fabric' => $fabric, 'scenario' => $scenario]));

		$support = __DIR__;
		$plugin  = dirname(__DIR__, 2);

		foreach (['perfquery', 'ibnetdiscover', 'iblinkinfo'] as $tool) {
			$script = '#!' . PHP_BINARY . "\n<?php\n"
				. 'require ' . var_export($plugin . '/include/autoload.php', true) . ";\n"
				. 'require ' . var_export($support . '/FabricGenerator.php', true) . ";\n"
				. 'require ' . var_export($support . '/FakeTools.php', true) . ";\n"
				. 'exit(\\Somethingwithproof\\Fabric\\Tests\\FakeTools::main(' . var_export($tool, true) . ', __DIR__, array_slice($argv, 1)));' . "\n";

			file_put_contents($dir . '/' . $tool, $script);
			chmod($dir . '/' . $tool, 0750);
		}
	}

	/**
	 * Every recorded call: tool, argv, environment.
	 *
	 * @return list<array{tool: string, argv: list<string>, env: array<string, string>}>
	 */
	public static function calls(string $dir): array {
		$out = [];

		foreach (file($dir . '/calls.log', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
			$out[] = json_decode($line, true);
		}

		return $out;
	}

	/**
	 * @param list<string> $argv
	 */
	public static function main(string $tool, string $dir, array $argv): int {
		file_put_contents($dir . '/calls.log', json_encode(['tool' => $tool, 'argv' => $argv, 'env' => getenv()]) . "\n", FILE_APPEND | LOCK_EX);

		$state    = unserialize((string) file_get_contents($dir . '/fabric.state'));
		$fabric   = $state['fabric'];
		$scenario = $state['scenario'];
		$allowed  = ['perfquery' => ['-x', '-t', '-C', '-P'], 'ibnetdiscover' => ['-t', '-C', '-P', '--node-name-map'], 'iblinkinfo' => ['--line', '-t', '-C', '-P', '--node-name-map']][$tool];
		$takes    = ['-t', '-C', '-P', '--node-name-map'];
		$flags    = [];
		$args     = [];

		for ($i = 0; $i < count($argv); $i++) {
			if (str_starts_with($argv[$i], '-')) {
				if (!in_array($argv[$i], $allowed, true)) {
					fwrite(STDERR, "unknown option {$argv[$i]}\n");

					return 1;
				}

				$flags[$argv[$i]] = in_array($argv[$i], $takes, true) ? ($argv[++$i] ?? '') : true;

				continue;
			}

			$args[] = $argv[$i];
		}

		if ($tool !== 'perfquery') {
			$mode = $scenario[$tool] ?? '';

			if ($args !== [] || ($tool === 'iblinkinfo' && !isset($flags['--line']))) {
				fwrite(STDERR, "unexpected arguments\n");

				return 1;
			}

			$output = $tool === 'ibnetdiscover' ? $fabric->ibnetdiscover() : $fabric->iblinkinfo();

			return self::finish($output, $mode);
		}

		if (count($args) !== 2) {
			fwrite(STDERR, "perfquery: want exactly a LID and a port list\n");

			return 1;
		}

		$lid   = (int) $args[0];
		$ports = array_map('intval', explode(',', $args[1]));
		$rule  = $scenario['perfquery'][$lid] ?? [];
		$at    = $fabric->nodeAtLid($lid);

		if ($at === null) {
			fwrite(STDERR, "ibwarn: [1] mad_rpc: _do_madrpc failed; dport (Lid $lid)\nperfquery.c: iberror: failed: classportinfo query\n");

			return 255;
		}

		$output = $fabric->perfquery($lid, $ports, isset($flags['-x']), $rule['failAt'] ?? null, $scenario['capMask'] ?? 0x1600, $scenario['capMask2'] ?? 0x2);

		if (isset($rule['failAt']) && in_array($rule['failAt'], $ports, true)) {
			print $output;
			fwrite(STDERR, "ibwarn: [1] mad_rpc: _do_madrpc failed; dport (Lid $lid)\nperfquery.c: iberror: [pid 1] main: failed: perfquery\n");

			return 255;
		}

		return self::finish($output, $rule['mode'] ?? '');
	}

	private static function finish(string $output, string $mode): int {
		switch ($mode) {
			case 'exit':
				fwrite(STDERR, "ibwarn: [1] umad_open_port: can't open UMAD port (mlx5_0:1)\n");

				return 255;
			case 'garbage':
				print "<html>\x1b[31mnot tool output\n";

				return 0;
			case 'truncate':
				print substr($output, 0, intdiv(strlen($output), 2));

				return 0;
			case 'sleep':
				print substr($output, 0, 100);
				sleep(30);

				return 0;
			default:
				print $output;

				return 0;
		}
	}
}
