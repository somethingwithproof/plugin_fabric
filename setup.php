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
 * Cacti includes this file on every request, on whatever PHP version the site
 * runs. It must parse on PHP 8.0 (CI lints it there), and nothing under lib/
 * is loaded until fabric_boot() has confirmed PHP 8.2 or later (ADR 0007).
 * Every hook and lifecycle function therefore starts with that check, so an
 * unsupported runtime gets a refusal instead of a parse error that would take
 * down the whole Cacti UI.
 */

function fabric_php_supported(): bool {
	return version_compare(PHP_VERSION, '8.2.0', '>=');
}

function fabric_boot(): bool {
	if (!fabric_php_supported()) {
		return false;
	}

	require_once(__DIR__ . '/include/autoload.php');

	return true;
}

/**
 * Refuse with a message naming the required version (REQ-COMPAT-002).
 *
 * Used by the lifecycle functions only. Hook shims return silently instead,
 * because they run on every page and a refused plugin must not flood the log.
 */
function fabric_refuse_unsupported(): bool {
	if (fabric_php_supported()) {
		return false;
	}

	$message = __('The Fabric plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.', '8.2.0', PHP_VERSION, 'fabric');

	cacti_log('ERROR: ' . $message, false, 'FABRIC');

	if (PHP_SAPI === 'cli') {
		fwrite(STDERR, $message . PHP_EOL);
	} elseif (function_exists('raise_message')) {
		raise_message('fabric_php', $message, MESSAGE_LEVEL_ERROR);
	}

	return true;
}

/**
 * @return array<string, string>
 */
function plugin_fabric_version(): array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	if (!is_array($info) || !isset($info['info']) || !is_array($info['info'])) {
		return array();
	}

	$version = array();

	foreach ($info['info'] as $key => $value) {
		$version[(string) $key] = is_scalar($value) ? (string) $value : '';
	}

	return $version;
}

function plugin_fabric_install(): void {
	if (fabric_refuse_unsupported()) {
		return;
	}

	plugin_fabric_upgrade(true);
}

function plugin_fabric_check_config(): bool {
	if (fabric_refuse_unsupported()) {
		return false;
	}

	return plugin_fabric_upgrade(true);
}

/**
 * Common start of every page script, after include/auth.php has authorized
 * the realm. Refuses on an unsupported runtime or a disabled plugin, because
 * Cacti keeps plugin realms registered while a plugin is disabled.
 */
function fabric_page_start(): bool {
	if (fabric_boot() && api_plugin_is_enabled('fabric')) {
		return true;
	}

	top_header();

	html_start_box(__('Fabrics', 'fabric'), '100%', '', '3', 'center', '');

	print '<tr><td>' . html_escape(fabric_php_supported() ? __('The Fabric plugin is not enabled.', 'fabric') : __('The Fabric plugin requires PHP %s or later.', '8.2.0', 'fabric')) . '</td></tr>';

	html_end_box();

	bottom_footer();

	return false;
}

/**
 * State-changing actions are accepted only as POST, where csrf-magic checks
 * the token (REQ-UI-004). A GET that names one is sent back to the page
 * without effect.
 *
 * @param list<string> $actions
 */
function fabric_require_post(string $page, array $actions): void {
	$action = (string) get_nfilter_request_var('action');

	if (in_array($action, $actions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
		header('Location: ' . $page);
		exit;
	}
}

/**
 * Whether the current user holds the Fabric Admin realm. Unmapped nodes
 * are shown only to them (REQ-UI-005).
 */
function fabric_is_admin(): bool {
	return (bool) api_user_realm_auth('fabric_admin.php');
}

/**
 * Whether the current user may see a Cacti device, by core's device
 * permissions.
 */
function fabric_device_allowed(int $hostId): bool {
	global $config;

	if (!function_exists('is_device_allowed')) {
		include_once($config['library_path'] . '/auth.php');
	}

	return $hostId > 0 && (bool) is_device_allowed($hostId);
}

function fabric_settings(): \Somethingwithproof\Fabric\Settings {
	return (new \Somethingwithproof\Fabric\SettingsRepository(false))->load();
}



/**
 * Bring the schema, hooks, and realms up to the code's version.
 *
 * Cacti 1.2.31 never calls this itself; check_config and the config_arrays
 * hook do. Fresh installs take the same path as upgrades. The version stored
 * in plugin_config changes only after every migration has succeeded, so an
 * interrupted run is finished by the next one (REQ-LIFE-002).
 *
 * $grant gives newly registered realms to the current user. Only install and
 * check_config pass it: both run from the Plugins page, which needs plugin
 * management. A repair triggered from config_arrays can run for anyone who
 * opens a plugin page, so it must never grant anything.
 */
function plugin_fabric_upgrade(bool $grant = false): bool {
	if (!fabric_boot()) {
		return false;
	}

	$info   = plugin_fabric_version();
	$schema = new \Somethingwithproof\Fabric\Schema(false);

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === $info['version'] && $schema->hooksRegistered()) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	$schema->trackTables();

	fabric_setup_hooks($grant);

	$schema->recordPluginVersion($info['version'], $info['author'], $info['homepage']);

	return true;
}

/**
 * Register hooks and realms.
 *
 * Kept procedural and named *_setup_* because api_plugin_register_hook() and
 * api_plugin_register_realm() refuse callers whose function name does not
 * contain install, upgrade, or setup.
 */
function fabric_setup_hooks(bool $grant = false): void {
	global $config;

	// Registering a hook replicates plugin config, which needs core lib/poller.php.
	if (!function_exists('replicate_out')) {
		include_once($config['library_path'] . '/poller.php');
	}

	// A hook repaired while the plugin is enabled must be active, or core
	// never calls it; on install and while disabled, core decides.
	$enabled = (new \Somethingwithproof\Fabric\Schema(false))->pluginEnabled();

	foreach (\Somethingwithproof\Fabric\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('fabric', $hook, $function, 'setup.php', $enabled);
	}

	foreach (\Somethingwithproof\Fabric\Pages::realms() as $realm) {
		api_plugin_register_realm('fabric', implode(',', $realm['files']), $realm['name'], $grant);
	}
}

/**
 * Abort an uninstall that was told to keep the tables but could not untrack
 * them. Core drops every tracked table once plugin_fabric_uninstall() returns
 * and ignores its result, so only stopping the request keeps the tables; the
 * plugin then stays installed and the uninstall can be retried. PHP 8.0
 * syntax, because the unbooted path uses it too.
 */
function fabric_uninstall_stop(): void {
	cacti_log('ERROR: Uninstall stopped: the plugin tables could not be untracked, so Cacti would have dropped the tables it was told to keep', false, 'FABRIC');

	throw new RuntimeException('The Fabric plugin uninstall was stopped to keep its tables. See the Cacti log.');
}

/**
 * Uninstall never touches a Cacti device, data source, graph, or RRD file,
 * and never runs a fabric tool (REQ-LIFE-005). Core drops the tracked
 * plugin tables afterwards unless the administrator left "Keep Plugin
 * Tables on Uninstall" checked, which is the default; the export on the
 * Fabric Admin page is the copy to take first (REQ-LIFE-003). M2 creates no
 * devices, so there is none to offer to delete.
 */
function plugin_fabric_uninstall(): void {
	if (!fabric_boot()) {
		include_once(__DIR__ . '/include/unbooted-uninstall.php');
		fabric_uninstall_unbooted();

		return;
	}

	$schema = new \Somethingwithproof\Fabric\Schema(false);

	if (fabric_settings()->keepTablesOnUninstall) {
		if (!$schema->untrackTables()) {
			fabric_uninstall_stop();
		}

		\Somethingwithproof\Fabric\Log::info('Uninstall kept the plugin tables as configured');
	} else {
		$schema->forgetVersion();
	}
}

function fabric_config_arrays(): void {
	global $menu;

	if (!fabric_boot()) {
		return;
	}

	foreach (\Somethingwithproof\Fabric\Pages::menu() as $file => $title) {
		$menu[__('Management')]['plugins/fabric/' . $file] = $title;
	}

	if (in_array(get_current_page(), \Somethingwithproof\Fabric\Pages::upgradeTriggers(), true)) {
		plugin_fabric_upgrade();
	}
}

function fabric_config_settings(): void {
	global $tabs, $settings;

	if (!fabric_boot()) {
		return;
	}

	$tabs['fabric']     = __('Fabrics', 'fabric');
	$settings['fabric'] = \Somethingwithproof\Fabric\Settings::form();

	// Core saves a text setting as given. This hook runs before the save,
	// so a tool directory that fails the checks is taken out of the form
	// being saved; the stored value stays as it was.
	if (get_current_page() === 'settings.php' && get_nfilter_request_var('action') === 'save' && get_nfilter_request_var('tab') === 'fabric' && isset_request_var('fabric_tool_dir')) {
		$fabric_problem = \Somethingwithproof\Fabric\ToolDirectory::problem((string) get_nfilter_request_var('fabric_tool_dir'));

		if ($fabric_problem !== null) {
			unset($settings['fabric']['fabric_tool_dir']);
			raise_message('fabric_tool_dir', __('The Tool Directory was not saved. %s', \Somethingwithproof\Fabric\ToolDirectory::message($fabric_problem), 'fabric'), MESSAGE_LEVEL_ERROR);
		}
	}
}

/**
 * @param array<string, mixed> $nav
 * @return array<string, mixed>
 */
function fabric_draw_navigation_text($nav) {
	if (!fabric_boot()) {
		return $nav;
	}

	return array_merge($nav, \Somethingwithproof\Fabric\Pages::navigation());
}

/**
 * The sweep entry point for this poller (ADR 0008). fabric_sweep.php uses
 * it now and the M3 script server will. It runs nothing while the plugin is
 * disabled: core keeps plugin files reachable from the CLI after a disable,
 * so the enabled state is checked here, not assumed from hooks.
 */
function fabric_sweep(?\Somethingwithproof\Fabric\ProcessRunner $runner = null): \Somethingwithproof\Fabric\Sweep {
	global $config;

	return new \Somethingwithproof\Fabric\Sweep(
		false,
		new \Somethingwithproof\Fabric\SystemClock(),
		$runner ?? new \Somethingwithproof\Fabric\ProcRunner(),
		fabric_settings(),
		(int) ($config['poller_id'] ?? 1),
		(new \Somethingwithproof\Fabric\Schema(false))->pluginEnabled()
	);
}
