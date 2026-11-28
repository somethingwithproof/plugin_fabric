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

use Somethingwithproof\Fabric\CredentialError;
use Somethingwithproof\Fabric\CredentialRepository;
use Somethingwithproof\Fabric\LinkCondition;
use Somethingwithproof\Fabric\LinkStateEvaluator;
use Somethingwithproof\Fabric\NodeType;
use Somethingwithproof\Fabric\Secret;
use Somethingwithproof\Fabric\UfmClient;
use Somethingwithproof\Fabric\UfmEndpoint;
use Somethingwithproof\Fabric\UfmHttpResponse;
use Somethingwithproof\Fabric\UfmHttpTransport;
use Somethingwithproof\Fabric\UfmParser;
use Somethingwithproof\Fabric\UfmSocketTransport;
use Somethingwithproof\Fabric\UfmTransportError;
use Somethingwithproof\Fabric\Vault;
use Somethingwithproof\Fabric\VaultKey;

it('encrypts and decrypts UFM secrets with AES-256-GCM and binds AAD to credential ID and purpose', function () {
	$key   = VaultKey::fromBytes(random_bytes(32));
	$vault = new Vault($key);

	$entry = $vault->encrypt(new Secret('ufm-token-value-123'), 7, CredentialRepository::PURPOSE);

	expect(strlen($entry->nonce))->toBe(Vault::NONCE_BYTES)
		->and(strlen($entry->tag))->toBe(Vault::TAG_BYTES)
		->and($entry->keyId)->toBe($key->id())
		->and($vault->decrypt($entry, 7, CredentialRepository::PURPOSE)->reveal())->toBe('ufm-token-value-123');

	// Cross-credential replay must fail GCM authentication.
	expect(fn () => $vault->decrypt($entry, 8, CredentialRepository::PURPOSE))->toThrow(CredentialError::class);

	// Wrong key fails cleanly.
	$other = new Vault(VaultKey::fromBytes(random_bytes(32)));
	expect(fn () => $other->decrypt($entry, 7, CredentialRepository::PURPOSE))->toThrow(CredentialError::class);
});

it('redacts Secret and VaultKey in debug info and refuses serialization', function () {
	$secret = new Secret('super-secret-ufm-token');
	$key    = VaultKey::fromBytes(random_bytes(32));

	expect($secret->__debugInfo())->toBe(['value' => '[redacted]'])
		->and((string) $secret)->toBe('[redacted]')
		->and($key->__debugInfo())->toBe(['key' => '[redacted]'])
		->and(fn () => serialize($secret))->toThrow(LogicException::class)
		->and(fn () => serialize($key))->toThrow(LogicException::class);
});

it('loads 64-hex vault keys from secure keyfiles and rejects 32-char ASCII passphrases', function () {
	$scratch = new Somethingwithproof\Fabric\Tests\Scratch();

	try {
		$dir = (string) realpath($scratch->dir);
		chmod($dir, 0700);
		$scratch->file('vault.key', bin2hex(random_bytes(32)) . "\n", 0600);
		$scratch->file('pass.key', str_repeat('A', 32), 0600);
		$scratch->file('open.key', bin2hex(random_bytes(32)) . "\n", 0644);
		$good = $dir . '/vault.key';
		$pass = $dir . '/pass.key';
		$open = $dir . '/open.key';

		$loaded = VaultKey::fromFile($good);
		expect(strlen($loaded->bytes()))->toBe(32)
			->and(strlen($loaded->id()))->toBe(16);

		expect(fn () => VaultKey::fromFile($pass))->toThrow(CredentialError::class)
			->and(fn () => VaultKey::fromFile($open))->toThrow(CredentialError::class);
	} finally {
		$scratch->remove();
	}
});

it('enforces HTTPS-only UFM endpoints and rejects embedded credentials or query strings', function () {
	$ep = UfmEndpoint::parse('https://ufm.example.org:8443/prefix/');

	expect($ep)->not->toBeNull()
		->and($ep?->socketAddress())->toBe('tls://ufm.example.org:8443')
		->and($ep?->hostHeader())->toBe('ufm.example.org:8443')
		->and($ep?->basePath)->toBe('/prefix')
		->and(UfmEndpoint::parse('http://ufm.example.org'))->toBeNull()
		->and(UfmEndpoint::parse('https://user:pass@ufm.example.org'))->toBeNull()
		->and(UfmEndpoint::parse('https://ufm.example.org/path?x=1'))->toBeNull()
		->and(UfmEndpoint::parse('http://127.0.0.1:8080', true)?->socketAddress())->toBe('tcp://127.0.0.1:8080');
});

it('enforces GET-only allowlisted paths on UfmClient and parses UFM ports and session counters', function () {
	$captured = [];
	$stub     = new class ($captured) implements UfmHttpTransport {
		/** @var array<int, array{path: string, auth: string}> */
		public array $calls = [];

		/** @param array<int, mixed> $unused */
		public function __construct(array &$unused) {
		}

		public function get(UfmEndpoint $endpoint, string $path, array $query, array $headers, float $deadline, ?string $caFile = null, ?string $spkiPin = null): UfmHttpResponse {
			$this->calls[] = ['path' => $path, 'auth' => $headers['Authorization'] ?? ''];

			if (str_ends_with($path, '/resources/ports')) {
				return new UfmHttpResponse(200, [], (string) json_encode([
					'data' => [
						[
							'systemID'       => '0002c90300a00000',
							'guid'           => '0002c90300a00000',
							'system_type'    => 'switch',
							'system_name'    => 'spine-sw-01',
							'number'         => 1,
							'lid'            => 10,
							'logical_state'  => 'Active',
							'physical_state' => 'LinkUp',
							'active_width'   => '1x',
							'active_speed'   => 'EDR',
							'enabled_width'  => ['1x', '2x', '4x'],
							'enabled_speed'  => ['EDR'],
							'peer_node_guid' => '0002c90300b00010',
							'peer'           => '0002c90300b00010_1',
						],
					],
				]));
			}

			return new UfmHttpResponse(200, [], (string) json_encode([
				'2026-11-27 00:00:00' => [
					'Ports' => [
						'0002c90300a00000_1' => [
							'statistics' => [
								'Infiniband_MBOut'        => 123456,
								'Infiniband_MBIn'         => '789012',
								'Infiniband_SymbolErrors' => 2,
								'Infiniband_XmitWait'     => 15,
							],
						],
					],
				],
			]));
		}
	};

	$endpoint = UfmEndpoint::parse('https://ufm.example.org');
	assert($endpoint !== null);

	$client = new UfmClient($stub, $endpoint, CredentialRepository::AUTH_TOKEN, '', new Secret('tok123'));

	// Non-allowlisted path is refused before transport is touched.
	expect(fn () => $client->getJson('/ufmRest/monitoring/start', [], microtime(true) + 10.0))
		->toThrow(UfmTransportError::class);

	$portsPayload    = $client->fetchPorts(microtime(true) + 10.0);
	$countersPayload = $client->fetchSessionCounters(microtime(true) + 10.0);

	expect($stub->calls[0]['path'])->toBe('/ufmRestV3/resources/ports')
		->and($stub->calls[0]['auth'])->toBe('Basic tok123')
		->and($stub->calls[1]['path'])->toBe('/ufmRestV3/monitoring/session/0/data');

	$parser = new UfmParser();
	$parsed = $parser->parsePorts($portsPayload);

	expect($parsed['topology']->nodes)->toHaveKey('0x0002c90300a00000')
		->and($parsed['topology']->nodes['0x0002c90300a00000']->type)->toBe(NodeType::Switch)
		->and($parsed['links']->records)->toHaveCount(1)
		->and((new LinkStateEvaluator())->evaluate($parsed['links']->records[0]->link))->toBe(LinkCondition::Degraded);

	$counters = $parser->parseSessionCounters($countersPayload);
	expect($counters)->toHaveKey('0x0002c90300a00000:1')
		->and($counters['0x0002c90300a00000:1']->values['PortXmitData'])->toBe('123456')
		->and($counters['0x0002c90300a00000:1']->values['PortRcvData'])->toBe('789012')
		->and($counters['0x0002c90300a00000:1']->values['SymbolErrorCounter'])->toBe('2');
});

it('normalizes SPKI SHA-256 pins in base64 and hex forms and rejects invalid pins', function () {
	$raw = random_bytes(32);

	expect(UfmSocketTransport::normalizeSpkiPin('sha256/' . base64_encode($raw)))->toBe($raw)
		->and(UfmSocketTransport::normalizeSpkiPin(bin2hex($raw)))->toBe($raw)
		->and(UfmSocketTransport::normalizeSpkiPin('sha256/not-valid'))->toBeNull()
		->and(UfmSocketTransport::normalizeSpkiPin('short'))->toBeNull();
});
