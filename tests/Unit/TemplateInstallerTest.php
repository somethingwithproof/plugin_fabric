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

use Somethingwithproof\Fabric\CactiObjectRepository;
use Somethingwithproof\Fabric\PortValues;
use Somethingwithproof\Fabric\TemplateInstaller;
use Somethingwithproof\Fabric\Tests\Scratch;

require_once dirname(__DIR__) . '/Support/Scratch.php';

/*
 * The shipped Cacti objects and the one file the plugin writes outside its
 * own directory (REQ-OBJ-001, REQ-OBJ-002, REQ-OBJ-004, ADR 0011).
 */

if (!function_exists('set_config_option')) {
	function set_config_option($config_name, $value, $remote = false) {
		$GLOBALS['__fabric_options'][$config_name] = $value;
	}
}

if (!function_exists('import_xml_data')) {
	function import_xml_data($xml_data, $import_as_new, $profile_id = false, $remove_orphans = false, $replace_svalues = false, $import_hashes = array()) {
		$GLOBALS['__fabric_imports'][] = $xml_data;

		return ['host_template' => [['result' => 'success']]];
	}
}

/**
 * A plugin copy and a Cacti tree in scratch, and an installer between them
 * whose objects are all found.
 */
function fabric_installer(Scratch $scratch): TemplateInstaller {
	$scratch->file('plugin/resource/script_server/fabric_ports.xml', "<interface>shipped</interface>\n");
	$scratch->file('plugin/templates/fabric-templates.xml', "<cacti></cacti>\n");
	$scratch->file('cacti/lib/utility.php', "<?php\n");
	$scratch->file('cacti/lib/import.php', "<?php\n");
	mkdir($scratch->dir . '/cacti/resource/script_server', 0700, true);
	$GLOBALS['__fabric_db_rows'] = ['WHERE hash' => [['id' => 3]]];

	return new TemplateInstaller($scratch->dir . '/plugin', $scratch->dir . '/cacti', new CactiObjectRepository(false));
}

function fabric_target(Scratch $scratch): string {
	return $scratch->dir . '/cacti/resource/script_server/fabric_ports.xml';
}

beforeEach(function () {
	$GLOBALS['__fabric_scratch']  = new Scratch();
	$GLOBALS['__fabric_options']  = [];
	$GLOBALS['__fabric_imports']  = [];
	$GLOBALS['__fabric_log']      = [];
	$GLOBALS['__fabric_db_rows']  = [];
});

afterEach(function () {
	$GLOBALS['__fabric_scratch']->remove();
});

it('writes the query file where none is, records it, and imports the templates', function () {
	$installer = fabric_installer($GLOBALS['__fabric_scratch']);

	expect($installer->install())->toBeTrue()
		->and(file_get_contents(fabric_target($GLOBALS['__fabric_scratch'])))->toBe("<interface>shipped</interface>\n")
		->and($GLOBALS['__fabric_options'][TemplateInstaller::FILES_SETTING])->toBe(hash('sha256', "<interface>shipped</interface>\n"))
		->and($GLOBALS['__fabric_imports'])->toBe(["<cacti></cacti>\n"])
		->and($installer->isCurrent())->toBeTrue()
		->and(glob($GLOBALS['__fabric_scratch']->dir . '/cacti/resource/script_server/.*.tmp'))->toBe([]);
});

it('replaces its own unchanged file on upgrade and removes it on uninstall', function () {
	$scratch   = $GLOBALS['__fabric_scratch'];
	$installer = fabric_installer($scratch);
	$installer->install();
	$scratch->file('plugin/resource/script_server/fabric_ports.xml', "<interface>newer</interface>\n");

	expect($installer->install())->toBeTrue()
		->and(file_get_contents(fabric_target($scratch)))->toBe("<interface>newer</interface>\n");

	$installer->uninstall();

	expect(file_exists(fabric_target($scratch)))->toBeFalse();
});

it('never overwrites or removes a file it did not write, or one changed after', function () {
	$scratch   = $GLOBALS['__fabric_scratch'];
	$installer = fabric_installer($scratch);
	$scratch->file('cacti/resource/script_server/fabric_ports.xml', "site file\n");

	expect($installer->install())->toBeFalse()
		->and(file_get_contents(fabric_target($scratch)))->toBe("site file\n")
		->and($installer->failure())->toContain('was not written by this plugin');

	$installer->uninstall();
	expect(file_get_contents(fabric_target($scratch)))->toBe("site file\n");

	// Written by the plugin, then edited by an operator.
	unlink(fabric_target($scratch));
	$installer->install();
	file_put_contents(fabric_target($scratch), "edited\n");
	$scratch->file('plugin/resource/script_server/fabric_ports.xml', "<interface>newer</interface>\n");

	expect($installer->install())->toBeFalse()
		->and(file_get_contents(fabric_target($scratch)))->toBe("edited\n");

	$installer->uninstall();
	expect(file_get_contents(fabric_target($scratch)))->toBe("edited\n");
});

it('leaves an identical file it did not write unclaimed, so uninstall keeps it', function () {
	$scratch   = $GLOBALS['__fabric_scratch'];
	$installer = fabric_installer($scratch);
	$scratch->file('cacti/resource/script_server/fabric_ports.xml', "<interface>shipped</interface>\n");

	expect($installer->install())->toBeTrue()
		->and($GLOBALS['__fabric_options'][TemplateInstaller::FILES_SETTING] ?? '')->toBe('');

	$installer->uninstall();
	expect(file_exists(fabric_target($scratch)))->toBeTrue();
});

it('refuses a symbolic link at the destination, dangling or not, and never writes through it', function (bool $dangling) {
	$scratch   = $GLOBALS['__fabric_scratch'];
	$installer = fabric_installer($scratch);
	$elsewhere = $scratch->dir . '/elsewhere.xml';

	if (!$dangling) {
		file_put_contents($elsewhere, "<interface>shipped</interface>\n");
	}

	symlink($elsewhere, fabric_target($scratch));

	expect($installer->install())->toBeFalse()
		->and(is_link(fabric_target($scratch)))->toBeTrue()
		->and($installer->failure())->toContain('symbolic link')
		->and($installer->queryFileProblem())->toContain('symbolic link');

	if ($dangling) {
		expect(file_exists($elsewhere))->toBeFalse();
	} else {
		expect(file_get_contents($elsewhere))->toBe("<interface>shipped</interface>\n");
	}

	$installer->uninstall();
	expect(is_link(fabric_target($scratch)))->toBeTrue();
})->with(['dangling' => true, 'to a file' => false]);

it('ships templates whose alertable values are all GAUGE and whose counters are DERIVE (ADR 0011)', function () {
	$xml = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/fabric-templates.xml');

	preg_match_all('#<data_source_name>([a-z0-9_]+)</data_source_name>\s*<rrd_minimum>([^<]*)</rrd_minimum>\s*<rrd_maximum>([^<]*)</rrd_maximum>\s*<data_source_type_id>(\d)</data_source_type_id>#', $xml, $m, PREG_SET_ORDER);

	$types = [];

	foreach ($m as $match) {
		$types[$match[1]] = (int) $match[4];
		expect($match[2])->toBe('0', $match[1]);
	}

	ksort($types);
	$names = PortValues::names();
	sort($names);

	expect(array_keys($types))->toBe($names);

	foreach ($types as $name => $type) {
		// 1 GAUGE, 3 DERIVE. Thold reads only GAUGE values safely (a
		// COUNTER or DERIVE that reads U makes Thold 1.8.2 fail).
		expect($type)->toBe(PortValues::isCounter($name) ? 3 : 1, $name);
	}

	foreach (array_keys(PortValues::RATE_OF + array_flip(PortValues::STATE_VALUES)) as $name) {
		expect($types[$name])->toBe(1, $name);
	}

	foreach (array_keys(PortValues::templates()) as $name) {
		expect($xml)->toMatch('/<hash_01[0-9]{4}' . TemplateInstaller::hash('data_template', $name) . '>/')
			->and($xml)->toContain('<name>' . $name . '</name>');
	}

	expect($xml)->toContain(TemplateInstaller::hash('host_template', TemplateInstaller::DEVICE_TEMPLATE))
		->and($xml)->toContain('&lt;path_cacti&gt;/resource/script_server/fabric_ports.xml')
		->and($xml)->not->toMatch('/<hash_2001/');
});
