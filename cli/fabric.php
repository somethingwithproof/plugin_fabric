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

/*
 * Operator and Configuration-as-Code CLI for InfiniBand Fabric:
 *
 *   cli/fabric.php export [--format=yaml|xml] [--file=PATH]
 *   cli/fabric.php validate --file=PATH [--format=yaml|xml]
 *   cli/fabric.php plan --file=PATH [--format=yaml|xml]
 *   cli/fabric.php apply --file=PATH --confirm=<PLAN_HASH> [--format=yaml|xml]
 *   cli/fabric.php health [--format=text|json]
 *
 * Must parse on PHP 8.0 and refuses runtimes below PHP 8.2.
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$dir = __DIR__;
chdir($dir . '/../../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/fabric/setup.php');

if (fabric_refuse_unsupported()) {
	exit(1);
}

fabric_boot();

$fabric_cli_usage = 'usage: cli/fabric.php export [--format=yaml|xml] [--file=PATH]' . PHP_EOL
	. '       cli/fabric.php validate --file=PATH [--format=yaml|xml]' . PHP_EOL
	. '       cli/fabric.php plan --file=PATH [--format=yaml|xml]' . PHP_EOL
	. '       cli/fabric.php apply --file=PATH --confirm=PLAN_HASH [--format=yaml|xml]' . PHP_EOL
	. '       cli/fabric.php health [--format=text|json]' . PHP_EOL;

$fabric_args = array_slice($_SERVER['argv'], 1);

if ($fabric_args === array()) {
	fwrite(STDERR, $fabric_cli_usage);
	exit(1);
}

$fabric_cmd  = array_shift($fabric_args);
$fabric_opts = array();

foreach ($fabric_args as $arg) {
	if (strpos($arg, '--') !== 0) {
		fwrite(STDERR, 'ERROR: Unexpected positional argument' . PHP_EOL . $fabric_cli_usage);
		exit(1);
	}

	$eq = strpos($arg, '=');

	if ($eq === false) {
		$fabric_opts[substr($arg, 2)] = '1';
	} else {
		$fabric_opts[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
	}
}

if ($fabric_cmd === 'health') {
	$fmt = isset($fabric_opts['format']) ? $fabric_opts['format'] : 'text';

	if ($fmt !== 'text' && $fmt !== 'json') {
		fwrite(STDERR, 'ERROR: --format must be text or json' . PHP_EOL . $fabric_cli_usage);
		exit(2);
	}

	$report = fabric_health_evaluator()->evaluate();

	if ($fmt === 'json') {
		fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
	} else {
		$summary = $report['summary'];
		fwrite(STDOUT, sprintf('Status: %s (schema v%d, fabrics %d/%d enabled, nodes %d, maintenance %d, degraded ports %d)' . PHP_EOL, $report['status'], $report['schema_version'], $summary['enabled_fabrics'], $summary['fabrics'], $summary['nodes'], $summary['nodes_in_maintenance'], $summary['degraded_ports']));

		foreach ($report['fabrics'] as $row) {
			fwrite(STDOUT, sprintf('  - %s [%s poller=%d]: %s (freshness=%s nodes=%d maint=%d degraded=%d)' . PHP_EOL, $row['name'], $row['backend'], $row['poller_id'], $row['status'], $row['freshness'], $row['nodes_total'], $row['nodes_in_maintenance'], $row['degraded_ports']));
		}

		foreach ($report['warnings'] as $warn) {
			fwrite(STDOUT, 'WARNING: ' . $warn . PHP_EOL);
		}
	}

	exit($report['status'] === 'fail' ? 1 : 0);
}

if (!in_array($fabric_cmd, array('export', 'validate', 'plan', 'apply'), true)) {
	fwrite(STDERR, 'ERROR: Unknown command' . PHP_EOL . $fabric_cli_usage);
	exit(1);
}

$format  = isset($fabric_opts['format']) ? $fabric_opts['format'] : \Somethingwithproof\Fabric\ConfigCodec::YAML;
$file    = isset($fabric_opts['file']) ? $fabric_opts['file'] : '';
$confirm = isset($fabric_opts['confirm']) ? $fabric_opts['confirm'] : '';

if (!\Somethingwithproof\Fabric\ConfigCodec::isFormat($format)) {
	fwrite(STDERR, 'ERROR: --format must be yaml or xml' . PHP_EOL . $fabric_cli_usage);
	exit(1);
}

$service = fabric_config_service();

try {
	if ($fabric_cmd === 'export') {
		$rendered = $service->export($format);

		if ($file === '' || $file === '-') {
			fwrite(STDOUT, $rendered);
			exit(0);
		}

		$handle = @fopen($file, 'x');

		if ($handle === false || fwrite($handle, $rendered) === false || !fclose($handle) || !chmod($file, 0600)) {
			fwrite(STDERR, 'ERROR: Cannot create ' . $file . ' (must not already exist)' . PHP_EOL);
			exit(1);
		}

		fwrite(STDOUT, 'Exported to ' . $file . PHP_EOL);
		exit(0);
	}

	if ($file === '') {
		fwrite(STDERR, 'ERROR: --file=PATH is required' . PHP_EOL . $fabric_cli_usage);
		exit(1);
	}

	$raw  = $file === '-' ? \Somethingwithproof\Fabric\ConfigCodec::readStream(STDIN) : \Somethingwithproof\Fabric\ConfigCodec::readFile($file);
	$tree = \Somethingwithproof\Fabric\ConfigCodec::parse($format, $raw);

	if ($fabric_cmd === 'validate') {
		$errs = $service->validate($tree);

		foreach ($errs as $err) {
			fwrite(STDERR, 'ERROR: ' . $err . PHP_EOL);
		}

		if ($errs === array()) {
			fwrite(STDOUT, 'Valid' . PHP_EOL);
			exit(0);
		}

		exit(2);
	}

	if ($fabric_cmd === 'plan') {
		$plan = $service->plan($tree);

		foreach ($plan->lines() as $line) {
			fwrite(STDOUT, $line . PHP_EOL);
		}

		$statusLine = $plan->hasErrors()
			? 'not applicable'
			: ($plan->isEmpty() ? 'no changes' : 'dry run; apply with --confirm=' . $plan->id());
		fwrite(STDOUT, 'Plan ' . $plan->id() . ': ' . $statusLine . PHP_EOL);
		exit($plan->hasErrors() ? 2 : 0);
	}

	if ($confirm === '') {
		fwrite(STDERR, 'ERROR: apply requires --confirm=PLAN_HASH' . PHP_EOL);
		exit(1);
	}

	$refusals = $service->apply($tree, $confirm);

	foreach ($refusals as $refusal) {
		fwrite(STDERR, 'ERROR: ' . $refusal . PHP_EOL);
	}

	if ($refusals === array()) {
		fwrite(STDOUT, 'Applied plan ' . $confirm . PHP_EOL);
		exit(0);
	}

	exit(1);
} catch (\Somethingwithproof\Fabric\ConfigException $e) {
	fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
	exit(2);
}
