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
 * Installs the shipped Cacti objects (REQ-OBJ-001, REQ-OBJ-002, ADR 0011):
 * the Fabric Port data query XML, copied into Cacti's resource tree, and
 * the device, data, and graph templates, imported with Cacti's own
 * import_xml_data().
 *
 * templates/fabric-templates.xml is Cacti's own export of templates that
 * tests/bin/build-templates.php builds in a disposable Cacti, never edited
 * by hand. Every object carries a hash derived from its name, so a second
 * import updates the objects of the first instead of adding copies, and
 * the plugin finds its objects by hash after an operator renames them.
 *
 * Cacti reads a data query XML only from inside <path_cacti>/resource, so
 * the file is copied there, the one place outside its own directory the
 * plugin writes. It is written only where no file is, or over a file the
 * plugin wrote itself and nobody changed since: the setting FILES_SETTING
 * keeps the SHA-256 of what was written. A destination that is a symbolic
 * link, dangling or not, is refused. The file is written beside the
 * destination and renamed over it, so a reindex never reads half a file.
 *
 * Nothing here creates a device, data source, graph, or RRD file.
 */
final class TemplateInstaller {
	/** Bump when templates/fabric-templates.xml or the query XML changes. */
	public const VERSION = 1;

	/** Setting constant for TemplateInstaller. */
	public const SETTING         = 'fabric_templates_version';
	/** Failure setting constant for TemplateInstaller. */
	public const FAILURE_SETTING = 'fabric_templates_failure';
	/** Files setting constant for TemplateInstaller. */
	public const FILES_SETTING   = 'fabric_query_files';

	/** Device template constant for TemplateInstaller. */
	public const DEVICE_TEMPLATE = 'Fabric Node';
	/** Query constant for TemplateInstaller. */
	public const QUERY           = 'Fabric Port';
	/** Query file constant for TemplateInstaller. */
	public const QUERY_FILE      = 'fabric_ports.xml';
	/** Template file constant for TemplateInstaller. */
	public const TEMPLATE_FILE   = 'fabric-templates.xml';

	/**
	 * Construct a new TemplateInstaller instance.
	 *
	 * @param string $pluginPath
	 * @param string $cactiPath
	 * @param CactiObjectRepository $objects
	 */
	public function __construct(
		private readonly string $pluginPath,
		private readonly string $cactiPath,
		private readonly CactiObjectRepository $objects,
	) {
	}

	/**
	 * The hash the template builder gives an object: 32 hexadecimal
	 * characters, as Cacti stores it.
	 */
	public static function hash(string $kind, string $name): string {
		return md5('plugin_fabric:' . $kind . ':' . $name);
	}

	/**
	 * Whether this version's objects were installed. The upgrade check
	 * runs on page loads, so it reads only this setting; a template or
	 * file removed afterwards is found by isCurrent() on the health page,
	 * which offers a reinstall to users who may import templates.
	 */
	public function installed(): bool {
		return (int) read_config_option(self::SETTING) === self::VERSION;
	}

	/**
	 * Check whether current.
	 *
	 * @return bool
	 */
	public function isCurrent(): bool {
		return $this->installed() && $this->present() && $this->queryFileProblem() === null;
	}

	/**
	 * Why the last install failed, or '' after a success.
	 */
	public function failure(): string {
		return (string) read_config_option(self::FAILURE_SETTING);
	}

	/**
	 * Place the query file and import the templates. A failure is logged
	 * and kept for the health page; a success clears it.
	 */
	public function install(): bool {
		$error = $this->installQueryFile() ?? $this->import();

		if ($error !== null) {
			Log::error($error);
			set_config_option(self::FAILURE_SETTING, $error);

			return false;
		}

		set_config_option(self::SETTING, (string) self::VERSION);
		set_config_option(self::FAILURE_SETTING, '');

		return true;
	}

	/**
	 * Remove the query file if the plugin wrote it and nobody changed it.
	 * The templates and everything made from them stay: once imported they
	 * belong to the site.
	 */
	public function uninstall(): void {
		$target  = $this->target();
		$written = $this->written();

		if ($target !== null && $written !== '' && !is_link($target) && is_file($target) && hash_file('sha256', $target) === $written) {
			@unlink($target);
		}

		set_config_option(self::FILES_SETTING, '');
		set_config_option(self::SETTING, '');
	}

	/**
	 * Every shipped object exists, found by hash.
	 */
	public function present(): bool {
		if ($this->objects->deviceTemplateId() === 0 || $this->objects->queryId() === 0) {
			return false;
		}

		foreach (array_keys(PortValues::templates()) as $name) {
			if ($this->objects->idByHash('data_template', self::hash('data_template', $name)) === 0 || $this->objects->graphTemplateId($name) === 0) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Why Cacti's copy of the query file is not the shipped one, or null.
	 */
	public function queryFileProblem(): ?string {
		$target = $this->target();
		$source = $this->source();

		if ($target === null || !is_file($source)) {
			return sprintf('The data query file %s cannot be installed: the plugin copy or the Cacti resource directory is missing', self::QUERY_FILE);
		}

		if (is_link($target)) {
			return sprintf('%s is a symbolic link; the plugin does not write through one. Replace it with a copy of plugins/fabric/resource/script_server/%s', $target, self::QUERY_FILE);
		}

		if (!is_file($target) || hash_file('sha256', $target) !== hash_file('sha256', $source)) {
			return sprintf('%s is missing or differs from plugins/fabric/resource/script_server/%s', $target, self::QUERY_FILE);
		}

		return null;
	}

	/**
	 * Target.
	 *
	 * @return ?string
	 */
	public function target(): ?string {
		$dir = $this->cactiPath . '/resource/script_server';

		return is_dir($dir) ? $dir . '/' . self::QUERY_FILE : null;
	}

	/**
	 * @return string|null the reason, or null when the file is in place
	 */
	private function installQueryFile(): ?string {
		$source = $this->source();
		$target = $this->target();
		$data   = is_file($source) ? file_get_contents($source) : false;

		if ($target === null || !is_string($data) || $data === '') {
			return sprintf('The data query file %s cannot be installed: the plugin copy or the Cacti resource directory is missing', self::QUERY_FILE);
		}

		$want = hash('sha256', $data);

		if (is_link($target)) {
			return sprintf('%s is a symbolic link, so it was not written. Remove the link and reinstall the templates from the health page', $target);
		}

		if (file_exists($target)) {
			$have = is_file($target) ? (string) hash_file('sha256', $target) : '';

			if ($have === $want) {
				return null;
			}

			if ($have === '' || $have !== $this->written()) {
				return sprintf('%s exists and was not written by this plugin, or was changed after; it was left as it is. Compare it with plugins/fabric/resource/script_server/%s', $target, self::QUERY_FILE);
			}
		}

		$temporary = dirname($target) . '/.' . self::QUERY_FILE . '.' . bin2hex(random_bytes(8)) . '.tmp';
		$handle    = @fopen($temporary, 'x');

		if ($handle === false) {
			return sprintf('Could not write in %s. Copy plugins/fabric/resource/script_server/%s there as the web server user, then reinstall the templates from the health page', dirname($target), self::QUERY_FILE);
		}

		$complete = fwrite($handle, $data) === strlen($data) && fflush($handle);
		fclose($handle);

		// Checked again just before the rename: a link placed meanwhile
		// is replaced by the rename, never written through, but refused.
		clearstatcache(true, $target);

		if (!$complete || is_link($target) || !@rename($temporary, $target)) {
			@unlink($temporary);

			return sprintf('Could not write %s. Copy plugins/fabric/resource/script_server/%s there as the web server user, then reinstall the templates from the health page', $target, self::QUERY_FILE);
		}

		if (hash_file('sha256', $target) !== $want) {
			return sprintf('%s could not be verified after it was written', $target);
		}

		set_config_option(self::FILES_SETTING, $want);

		return null;
	}

	/**
	 * @return string|null the reason, or null when every object imported
	 */
	private function import(): ?string {
		$file = $this->pluginPath . '/templates/' . self::TEMPLATE_FILE;
		$xml  = is_readable($file) ? file_get_contents($file) : false;

		if (!is_string($xml) || $xml === '') {
			return 'The template file ' . $file . ' is missing or empty';
		}

		include_once($this->cactiPath . '/lib/utility.php');
		include_once($this->cactiPath . '/lib/import.php');

		$result = import_xml_data($xml, false, $this->objects->defaultProfileId());

		if (!is_array($result) || $result === []) {
			return 'Importing the Fabric templates returned nothing';
		}

		$failed = 0;

		foreach ($result as $type => $entries) {
			if ($type === 'files' || !is_array($entries)) {
				continue;
			}

			foreach ($entries as $entry) {
				if (is_array($entry) && ($entry['result'] ?? '') === 'fail') {
					$failed++;
				}
			}
		}

		if ($failed > 0) {
			return sprintf('%d Fabric template objects failed to import', $failed);
		}

		return $this->present() ? null : 'The Fabric templates are missing after import';
	}

	/**
	 * The SHA-256 of the query file the plugin last wrote, or ''.
	 */
	private function written(): string {
		$hash = (string) read_config_option(self::FILES_SETTING);

		return preg_match('/^[0-9a-f]{64}$/D', $hash) === 1 ? $hash : '';
	}

	/**
	 * Source.
	 *
	 * @return string
	 */
	private function source(): string {
		return $this->pluginPath . '/resource/script_server/' . self::QUERY_FILE;
	}
}
