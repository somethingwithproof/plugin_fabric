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
 * AES-256-GCM encryption of stored UFM credentials.
 *
 * Each value gets a fresh random 96-bit nonce and authenticated additional
 * data bound to the credential ID and purpose, preventing cross-row replay.
 */
final class Vault {
	public const NONCE_BYTES = 12;
	public const TAG_BYTES   = 16;

	private const CIPHER = 'aes-256-gcm';

	public function __construct(private readonly VaultKey $key) {
	}

	public function encrypt(Secret $secret, int $credentialId, string $purpose): VaultEntry {
		$nonce = random_bytes(self::NONCE_BYTES);
		$tag   = '';

		$ciphertext = openssl_encrypt(
			$secret->reveal(),
			self::CIPHER,
			$this->key->bytes(),
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			self::additionalData($credentialId, $purpose),
			self::TAG_BYTES,
		);

		if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
			throw CredentialError::because('the credential could not be encrypted');
		}

		return new VaultEntry($nonce, $ciphertext, $tag, $this->key->id());
	}

	/**
	 * @throws CredentialError
	 */
	public function decrypt(VaultEntry $entry, int $credentialId, string $purpose): Secret {
		if ($entry->keyId !== $this->key->id()) {
			throw CredentialError::because('the credential was stored with a different vault key; store it again');
		}

		$plain = openssl_decrypt(
			$entry->ciphertext,
			self::CIPHER,
			$this->key->bytes(),
			OPENSSL_RAW_DATA,
			$entry->nonce,
			$entry->tag,
			self::additionalData($credentialId, $purpose),
		);

		if ($plain === false) {
			throw CredentialError::because('the stored credential failed its integrity check');
		}

		return new Secret($plain);
	}

	private static function additionalData(int $credentialId, string $purpose): string {
		return 'plugin_fabric/vault/v1/credential/' . $credentialId . '/' . $purpose;
	}
}
