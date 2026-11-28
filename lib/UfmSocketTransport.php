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
 * Read-only HTTP/1.1 GET transport over stream_socket_client().
 *
 * Enforces TLS peer and hostname verification by default, optional CA bundle
 * validation through SecureFile, optional SHA-256 SPKI pin verification, no
 * redirects, bounded header and body sizes, strict Content-Length framing,
 * and RFC 9112 chunked transfer decoding.
 */
final class UfmSocketTransport implements UfmHttpTransport {
	/** Maximum header bytes. */
	private const MAX_HEADER_BYTES = 65536;
	/** Maximum ca bytes. */
	private const MAX_CA_BYTES     = 1048576;

	/**
	 * Construct a new UfmSocketTransport instance.
	 *
	 * @param int $maxBytes
	 * @param float $connectTimeout
	 */
	public function __construct(
		private readonly int $maxBytes = 8388608,
		private readonly float $connectTimeout = 5.0,
	) {
	}

	/**
	 * Get.
	 *
	 * @param UfmEndpoint $endpoint
	 * @param string $path
	 * @param float $deadline
	 * @param ?string $caFile
	 * @param ?string $spkiPin
	 * @return UfmHttpResponse
	 * @throws UfmTransportError
	 */
	public function get(
		UfmEndpoint $endpoint,
		string $path,
		array $query,
		array $headers,
		float $deadline,
		?string $caFile = null,
		?string $spkiPin = null,
	): UfmHttpResponse {
		if (preg_match('#^(/[A-Za-z0-9._~-]+)+/?$#', $path) !== 1) {
			throw new UfmTransportError('refused', 'invalid request path');
		}

		$normalizedPin = null;

		if ($spkiPin !== null && $spkiPin !== '') {
			$normalizedPin = self::normalizeSpkiPin($spkiPin);

			if ($normalizedPin === null) {
				throw new UfmTransportError('config', 'invalid SPKI pin format');
			}
		}

		if ($caFile !== null && $caFile !== '') {
			try {
				SecureFile::check($caFile, self::MAX_CA_BYTES, false);
			} catch (CredentialError $e) {
				throw new UfmTransportError('config', 'CA bundle rejected: ' . $e->getMessage());
			}
		}

		$queryParts = [];

		foreach ($query as $name => $value) {
			$text = (string) $value;

			if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1 || preg_match('/^[A-Za-z0-9._-]+$/', $text) !== 1) {
				throw new UfmTransportError('refused', 'invalid query parameter');
			}

			$queryParts[] = $name . '=' . $text;
		}

		$target = $endpoint->basePath . $path . ($queryParts === [] ? '' : '?' . implode('&', $queryParts));
		$raw    = 'GET ' . $target . " HTTP/1.1\r\nHost: " . $endpoint->hostHeader() . "\r\nConnection: close\r\nAccept: application/json\r\n";

		foreach ($headers as $name => $value) {
			if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1 || preg_match('/[\r\n\x00]/', $value) === 1) {
				throw new UfmTransportError('refused', 'invalid request header');
			}

			$raw .= $name . ': ' . $value . "\r\n";
		}

		$raw .= "\r\n";

		$ssl = [
			'verify_peer'         => true,
			'verify_peer_name'    => true,
			'allow_self_signed'   => false,
			'peer_name'           => $endpoint->host,
			'SNI_enabled'         => true,
			'disable_compression' => true,
		];

		if ($caFile !== null && $caFile !== '') {
			$ssl['cafile'] = $caFile;
		}

		if ($normalizedPin !== null) {
			$ssl['capture_peer_cert'] = true;
		}

		$ctx           = stream_context_create(['ssl' => $ssl]);
		$connectBudget = min($this->connectTimeout, $deadline - microtime(true));

		if ($connectBudget <= 0) {
			throw new UfmTransportError('timeout', 'deadline exceeded before connect');
		}

		$warnings = [];
		set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
			$warnings[] = $message;

			return true;
		});

		try {
			$fp = stream_socket_client($endpoint->socketAddress(), $errno, $errstr, $connectBudget, STREAM_CLIENT_CONNECT, $ctx);
		} finally {
			restore_error_handler();
		}

		if ($fp === false) {
			$detail = implode(' | ', array_merge($warnings, ["$errno $errstr"]));

			throw new UfmTransportError(str_contains($detail, 'timed out') ? 'timeout' : 'unreachable', self::classify($detail));
		}

		try {
			if ($normalizedPin !== null) {
				$params  = stream_context_get_params($fp);
				$sslOpts = is_array($params['options']['ssl'] ?? null) ? $params['options']['ssl'] : [];
				$cert    = $sslOpts['peer_certificate'] ?? null;

				if (!$cert instanceof \OpenSSLCertificate || !self::verifySpkiPin($cert, $normalizedPin)) {
					throw new UfmTransportError('tls_pin', 'server certificate SPKI pin mismatch');
				}
			}

			for ($off = 0; $off < strlen($raw); $off += $n) {
				$this->limit($fp, $deadline);
				$n = @fwrite($fp, substr($raw, $off, 65536));

				if (stream_get_meta_data($fp)['timed_out']) {
					throw new UfmTransportError('timeout', 'request write timed out');
				}

				if ($n === false || $n === 0) {
					throw new UfmTransportError('unreachable', 'request write failed');
				}
			}

			$buffer    = '';
			$headBytes = 0;

			while (true) {
				$headEnd = strpos($buffer, "\r\n\r\n");

				while ($headEnd === false) {
					if ($headBytes + strlen($buffer) > self::MAX_HEADER_BYTES) {
						throw new UfmTransportError('too_large', 'response headers too large');
					}

					if (feof($fp)) {
						throw new UfmTransportError('malformed', 'connection closed in headers');
					}

					$buffer .= $this->readSome($fp, $deadline);
					$headEnd = strpos($buffer, "\r\n\r\n");
				}

				$headBytes += $headEnd + 4;

				if ($headBytes > self::MAX_HEADER_BYTES) {
					throw new UfmTransportError('too_large', 'response headers too large');
				}

				[$status, $respHeaders] = self::parseHead(substr($buffer, 0, $headEnd));
				$rest                   = substr($buffer, $headEnd + 4);

				if ($status === 101) {
					throw new UfmTransportError('malformed', 'unexpected 101 response');
				}

				if ($status >= 200) {
					break;
				}

				$buffer = $rest;
			}

			if ($status === 204 || $status === 304) {
				return new UfmHttpResponse($status, $respHeaders, '');
			}

			if ($status >= 300 && $status < 400) {
				throw new UfmTransportError('refused', 'redirect refused');
			}

			$chunked = str_contains(strtolower($respHeaders['transfer-encoding'] ?? ''), 'chunked');
			$length  = null;

			if (!$chunked && isset($respHeaders['content-length'])) {
				if (preg_match('/^\d{1,12}$/', $respHeaders['content-length']) !== 1) {
					throw new UfmTransportError('malformed', 'invalid content-length');
				}

				$length = (int) $respHeaders['content-length'];

				if ($length > $this->maxBytes) {
					throw new UfmTransportError('too_large', 'response exceeds size cap');
				}
			}

			while (!feof($fp) && ($length === null || strlen($rest) < $length)) {
				$rest .= $this->readSome($fp, $deadline);

				if (strlen($rest) > $this->maxBytes + ($chunked ? 65536 : 0)) {
					throw new UfmTransportError('too_large', 'response exceeds size cap');
				}
			}

			if ($length !== null && strlen($rest) < $length) {
				throw new UfmTransportError('malformed', 'truncated response body');
			}

			$body = $chunked ? self::dechunk($rest) : ($length === null ? $rest : substr($rest, 0, $length));

			if (strlen($body) > $this->maxBytes) {
				throw new UfmTransportError('too_large', 'response exceeds size cap');
			}

			return new UfmHttpResponse($status, $respHeaders, $body);
		} finally {
			fclose($fp);
		}
	}

	/**
	 * Accepts "sha256/<base64>" or 64-character hex SHA-256 of SubjectPublicKeyInfo DER.
	 * Returns raw 32-byte digest or null if invalid.
	 */
	public static function normalizeSpkiPin(string $pin): ?string {
		$pin = trim($pin);

		if (str_starts_with($pin, 'sha256/')) {
			$decoded = base64_decode(substr($pin, 7), true);

			return is_string($decoded) && strlen($decoded) === 32 ? $decoded : null;
		}

		if (strlen($pin) === 64 && ctype_xdigit($pin)) {
			$bin = hex2bin($pin);

			return is_string($bin) && strlen($bin) === 32 ? $bin : null;
		}

		return null;
	}

	/**
	 * Compute the raw SHA-256 digest of a certificate's SubjectPublicKeyInfo DER.
	 */
	public static function verifySpkiPin(\OpenSSLCertificate $cert, string $expectedDigest): bool {
		$pubKey = openssl_pkey_get_public($cert);

		if ($pubKey === false) {
			return false;
		}

		$details = openssl_pkey_get_details($pubKey);

		if (!is_array($details) || !isset($details['key']) || !is_string($details['key'])) {
			return false;
		}

		$pem = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $details['key']);

		if (!is_string($pem) || $pem === '') {
			return false;
		}

		$der = base64_decode($pem, true);

		if (!is_string($der) || $der === '') {
			return false;
		}

		return hash_equals($expectedDigest, hash('sha256', $der, true));
	}

	/**
	 * @param resource $fp
	 */
	private function limit($fp, float $deadline): void {
		$left = $deadline - microtime(true);

		if ($left <= 0) {
			throw new UfmTransportError('timeout', 'deadline exceeded');
		}

		stream_set_timeout($fp, (int) $left, (int) (fmod($left, 1) * 1e6));
	}

	/**
	 * @param resource $fp
	 */
	private function readSome($fp, float $deadline): string {
		$this->limit($fp, $deadline);
		$chunk = @fread($fp, 8192);

		if (stream_get_meta_data($fp)['timed_out']) {
			throw new UfmTransportError('timeout', 'socket read timed out');
		}

		return $chunk === false ? '' : $chunk;
	}

	/**
	 * @return array{0: int, 1: array<string, string>}
	 */
	private static function parseHead(string $head): array {
		$lines = explode("\r\n", $head);

		if (preg_match('#^HTTP/1\.[01] (\d{3})(?: |$)#', (string) array_shift($lines), $m) !== 1) {
			throw new UfmTransportError('malformed', 'invalid HTTP status line');
		}

		$headers = [];

		foreach ($lines as $line) {
			$pos = strpos($line, ':');

			if ($pos === false || $pos === 0 || $line[0] === ' ' || $line[0] === "\t") {
				throw new UfmTransportError('malformed', 'invalid HTTP header line');
			}

			$name  = strtolower(substr($line, 0, $pos));
			$value = trim(substr($line, $pos + 1), " \t");

			if ($name === 'content-length' && isset($headers[$name]) && $headers[$name] !== $value) {
				throw new UfmTransportError('malformed', 'conflicting content-length');
			}

			$headers[$name] = $value;
		}

		return [(int) $m[1], $headers];
	}

	/**
	 * Dechunk.
	 *
	 * @param string $raw
	 * @return string
	 * @throws UfmTransportError
	 */
	private static function dechunk(string $raw): string {
		$out = '';
		$pos = 0;

		while (true) {
			$eol = strpos($raw, "\r\n", $pos);

			if ($eol === false || preg_match('/^([0-9A-Fa-f]{1,8})(?:;[^\r\n]*)?$/', substr($raw, $pos, $eol - $pos), $m) !== 1) {
				throw new UfmTransportError('malformed', 'invalid chunk size');
			}

			$size = (int) hexdec($m[1]);
			$pos  = $eol + 2;

			if ($size === 0) {
				while (true) {
					$eol = strpos($raw, "\r\n", $pos);

					if ($eol === false) {
						throw new UfmTransportError('malformed', 'missing chunked terminator');
					}

					$line = substr($raw, $pos, $eol - $pos);
					$pos  = $eol + 2;

					if ($line === '') {
						return $out;
					}

					if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+:/', $line) !== 1) {
						throw new UfmTransportError('malformed', 'invalid chunked trailer');
					}
				}
			}

			if (strlen($raw) < $pos + $size + 2 || substr($raw, $pos + $size, 2) !== "\r\n") {
				throw new UfmTransportError('malformed', 'invalid chunk data');
			}

			$out .= substr($raw, $pos, $size);
			$pos += $size + 2;
		}
	}

	/**
	 * Classify.
	 *
	 * @param string $message
	 * @return string
	 */
	private static function classify(string $message): string {
		return match (true) {
			str_contains($message, 'certificate verify failed') => 'tls certificate not trusted',
			str_contains($message, 'did not match expected')    => 'tls name mismatch',
			str_contains($message, 'timed out')                 => 'connect timed out',
			str_contains($message, 'refused')                   => 'connection refused',
			default                                             => 'connect failed',
		};
	}
}
