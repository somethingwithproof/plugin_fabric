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
use Somethingwithproof\Fabric\CommandError;
use Somethingwithproof\Fabric\Fabric;
use Somethingwithproof\Fabric\IbnetdiscoverParser;
use Somethingwithproof\Fabric\ProcRunner;
use Somethingwithproof\Fabric\Settings;
use Somethingwithproof\Fabric\Tests\FabricGenerator;
use Somethingwithproof\Fabric\Tests\FakeTools;
use Somethingwithproof\Fabric\Tests\Scratch;

require_once dirname(__DIR__) . '/Support/FabricGenerator.php';
require_once dirname(__DIR__) . '/Support/FakeTools.php';
require_once dirname(__DIR__) . '/Support/Scratch.php';

/*
 * The production runner against fake tool executables in a scratch
 * directory (REQ-EXEC-004, REQ-TEST-002). Nothing touches a real fabric.
 */

function fabric_fake_builder(string $dir, string $map = ''): CommandBuilder {
	$settings = Settings::fromRow(['fabric_tool_dir' => $dir, 'fabric_node_name_map' => $map]);

	expect($settings->toolDir)->toBe($dir);

	return new CommandBuilder($settings, new Fabric(1, 'ib0', Backend::Diags, 1, 300, 'mlx5_0', 1, AdminState::Enabled));
}

beforeEach(function () {
	$this->scratch = new Scratch();
	$this->fabric  = FabricGenerator::forCas(8);
});

afterEach(function () {
	$this->scratch->remove();
});

it('runs a tool with a fixed argument vector, no shell, and a minimal environment', function () {
	FakeTools::install($this->scratch->dir, $this->fabric);

	$result = (new ProcRunner())->run(fabric_fake_builder($this->scratch->dir)->discovery(), 10.0, 1 << 20);
	$calls  = FakeTools::calls($this->scratch->dir);

	expect($result->error())->toBeNull()
		->and(count((new IbnetdiscoverParser())->parse($result->stdout)->nodes))->toBe(count($this->fabric->nodes))
		->and($calls)->toHaveCount(1)
		->and($calls[0]['argv'])->toBe(['-t', '1000', '-C', 'mlx5_0', '-P', '1'])
		->and(array_diff_key($calls[0]['env'], ['__CF_USER_TEXT_ENCODING' => true]))->toBe(['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C']);
});

it('reports a missing or non-executable tool as not runnable', function () {
	$result = (new ProcRunner())->run(fabric_fake_builder($this->scratch->dir)->discovery(), 10.0, 1 << 20);

	expect($result->error())->toBe(CommandError::NotRunnable);

	$this->scratch->file('ibnetdiscover', "#!/bin/sh\nexit 0\n", 0600);

	expect((new ProcRunner())->run(fabric_fake_builder($this->scratch->dir)->discovery(), 10.0, 1 << 20)->error())->toBe(CommandError::NotRunnable);
});

it('kills a tool that runs past its timeout and keeps none of its output', function () {
	FakeTools::install($this->scratch->dir, $this->fabric, ['ibnetdiscover' => 'sleep']);

	$result = (new ProcRunner())->run(fabric_fake_builder($this->scratch->dir)->discovery(), 1.0, 1 << 20);

	expect($result->error())->toBe(CommandError::Timeout)
		->and($result->stdout)->toBe('')
		->and($result->duration)->toBeLessThan(5.0);
});

it('stops a tool whose output passes the size limit and keeps none of it', function () {
	FakeTools::install($this->scratch->dir, $this->fabric);

	$result = (new ProcRunner())->run(fabric_fake_builder($this->scratch->dir)->discovery(), 10.0, 1024);

	expect($result->error())->toBe(CommandError::TooLarge)
		->and($result->stdout)->toBe('');
});

it('reports a non-zero exit, keeps stdout for the caller, and bounds stderr', function () {
	FakeTools::install($this->scratch->dir, $this->fabric, ['perfquery' => [1 => ['failAt' => 3]]]);

	$result = (new ProcRunner())->run(fabric_fake_builder($this->scratch->dir)->counters(1, [1, 2, 3, 4], false), 10.0, 1 << 20);

	expect($result->error())->toBe(CommandError::ExitStatus)
		->and($result->exitCode)->toBe(255)
		->and(substr_count($result->stdout, '# Port counters:'))->toBe(2)
		->and($result->stderr)->toContain('mad_rpc')
		->and(strlen($result->stderr))->toBeLessThanOrEqual(2048);
});
