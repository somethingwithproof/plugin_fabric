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
use Somethingwithproof\Fabric\CactiObjectRepository;
use Somethingwithproof\Fabric\ConfigException;
use Somethingwithproof\Fabric\CredentialError;
use Somethingwithproof\Fabric\CredentialRepository;
use Somethingwithproof\Fabric\DeviceAccess;
use Somethingwithproof\Fabric\DeviceProvisioner;
use Somethingwithproof\Fabric\Fabric;
use Somethingwithproof\Fabric\FabricRepository;
use Somethingwithproof\Fabric\FixedClock;
use Somethingwithproof\Fabric\Guid;
use Somethingwithproof\Fabric\IdentityState;
use Somethingwithproof\Fabric\Node;
use Somethingwithproof\Fabric\NodeRepository;
use Somethingwithproof\Fabric\NodeType;
use Somethingwithproof\Fabric\Presence;
use Somethingwithproof\Fabric\RelinkRepository;
use Somethingwithproof\Fabric\Secret;
use Somethingwithproof\Fabric\Settings;
use Somethingwithproof\Fabric\UfmEndpoint;
use Somethingwithproof\Fabric\UfmHttpResponse;
use Somethingwithproof\Fabric\UfmHttpTransport;
use Somethingwithproof\Fabric\UfmSocketTransport;
use Somethingwithproof\Fabric\UfmSweep;
use Somethingwithproof\Fabric\UfmTransportError;
use Somethingwithproof\Fabric\Vault;
use Somethingwithproof\Fabric\VaultKey;
use Somethingwithproof\Fabric\YamlReader;

beforeEach(function () {
	$GLOBALS['__fabric_db_exec']      = [];
	$GLOBALS['__fabric_db_rows']      = [];
	$GLOBALS['__fabric_db_fail']      = [];
	$GLOBALS['__fabric_log']          = [];
	$GLOBALS['__fabric_in_tx']        = [];
	$GLOBALS['__fabric_insert_id']    = 0;
	$GLOBALS['__fabric_affected_rows'] = 1;
});

it('runs UfmSweep across happy path, ifDue skip, claim loss, transport/parse/credential errors', function () {
	$now    = 1800000000;
	$clock  = new FixedClock($now);
	$key    = VaultKey::fromBytes(random_bytes(32));
	$vault  = new Vault($key);
	$entry  = $vault->encrypt(new Secret('ufm-api-tok'), 3, CredentialRepository::PURPOSE);
	$fabric = new Fabric(1, 'ufm0', Backend::Ufm, 1, 300, '', 0, AdminState::Enabled, 'https://ufm.example.org', '', '', 3);

	$baseRows = [
		'FROM plugin_fabric_fabric' => [[
			'id' => 1, 'name' => 'ufm0', 'backend' => 'ufm', 'poller_id' => 1, 'sweep_interval' => 300,
			'adapter' => '', 'adapter_port' => 0, 'admin_state' => 'enabled',
			'ufm_url' => 'https://ufm.example.org', 'ufm_ca_file' => '', 'ufm_spki_pin' => '', 'ufm_credential_id' => 3,
		]],
		'FROM plugin_fabric_secret' => [[
			'id' => 3, 'name' => 'ufm-cred', 'auth_type' => 'token', 'username' => '',
			'nonce' => $entry->nonce, 'ciphertext' => $entry->ciphertext, 'tag' => $entry->tag, 'key_id' => $entry->keyId,
		]],
		'FROM plugin_fabric_claim' => [['c' => 1]],
		'FROM plugin_fabric_node'  => [[
			'id' => 10, 'fabric_id' => 1, 'guid' => '0x0002c90300a00000', 'node_type' => 'switch',
			'port_count' => 2, 'device_id' => 1, 'vendor_id' => 2, 'sysimg_guid' => '0x0002c90300a00000',
			'description' => 'sw01', 'lid' => 5, 'host_id' => 0, 'presence' => 'present', 'identity' => 'ok', 'revision' => 1,
		]],
		'FROM plugin_fabric_port'   => [],
		'FROM plugin_fabric_sample' => [],
	];

	$stub = new class implements UfmHttpTransport {
		public string $mode = 'ok';

		public function get(UfmEndpoint $endpoint, string $path, array $query, array $headers, float $deadline, ?string $caFile = null, ?string $spkiPin = null): UfmHttpResponse {
			if ($this->mode === 'transport_err') {
				throw new UfmTransportError('timeout', 'timed out');
			}

			if ($this->mode === 'parse_err') {
				return new UfmHttpResponse(200, [], '{"data": "not-a-list"}');
			}

			if (str_ends_with($path, '/resources/ports')) {
				return new UfmHttpResponse(200, [], (string) json_encode([
					'data' => [[
						'systemID' => '0002c90300a00000', 'guid' => '0002c90300a00000', 'system_type' => 'switch',
						'system_name' => 'sw01', 'number' => 1, 'lid' => 5, 'logical_state' => 'Active',
						'physical_state' => 'LinkUp', 'active_width' => '4x', 'active_speed' => 'EDR',
						'enabled_width' => ['4x'], 'enabled_speed' => ['EDR'],
						'peer_node_guid' => '0002c90300b00010', 'peer' => '0002c90300b00010_1',
					]],
				]));
			}

			if ($this->mode === 'counters_err') {
				throw new UfmTransportError('unreachable', 'session failed');
			}

			return new UfmHttpResponse(200, [], (string) json_encode([
				'2026-11-27 00:00:00' => [
					'Ports' => [
						'0002c90300a00000_1' => [
							'statistics' => [
								'Infiniband_MBOut'    => 1000,
								'Infiniband_XmitWait' => 5,
							],
						],
					],
				],
			]));
		}
	};

	$GLOBALS['__fabric_db_rows'] = $baseRows;
	$sweep                       = new UfmSweep(false, $clock, Settings::fromRow([]), $stub, $vault);
	expect($sweep->run($fabric, 'holder-1', false))->toBe(1);

	// ifDue skips when discovery_attempt is recent.
	$GLOBALS['__fabric_db_rows'] = $baseRows + ['FROM plugin_fabric_health' => [['discovery_attempt' => $now - 10]]];
	expect($sweep->run($fabric, 'holder-1', true))->toBe(0);

	// Transport, parse, and counter errors return 0 cleanly.
	$GLOBALS['__fabric_db_rows'] = $baseRows;

	foreach (['transport_err', 'parse_err', 'counters_err'] as $mode) {
		$stub->mode = $mode;
		expect($sweep->run($fabric, 'holder-1', false))->toBe(0);
	}

	// Missing URL triggers CredentialError handling cleanly.
	$badFabric = new Fabric(1, 'ufm0', Backend::Ufm, 1, 300, '', 0, AdminState::Enabled, '', '', '', 3);
	expect($sweep->run($badFabric, 'holder-1', false))->toBe(0);

	// Claim acquisition refused returns null.
	$GLOBALS['__fabric_db_rows']['FROM plugin_fabric_claim'] = [['c' => 0]];
	expect($sweep->run($fabric, 'holder-1', false))->toBeNull();
});

it('exercises CredentialRepository, FabricRepository, RelinkRepository, CactiObjectRepository, and DeviceProvisioner', function () {
	$now   = 1800000000;
	$clock = new FixedClock($now);
	$key   = VaultKey::fromBytes(random_bytes(32));
	$vault = new Vault($key);
	$entry = $vault->encrypt(new Secret('tok-xyz'), 5, CredentialRepository::PURPOSE);

	// CredentialRepository CRUD + errors.
	$creds                       = new CredentialRepository(false, $clock);
	$GLOBALS['__fabric_db_rows'] = [
		'SELECT id, name, auth_type, username, key_id' => [['id' => 5, 'name' => 'c1', 'auth_type' => 'token', 'username' => '', 'key_id' => $entry->keyId, 'updated_at' => $now]],
		'SELECT id, name, auth_type, username, nonce'  => [['id' => 5, 'name' => 'c1', 'auth_type' => 'token', 'username' => '', 'nonce' => $entry->nonce, 'ciphertext' => $entry->ciphertext, 'tag' => $entry->tag, 'key_id' => $entry->keyId]],
		'SELECT id FROM plugin_fabric_secret'          => [['id' => 5]],
	];

	expect($creds->listMetadata())->toHaveCount(1)
		->and($creds->find(0))->toBeNull()
		->and($creds->find(5))->not->toBeNull()
		->and($creds->findIdByName(''))->toBe(0)
		->and($creds->findIdByName('c1'))->toBe(5)
		->and($creds->decrypt(5, $vault)['secret']->reveal())->toBe('tok-xyz')
		->and($creds->save(0, 'newcred', CredentialRepository::AUTH_TOKEN, '', new Secret('abc'), $vault))->toBeGreaterThan(0)
		->and($creds->save(5, 'c1', CredentialRepository::AUTH_BASIC, 'admin', new Secret('pw'), $vault))->toBe(5)
		->and($creds->delete(0))->toBeFalse()
		->and($creds->delete(5))->toBeTrue();

	expect(fn () => $creds->save(0, 'c1', 'oauth', '', new Secret('x'), $vault))->toThrow(CredentialError::class)
		->and(fn () => $creds->save(0, 'bad name!', CredentialRepository::AUTH_TOKEN, '', new Secret('x'), $vault))->toThrow(CredentialError::class)
		->and(fn () => $creds->decrypt(0, $vault))->toThrow(CredentialError::class);

	// FabricRepository save, dueForPoller, nameTaken, portTaken, deleteCounts, delete.
	$fabrics                     = new FabricRepository(false, $clock);
	$f                           = new Fabric(0, 'ib0', Backend::Diags, 1, 300, 'mlx5_0', 1, AdminState::Enabled);
	$fExisting                   = new Fabric(1, 'ib0', Backend::Ufm, 1, 300, '', 0, AdminState::Enabled, 'https://ufm.example.org');
	$GLOBALS['__fabric_db_rows'] = [
		'FROM plugin_fabric_fabric WHERE poller_id' => [['id' => 1, 'name' => 'ib0', 'backend' => 'diags', 'poller_id' => 1, 'sweep_interval' => 300, 'adapter' => 'mlx5_0', 'adapter_port' => 1, 'admin_state' => 'enabled']],
		'FROM plugin_fabric_fabric WHERE id = ? FOR UPDATE' => [['c' => 1]],
		'FROM plugin_fabric_fabric WHERE id = ?'    => [['id' => 1, 'name' => 'ib0', 'backend' => 'diags', 'poller_id' => 1, 'sweep_interval' => 300, 'adapter' => 'mlx5_0', 'adapter_port' => 1, 'admin_state' => 'enabled']],
		'FROM plugin_fabric_claim'                  => [['c' => 0]],
		'FROM plugin_fabric_health'                 => [['discovery_attempt' => 0]],
	];

	expect($fabrics->dueForPoller(1))->toBe([1])
		->and($fabrics->save($f))->toBeGreaterThan(0)
		->and($fabrics->save($fExisting))->toBe(1)
		->and($fabrics->portTaken($fExisting))->toBeFalse()
		->and($fabrics->portTaken($f))->toBeTrue()
		->and($fabrics->nameTaken('ib0', 0))->toBeFalse()
		->and($fabrics->deleteCounts(1))->toBe(['nodes' => 0, 'mapped' => 0])
		->and($fabrics->delete(1))->toBeTrue();

	// CactiObjectRepository lookups.
	$objects                     = new CactiObjectRepository(false);
	$GLOBALS['__fabric_db_rows'] = [
		'FROM `host_template`'     => [['id' => 5]],
		'FROM `snmp_query`'        => [['id' => 9]],
		'FROM data_source_profiles' => [['id' => 2]],
		'FROM host WHERE id = ?'   => [['id' => 42, 'description' => 'sw01', 'hostname' => 'sw01.local', 'poller_id' => 1, 'host_template_id' => 5]],
		'FROM host_snmp_query'     => [['c' => 1]],
		"FROM host WHERE deleted = ''" => [['id' => 42, 'description' => 'sw01']],
	];

	expect($objects->idByHash('bad_table', str_repeat('a', 32)))->toBe(0)
		->and($objects->deviceTemplateId())->toBe(5)
		->and($objects->queryId())->toBe(9)
		->and($objects->defaultProfileId())->toBe(2)
		->and($objects->device(0))->toBeNull()
		->and($objects->device(42)['hostname'] ?? '')->toBe('sw01.local')
		->and($objects->hasQuery(42))->toBeTrue()
		->and($objects->suggestions('ab'))->toBe([])
		->and($objects->suggestions('sw01 HCA-1'))->toBe([42 => 'sw01']);

	// DeviceProvisioner refusals, plan, description, hostname.
	$nodes       = new NodeRepository(false);
	$access      = new DeviceAccess(static fn (int $h): bool => true);
	$provisioner = new DeviceProvisioner($nodes, $fabrics, $objects, $access, '/var/www/cacti');
	$node        = new Node(10, 1, Guid::fromString('0x0002c90300a00000'), NodeType::Switch, 36, 1, 2, null, null, 'sw01', 5, 0, Presence::Present, IdentityState::Ok, null, 0, 0, 1);

	$GLOBALS['__fabric_db_rows']['FROM plugin_fabric_fabric WHERE id = ?'] = [[
		'id' => 1, 'name' => 'ib0', 'backend' => 'diags', 'poller_id' => 1,
		'sweep_interval' => 300, 'adapter' => 'mlx5_0', 'adapter_port' => 1, 'admin_state' => 'enabled',
	]];

	expect($provisioner->createRefusal($node))->toBeNull()
		->and($provisioner->mapRefusal($node, 42))->toBeNull()
		->and($provisioner->mapRefusal($node, 0))->toContain('does not exist')
		->and($provisioner->plan($node))->toHaveCount(2)
		->and($provisioner->description($node))->toContain('sw01')
		->and($provisioner->hostname($node))->toBe('0002c90300a00000.fabric.invalid');

	// RelinkRepository find & reject.
	$relinks                     = new RelinkRepository(false, $clock);
	$GLOBALS['__fabric_db_rows'] = [
		'FROM plugin_fabric_relink r' => [['id' => 7, 'state' => 'proposed', 'old_node_id' => 10, 'new_node_id' => 11, 'old_host_id' => 42, 'new_host_id' => 0]],
	];
	expect($relinks->find(7))->not->toBeNull()
		->and($relinks->reject(7))->toBeTrue();
});

it('rejects malformed YAML constructs across YamlReader validation branches', function (string $yaml) {
	expect(fn () => YamlReader::parse($yaml))->toThrow(ConfigException::class);
})->with([
	'bare carriage return'   => ["a: 1\rb: 2"],
	'control character'      => ["a: \x01bad"],
	'tab indentation'        => ["a:\n\tb: 1"],
	'second document'        => ["a: 1\n---\nb: 2"],
	'yaml directive'         => ["%YAML 1.2\na: 1"],
	'unexpected indentation' => ["a: 1\n  b: 2"],
	'list where map expected' => ["a: 1\n- item"],
	'missing colon'          => ['not a mapping'],
	'duplicate key'          => ["a: 1\na: 2"],
	'anchor'                 => ['a: &ref 1'],
	'flow list'              => ['a: [1, 2]'],
	'block scalar'           => ["a: |\n  hello"],
	'reserved scalar start'  => ['a: @bad'],
	'empty key'              => [': value'],
	'merge key'              => ['<<: value'],
	'unquoted colon in val'  => ['a: b: c'],
	'ambiguous yaml 1.1'     => ['a: yes'],
	'trailing after quote'   => ['a: "ok" extra'],
	'unterminated quote'     => ['a: "unterminated'],
	'bad escape'             => ['a: "\q"'],
	'nested inline list'     => ['- - item'],
]);

it('validates UfmSocketTransport inputs, SPKI verification, and HTTP framing helpers', function () {
	$transport = new UfmSocketTransport(1024, 1.0);
	$endpoint  = UfmEndpoint::parse('https://ufm.example.org');
	assert($endpoint !== null);

	// Reject bad path, bad SPKI pin, missing CA file, bad query, bad header, expired deadline.
	expect(fn () => $transport->get($endpoint, '/bad path', [], [], microtime(true) + 5.0))->toThrow(UfmTransportError::class)
		->and(fn () => $transport->get($endpoint, '/ufmRest/ok', [], [], microtime(true) + 5.0, null, 'bad-pin'))->toThrow(UfmTransportError::class)
		->and(fn () => $transport->get($endpoint, '/ufmRest/ok', [], [], microtime(true) + 5.0, '/nonexistent/ca.pem'))->toThrow(UfmTransportError::class)
		->and(fn () => $transport->get($endpoint, '/ufmRest/ok', ['bad-key!' => '1'], [], microtime(true) + 5.0))->toThrow(UfmTransportError::class)
		->and(fn () => $transport->get($endpoint, '/ufmRest/ok', [], ['X-Test' => "bad\r\nInject: 1"], microtime(true) + 5.0))->toThrow(UfmTransportError::class)
		->and(fn () => $transport->get($endpoint, '/ufmRest/ok', [], [], microtime(true) - 1.0))->toThrow(UfmTransportError::class);

	// Verify real OpenSSL SPKI pin matching and mismatch.
	$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

	if ($key !== false) {
		$csr  = openssl_csr_new(['commonName' => 'ufm.test'], $key);
		$cert = $csr !== false ? openssl_csr_sign($csr, null, $key, 1) : false;

		if ($cert instanceof \OpenSSLCertificate) {
			$details = openssl_pkey_get_details($key);
			assert(is_array($details) && is_string($details['key']));
			$pem    = (string) preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $details['key']);
			$digest = hash('sha256', (string) base64_decode($pem, true), true);

			expect(UfmSocketTransport::verifySpkiPin($cert, $digest))->toBeTrue()
				->and(UfmSocketTransport::verifySpkiPin($cert, str_repeat("\x00", 32)))->toBeFalse();
		}
	}

	// Verify parseHead, dechunk, and classify via reflection on pure private helpers.
	$parseHead = new ReflectionMethod(UfmSocketTransport::class, 'parseHead');
	$dechunk   = new ReflectionMethod(UfmSocketTransport::class, 'dechunk');
	$classify  = new ReflectionMethod(UfmSocketTransport::class, 'classify');

	expect($parseHead->invoke(null, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 2"))->toBe([200, ['content-type' => 'application/json', 'content-length' => '2']])
		->and(fn () => $parseHead->invoke(null, 'GARBAGE'))->toThrow(UfmTransportError::class)
		->and(fn () => $parseHead->invoke(null, "HTTP/1.1 200 OK\r\n bad-header"))->toThrow(UfmTransportError::class)
		->and(fn () => $parseHead->invoke(null, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nContent-Length: 5"))->toThrow(UfmTransportError::class)
		->and($dechunk->invoke(null, "4\r\nWiki\r\n5\r\npedia\r\n0\r\nX-Trailer: 1\r\n\r\n"))->toBe('Wikipedia')
		->and(fn () => $dechunk->invoke(null, "ZZ\r\n"))->toThrow(UfmTransportError::class)
		->and(fn () => $dechunk->invoke(null, "4\r\nab"))->toThrow(UfmTransportError::class)
		->and(fn () => $dechunk->invoke(null, "0\r\n"))->toThrow(UfmTransportError::class)
		->and($classify->invoke(null, 'certificate verify failed'))->toBe('tls certificate not trusted')
		->and($classify->invoke(null, 'Peer certificate CN did not match expected'))->toBe('tls name mismatch')
		->and($classify->invoke(null, 'Connection timed out'))->toBe('connect timed out')
		->and($classify->invoke(null, 'Connection refused'))->toBe('connection refused')
		->and($classify->invoke(null, 'other error'))->toBe('connect failed');
});
