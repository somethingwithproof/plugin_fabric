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

if (PHP_SAPI !== 'cli') {
	exit(1);
}


/*
 * Build the Fabric templates in a disposable Cacti database with Cacti's
 * own save function, and write Cacti's export of them (get_item_xml with
 * dependencies) to templates/fabric-templates.xml. The plugin ships that
 * export, not this script's data:
 *
 *   FABRIC_TEST_DATABASE=disposable php plugins/fabric/tests/bin/build-templates.php [export-file]
 *
 * Hashes come from TemplateInstaller::hash(), so a rebuild updates the rows
 * it made and exports the same hashes. Must parse on PHP 8.0.
 *
 * Data source types follow ADR 0011: counters as received are DERIVE with
 * minimum 0, so a decrease is unknown rather than a spike, for graphs only;
 * the rates the plugin computes and the state values are GAUGE, the only
 * values a Thold threshold should use. The maximums are plausibility
 * bounds per second, not reset filters: 10^11 words (4 octets) of data,
 * about 1.25 times a 12X XDR link; 2 * 10^10 packets; 10^6 errors or
 * events; 10^12 transmit wait ticks.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/functions.php');
require_once($config['base_path'] . '/lib/export.php');
require_once($config['base_path'] . '/plugins/fabric/setup.php');

if (getenv('FABRIC_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set FABRIC_TEST_DATABASE=disposable\n");
	exit(2);
}

if (!fabric_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

use Somethingwithproof\Fabric\PortValues as V;
use Somethingwithproof\Fabric\TemplateInstaller as T;

const FABRIC_GAUGE  = 1;
const FABRIC_DERIVE = 3;

/** Core's "Get Script Server Data (Indexed)" input and its three index fields. */
const FABRIC_INDEXED_INPUT  = '332111d8b54ac8ce939af87a7eac0c06';
const FABRIC_INDEXED_FIELDS = array('172b4b0eacee4948c6479f587b62e512', '30fb5d5bcf3d66bb5abe88596f357c26', '31112c85ae4ff821d3b288336288818c');

/** Data source name => [type, maximum]. */
function fabric_ds_spec($name) {
	$state = array('active_lanes' => '12', 'speed_rank' => '9', 'degraded' => '1', 'port_state' => '4', 'age' => 'U');

	if (isset($state[$name])) {
		return array(FABRIC_GAUGE, $state[$name]);
	}

	if (isset(V::RATE_OF[$name])) {
		return array(FABRIC_GAUGE, 'U');
	}

	switch ($name) {
		case 'xmit_data':
		case 'rcv_data':
			return array(FABRIC_DERIVE, '100000000000');
		case 'xmit_pkts':
		case 'rcv_pkts':
			return array(FABRIC_DERIVE, '20000000000');
		case 'xmit_wait':
			return array(FABRIC_DERIVE, '1000000000000');
		default:
			return array(FABRIC_DERIVE, '1000000');
	}
}

function fabric_save($table, array $row) {
	$id = (int) db_fetch_cell_prepared("SELECT id FROM `$table` WHERE hash = ?", array($row['hash']));

	$row['id'] = $id > 0 ? $id : 0;
	$saved     = sql_save($row, $table);

	if (!$saved) {
		fwrite(STDERR, "Saving $table {$row['hash']} failed\n");
		exit(1);
	}

	return (int) $saved;
}

function fabric_color($hex) {
	$hex = strtoupper($hex);
	$id  = (int) db_fetch_cell_prepared('SELECT id FROM colors WHERE hex = ?', array($hex));

	if ($id === 0) {
		db_execute_prepared('INSERT INTO colors (hex) VALUES (?)', array($hex));
		$id = (int) db_fetch_cell_prepared('SELECT id FROM colors WHERE hex = ?', array($hex));
	}

	return $id;
}

function fabric_cdef($name, $rpn) {
	$id = fabric_save('cdef', array('hash' => T::hash('cdef', $name), 'name' => $name, 'system' => 0));

	db_execute_prepared('DELETE FROM cdef_items WHERE cdef_id = ?', array($id));
	fabric_save('cdef_items', array('hash' => T::hash('cdef_item', $name . ':1'), 'cdef_id' => $id, 'sequence' => 1, 'type' => 6, 'value' => $rpn));

	return $id;
}

function fabric_gprint($name, $text) {
	return fabric_save('graph_templates_gprint', array('hash' => T::hash('gprint', $name), 'name' => $name, 'gprint_text' => $text));
}

/**
 * A data template on the indexed script server input, one RRD item per
 * data source name.
 */
function fabric_data_template($name, $inputId, $title, array $names, array $indexFields) {
	$dtId  = fabric_save('data_template', array('hash' => T::hash('data_template', $name), 'name' => $name));
	$dtdId = (int) db_fetch_cell_prepared('SELECT id FROM data_template_data WHERE data_template_id = ? AND local_data_id = 0', array($dtId));
	$dtdId = (int) sql_save(array(
		'id'                          => $dtdId,
		'data_template_id'            => $dtId,
		'local_data_id'               => 0,
		'local_data_template_data_id' => 0,
		'data_input_id'               => $inputId,
		'name'                        => $title,
		'active'                      => 'on',
		'rrd_step'                    => 300,
		'data_source_profile_id'      => 1,
	), 'data_template_data');

	// The index fields are set per data source when Cacti creates one from
	// the data query.
	foreach ($indexFields as $fieldId) {
		db_execute_prepared('REPLACE INTO data_input_data (data_input_field_id, data_template_data_id, t_value, value) VALUES (?, ?, ?, ?)', array($fieldId, $dtdId, 'on', ''));
	}

	$rrd = array();

	foreach ($names as $item) {
		$spec       = fabric_ds_spec($item);
		$rrd[$item] = fabric_save('data_template_rrd', array(
			'hash'                => T::hash('data_template_item', $name . ':' . $item),
			'data_template_id'    => $dtId,
			'local_data_id'       => 0,
			'rrd_maximum'         => $spec[1],
			'rrd_minimum'         => '0',
			'rrd_heartbeat'       => 600,
			'data_source_type_id' => $spec[0],
			'data_source_name'    => $item,
			'data_input_field_id' => 0,
		));
	}

	return array('id' => $dtId, 'rrd' => $rrd);
}

/**
 * A graph template from a list of drawn items. Each item may carry legends:
 * a list of [GPRINT type, label] printed after it; the last legend ends the
 * line.
 */
function fabric_graph_template($name, $title, $label, $base, array $items) {
	$gtId  = fabric_save('graph_templates', array('hash' => T::hash('graph_template', $name), 'name' => $name));
	$gtgId = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates_graph WHERE graph_template_id = ? AND local_graph_id = 0', array($gtId));

	sql_save(array(
		'id'                            => $gtgId,
		'graph_template_id'             => $gtId,
		'local_graph_id'                => 0,
		'local_graph_template_graph_id' => 0,
		'image_format_id'               => 1,
		't_title'                       => '',
		'title'                         => $title,
		'height'                        => 150,
		'width'                         => 600,
		'upper_limit'                   => '100',
		'lower_limit'                   => '0',
		'vertical_label'                => $label,
		'auto_scale'                    => 'on',
		'auto_scale_opts'               => 2,
		'auto_scale_rigid'              => '',
		'base_value'                    => $base,
		'slope_mode'                    => 'on',
	), 'graph_templates_graph');

	db_execute_prepared('DELETE FROM graph_templates_item WHERE graph_template_id = ? AND local_graph_id = 0', array($gtId));

	$sequence = 0;
	$first    = 0;

	$add = function (array $row) use (&$sequence, $gtId, $name) {
		$sequence++;

		return fabric_save('graph_templates_item', $row + array(
			'hash'                      => T::hash('graph_template_item', $name . ':' . $sequence),
			'graph_template_id'         => $gtId,
			'local_graph_id'            => 0,
			'task_item_id'              => 0,
			'color_id'                  => 0,
			'alpha'                     => 'FF',
			'graph_type_id'             => GRAPH_ITEM_TYPE_COMMENT,
			'cdef_id'                   => 0,
			'consolidation_function_id' => 1,
			'text_format'               => '',
			'value'                     => '',
			'hard_return'               => '',
			'gprint_id'                 => 0,
			'sequence'                  => $sequence,
		));
	};

	$gprints = array(GRAPH_ITEM_TYPE_GPRINT_LAST, GRAPH_ITEM_TYPE_GPRINT_AVERAGE, GRAPH_ITEM_TYPE_GPRINT_MAX, GRAPH_ITEM_TYPE_GPRINT_MIN);

	foreach ($items as $item) {
		$legends = isset($item['legends']) ? $item['legends'] : array();
		$id      = $add(array(
			'task_item_id'  => $item['rrd'],
			'graph_type_id' => $item['type'],
			'color_id'      => isset($item['color']) ? fabric_color($item['color']) : 0,
			'cdef_id'       => isset($item['cdef']) ? $item['cdef'] : 0,
			'text_format'   => $item['text'],
			'gprint_id'     => in_array($item['type'], $gprints, true) ? $item['gprint'] : 0,
			'hard_return'   => $legends === array() ? 'on' : '',
		));

		$first = $first === 0 && !in_array($item['type'], $gprints, true) ? $id : $first;

		foreach ($legends as $i => $legend) {
			$add(array(
				'task_item_id'  => $item['rrd'],
				'graph_type_id' => $legend[0],
				'cdef_id'       => isset($item['cdef']) ? $item['cdef'] : 0,
				'text_format'   => $legend[1],
				'gprint_id'     => $item['gprint'],
				'hard_return'   => $i === count($legends) - 1 ? 'on' : '',
			));
		}
	}

	// One graph template input, for the first item's color: Cacti 1.2.32
	// and later refuse a graph template export with no <inputs> element.
	$inputId = fabric_save('graph_template_input', array(
		'hash'              => T::hash('graph_template_input', $name . ':color'),
		'graph_template_id' => $gtId,
		'name'              => 'First Item Color',
		'description'       => 'The color of the first item',
		'column_name'       => 'color_id',
	));

	db_execute_prepared('DELETE FROM graph_template_input_defs WHERE graph_template_input_id = ?', array($inputId));
	db_execute_prepared('INSERT INTO graph_template_input_defs (graph_template_input_id, graph_template_item_id) VALUES (?, ?)', array($inputId, $first));

	return $gtId;
}

$words = fabric_cdef('Fabric Words to Bytes', 'CURRENT_DATA_SOURCE,4,*');
$gNum  = fabric_gprint('Fabric Number', '%8.2lf %s');
$gInt  = fabric_gprint('Fabric Integer', '%5.0lf');

$stats = array(array(GRAPH_ITEM_TYPE_GPRINT_LAST, 'Current:'), array(GRAPH_ITEM_TYPE_GPRINT_AVERAGE, 'Average:'), array(GRAPH_ITEM_TYPE_GPRINT_MAX, 'Maximum:'));
$now   = array(array(GRAPH_ITEM_TYPE_GPRINT_LAST, 'Current:'));

$indexedInput  = (int) db_fetch_cell_prepared('SELECT id FROM data_input WHERE hash = ?', array(FABRIC_INDEXED_INPUT));
$indexedFields = array();

foreach (FABRIC_INDEXED_FIELDS as $hash) {
	$indexedFields[] = (int) db_fetch_cell_prepared('SELECT id FROM data_input_fields WHERE hash = ?', array($hash));
}

if ($indexedInput === 0 || in_array(0, $indexedFields, true)) {
	fwrite(STDERR, "Cacti's script server indexed input is missing\n");
	exit(1);
}

$suffix = array(V::TRAFFIC => 'Traffic', V::ERRORS => 'Errors', V::CONGESTION => 'Congestion', V::STATE => 'State', V::RATES => 'Alert Rates');
$dts    = array();

foreach (V::templates() as $name => $names) {
	$dts[$name] = fabric_data_template($name, $indexedInput, '|host_description| - |query_fabricPort| - ' . $suffix[$name], $names, $indexedFields);
}

$t = $dts[V::TRAFFIC]['rrd'];
$e = $dts[V::ERRORS]['rrd'];
$c = $dts[V::CONGESTION]['rrd'];
$s = $dts[V::STATE]['rrd'];
$r = $dts[V::RATES]['rrd'];

$palette = array('C0392B', 'E67E22', 'F1C40F', '27AE60', '16A085', '2980B9', '8E44AD', '7F8C8D', '2C3E50');
$avg     = array(array(GRAPH_ITEM_TYPE_GPRINT_AVERAGE, 'Average:'), array(GRAPH_ITEM_TYPE_GPRINT_MAX, 'Maximum:'));

$errorText = array(
	'symbol_err'       => 'Symbol errors',
	'link_err_recov'   => 'Link error recoveries',
	'link_downed'      => 'Link downed events',
	'rcv_err'          => 'Receive errors',
	'rcv_remote_phys'  => 'Receive remote physical errors',
	'rcv_switch_relay' => 'Receive switch relay errors',
	'local_link_integ' => 'Local link integrity errors',
	'buffer_overrun'   => 'Excessive buffer overruns',
	'vl15_dropped'     => 'VL15 packets dropped',
);

$errorItems = array();
$i          = 0;

foreach ($errorText as $name => $text) {
	$errorItems[] = array('rrd' => $e[$name], 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => $palette[$i++ % count($palette)], 'text' => $text, 'gprint' => $gNum, 'legends' => $avg);
}

$graphs = array(
	V::TRAFFIC => fabric_graph_template(V::TRAFFIC, '|host_description| - |query_fabricPort| - Traffic (bytes per second)', 'bytes per second', 1000, array(
		array('rrd' => $t['xmit_data'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => '2471A3', 'cdef' => $words, 'text' => 'Transmitted, data counter times 4 octets', 'gprint' => $gNum, 'legends' => $stats),
		array('rrd' => $t['rcv_data'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => '1E8449', 'cdef' => $words, 'text' => 'Received, data counter times 4 octets', 'gprint' => $gNum, 'legends' => $stats),
		array('rrd' => $t['xmit_pkts'], 'type' => GRAPH_ITEM_TYPE_GPRINT_AVERAGE, 'text' => 'Packets transmitted per second, average:', 'gprint' => $gNum),
		array('rrd' => $t['rcv_pkts'], 'type' => GRAPH_ITEM_TYPE_GPRINT_AVERAGE, 'text' => 'Packets received per second, average:', 'gprint' => $gNum),
	)),
	V::ERRORS => fabric_graph_template(V::ERRORS, '|host_description| - |query_fabricPort| - Link and Receive Errors (per second)', 'errors per second', 1000, $errorItems),
	V::CONGESTION => fabric_graph_template(V::CONGESTION, '|host_description| - |query_fabricPort| - Congestion and Transmit Discards (per second)', 'ticks or packets per second', 1000, array(
		array('rrd' => $c['xmit_wait'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => 'C0392B', 'text' => 'Transmit wait, ticks with data queued but no credit or arbitration', 'gprint' => $gNum, 'legends' => $avg),
		array('rrd' => $c['xmit_discards'], 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => '7D3C98', 'text' => 'Transmit discards, packets', 'gprint' => $gNum, 'legends' => $avg),
		array('rrd' => $c['xmit_constraint'], 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => 'B9770E', 'text' => 'Transmit constraint errors, packets', 'gprint' => $gNum, 'legends' => $avg),
		array('rrd' => $c['rcv_constraint'], 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => '117A65', 'text' => 'Receive constraint errors, packets', 'gprint' => $gNum, 'legends' => $avg),
	)),
	V::STATE => fabric_graph_template(V::STATE, '|host_description| - |query_fabricPort| - Link State', 'lanes, rank, or state', 1000, array(
		array('rrd' => $s['active_lanes'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => '2471A3', 'text' => 'Active lanes', 'gprint' => $gInt, 'legends' => $now),
		array('rrd' => $s['speed_rank'], 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => '1E8449', 'text' => 'Active speed rank, 1 SDR to 9 XDR', 'gprint' => $gInt, 'legends' => $now),
		array('rrd' => $s['degraded'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => 'C0392B', 'text' => 'Degraded, 1 when below best and not explained', 'gprint' => $gInt, 'legends' => $now),
		array('rrd' => $s['port_state'], 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => '7F8C8D', 'text' => 'Logical state, 1 Down to 4 Active', 'gprint' => $gInt, 'legends' => $now),
		array('rrd' => $s['age'], 'type' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text' => 'Seconds since the last sample:', 'gprint' => $gInt),
	)),
	V::RATES => fabric_graph_template(V::RATES, '|host_description| - |query_fabricPort| - Alert Rates (per second)', 'per second', 1000, array(
		array('rrd' => $r['symbol_err_rate'], 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => 'C0392B', 'text' => 'Symbol errors', 'gprint' => $gNum, 'legends' => $avg),
		array('rrd' => $r['rcv_err_rate'], 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => 'E67E22', 'text' => 'Receive errors', 'gprint' => $gNum, 'legends' => $avg),
		array('rrd' => $r['link_downed_rate'], 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => '8E44AD', 'text' => 'Link downed events', 'gprint' => $gNum, 'legends' => $avg),
		array('rrd' => $r['xmit_discard_rate'], 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => '2980B9', 'text' => 'Transmit discards', 'gprint' => $gNum, 'legends' => $avg),
		array('rrd' => $r['xmit_wait_rate'], 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => '7F8C8D', 'text' => 'Transmit wait ticks', 'gprint' => $gNum, 'legends' => $avg),
	)),
);

$queryId = fabric_save('snmp_query', array(
	'hash'          => T::hash('data_query', T::QUERY),
	'xml_path'      => '<path_cacti>/resource/script_server/' . T::QUERY_FILE,
	'name'          => T::QUERY,
	'description'   => 'InfiniBand ports of the fabric nodes mapped to a device, from the Fabric plugin cache',
	'data_input_id' => $indexedInput,
));

foreach ($graphs as $name => $gtId) {
	$sqgId = fabric_save('snmp_query_graph', array(
		'hash'              => T::hash('data_query_graph', T::QUERY . ':' . $name),
		'snmp_query_id'     => $queryId,
		'name'              => $suffix[$name],
		'graph_template_id' => $gtId,
	));

	$template = $dts[$name];

	// Every item of the graph's data template maps to the query field of
	// the same name.
	db_execute_prepared('DELETE FROM snmp_query_graph_rrd WHERE snmp_query_graph_id = ?', array($sqgId));

	foreach ($template['rrd'] as $item => $rrdId) {
		db_execute_prepared('INSERT INTO snmp_query_graph_rrd (snmp_query_graph_id, data_template_id, data_template_rrd_id, snmp_field_name) VALUES (?, ?, ?, ?)', array($sqgId, $template['id'], $rrdId, $item));
	}

	fabric_save('snmp_query_graph_sv', array(
		'hash'                => T::hash('data_query_sv_graph', T::QUERY . ':' . $name . ':title'),
		'snmp_query_graph_id' => $sqgId,
		'sequence'            => 1,
		'field_name'          => 'title',
		'text'                => '|host_description| - |query_fabricPort| - ' . $suffix[$name],
	));

	fabric_save('snmp_query_graph_rrd_sv', array(
		'hash'                => T::hash('data_query_sv_data_source', T::QUERY . ':' . $name . ':name'),
		'snmp_query_graph_id' => $sqgId,
		'data_template_id'    => $template['id'],
		'sequence'            => 1,
		'field_name'          => 'name',
		'text'                => '|host_description| - |query_fabricPort| - ' . $suffix[$name],
	));
}

$htId = fabric_save('host_template', array('hash' => T::hash('host_template', T::DEVICE_TEMPLATE), 'name' => T::DEVICE_TEMPLATE));

db_execute_prepared('DELETE FROM host_template_graph WHERE host_template_id = ?', array($htId));
db_execute_prepared('DELETE FROM host_template_snmp_query WHERE host_template_id = ?', array($htId));
db_execute_prepared('INSERT INTO host_template_snmp_query (host_template_id, snmp_query_id) VALUES (?, ?)', array($htId, $queryId));

$xml = get_item_xml('host_template', $htId, true);

// The export follows the data source profile the data templates use. The
// plugin must not overwrite a site's profile, so the shipped file leaves it
// out and import resolves the profile by its hash, which is Cacti's stock one.
$xml = preg_replace('#\s*<hash_2001[0-9a-f]{34}>.*?</hash_2001[0-9a-f]{34}>#s', '', $xml, -1, $removed);

if ($removed !== 1) {
	fwrite(STDERR, "Expected one data source profile in the export, found $removed\n");
	exit(1);
}

$file = isset($argv[1]) ? $argv[1] : $config['base_path'] . '/plugins/fabric/templates/' . T::TEMPLATE_FILE;

file_put_contents($file, $xml);
print "Wrote $file\n";
