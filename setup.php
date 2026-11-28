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

/**
 * Php supported.
 *
 * @return bool
 */
function fabric_php_supported(): bool {
	return version_compare(PHP_VERSION, '8.2.0', '>=');
}

/**
 * Boot.
 *
 * @return bool
 */
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

/**
 * Plugin fabric install.
 *
 * @return void
 */
function plugin_fabric_install(): void {
	if (fabric_refuse_unsupported()) {
		return;
	}

	plugin_fabric_upgrade(true);
}

/**
 * Plugin fabric check config.
 *
 * @return bool
 */
function plugin_fabric_check_config(): bool {
	if (fabric_refuse_unsupported()) {
		return false;
	}

	return plugin_fabric_upgrade(true);
}

/**
 * Common start after include/auth.php establishes the user. Recheck the
 * realm here because cookie and Basic login restore the user after the
 * alternate-realms hook. Disabled plugins and unsupported runtimes refuse.
 */
function fabric_page_start(): bool {
	if (fabric_boot() && api_plugin_is_enabled('fabric')) {
		if (fabric_page_allowed()) {
			return true;
		}
		http_response_code(403);

		return false;
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

/**
 * Whether the current user may see a graph, by core's graph permissions.
 */
function fabric_graph_allowed(int $localGraphId): bool {
	global $config;

	if (!function_exists('is_graph_allowed')) {
		include_once($config['library_path'] . '/auth.php');
	}

	return $localGraphId > 0 && (bool) is_graph_allowed($localGraphId);
}

/**
 * Settings.
 *
 * @return \Somethingwithproof\Fabric\Settings
 */
function fabric_settings(): \Somethingwithproof\Fabric\Settings {
	return (new \Somethingwithproof\Fabric\SettingsRepository(false))->load();
}

/**
 * Templates.
 *
 * @return \Somethingwithproof\Fabric\TemplateInstaller
 */
function fabric_templates(): \Somethingwithproof\Fabric\TemplateInstaller {
	global $config;

	return new \Somethingwithproof\Fabric\TemplateInstaller(__DIR__, (string) $config['base_path'], new \Somethingwithproof\Fabric\CactiObjectRepository(false));
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

	$templates = fabric_templates();

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === $info['version'] && $schema->hooksRegistered() && $templates->installed()) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	$schema->trackTables();

	fabric_setup_hooks($grant);

	// A failed template install is logged and shown on the health page,
	// and retried by the next upgrade check; the plugin itself works
	// without the templates, so it does not fail the upgrade.
	if (!$templates->installed()) {
		$templates->install();
	}

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
		api_plugin_register_realm('fabric', implode(',', $realm['files']), $realm['name'], $grant && $realm['files'] !== \Somethingwithproof\Fabric\Pages::OPERATOR);
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
 * Fabric Admin page is the copy to take first (REQ-LIFE-003). Devices the
 * plugin created stay, like every Cacti object: an administrator deletes
 * them in Cacti, where the Fabric Node device template finds them.
 */
function plugin_fabric_uninstall(): void {
	if (!fabric_boot()) {
		include_once(__DIR__ . '/include/unbooted-uninstall.php');
		fabric_uninstall_unbooted();

		return;
	}

	$schema = new \Somethingwithproof\Fabric\Schema(false);

	// Only the query file the plugin wrote and nobody changed; templates
	// and devices stay.
	fabric_templates()->uninstall();

	if (fabric_settings()->keepTablesOnUninstall) {
		if (!$schema->untrackTables()) {
			fabric_uninstall_stop();
		}

		\Somethingwithproof\Fabric\Log::info('Uninstall kept the plugin tables as configured');
	} else {
		$schema->forgetVersion();
	}
}

/**
 * Config arrays.
 *
 * @return void
 */
function fabric_config_arrays(): void {
	global $menu;

	if (!fabric_boot()) {
		return;
	}

	foreach (\Somethingwithproof\Fabric\Pages::menu() as $file => $title) {
		$menu[__('Management')]['plugins/fabric/' . $file] = $title;
	}

	if (fabric_repair_on_page(get_current_page())) {
		plugin_fabric_upgrade();
	}
}

/**
 * Whether config_arrays may repair the install on this page. Core fires
 * config_arrays before include/auth.php checks the session, so a request
 * that is about to be redirected to the login page gets here too. Only a
 * request that already carries a user may start the migrations and the
 * template import; an anonymous one leaves them to the next page load.
 */
function fabric_repair_on_page(string $page): bool {
	return !empty($_SESSION['sess_user_id']) && in_array($page, \Somethingwithproof\Fabric\Pages::upgradeTriggers(), true);
}

/**
 * Config settings.
 *
 * @return void
 */
function fabric_config_settings(): void {
	global $tabs, $settings;

	if (PHP_SAPI !== 'cli' && !api_plugin_user_realm_auth('fabric_admin.php')) {
		return;
	}

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
	global $current_user;

	if (!fabric_boot()) {
		return $nav;
	}

	// Refresh settings after core restores cookie or Basic authentication.
	if (get_current_page() === 'settings.php' && (PHP_SAPI === 'cli' || ($current_user['enabled'] ?? '') === 'on')) {
		fabric_config_settings();
	}

	return array_merge($nav, \Somethingwithproof\Fabric\Pages::navigation());
}

/**
 * Clear the mappings of deleted devices (REQ-MAP-004). The nodes and their
 * history stay; core passes the device ids and expects them back.
 *
 * @param mixed $device_ids
 * @return mixed
 */
function fabric_device_remove($device_ids) {
	if (fabric_boot() && is_array($device_ids)) {
		$ids = array_filter(array_map(static fn ($id): int => is_numeric($id) ? (int) $id : 0, $device_ids), static fn (int $id): bool => $id > 0);

		(new \Somethingwithproof\Fabric\NodeRepository(false))->unmapHosts(array_values($ids));
	}

	return $device_ids;
}

/**
 * The Fabric Port data query (scripts/ss_fabric_ports.php). With $sweep,
 * as the script server passes, the first value asked for a device starts
 * its due sweep; a sweep that fails is logged and the values are answered
 * from what the cache holds.
 */
function fabric_port_query(bool $sweep): \Somethingwithproof\Fabric\PortQuery {
	$sweeper = null;

	if ($sweep) {
		$sweeper = static function (int $hostId): void {
			try {
				fabric_sweep(null, microtime(true) + fabric_script_sweep_seconds())->forDevice($hostId);
			} catch (\Throwable $e) {
				\Somethingwithproof\Fabric\Log::error(sprintf('Sweep for device %d failed: %s', $hostId, \Somethingwithproof\Fabric\Log::excerpt($e->getMessage())));
			}
		};
	}

	return new \Somethingwithproof\Fabric\PortQuery(false, new \Somethingwithproof\Fabric\SystemClock(), fabric_settings()->indexDownPorts, $sweeper);
}

/**
 * The sweep entry point for this poller (ADR 0008), used by the script
 * server through fabric_port_query() and by fabric_sweep.php. It runs nothing while the plugin is
 * disabled: core keeps plugin files reachable from the CLI after a disable,
 * so the enabled state is checked here, not assumed from hooks.
 */
function fabric_sweep(?\Somethingwithproof\Fabric\ProcessRunner $runner = null, ?float $deadline = null): \Somethingwithproof\Fabric\Sweep {
	global $config;

	return new \Somethingwithproof\Fabric\Sweep(
		false,
		new \Somethingwithproof\Fabric\SystemClock(),
		$runner ?? new \Somethingwithproof\Fabric\ProcRunner(),
		fabric_settings(),
		(int) ($config['poller_id'] ?? 1),
		(new \Somethingwithproof\Fabric\Schema(false))->pluginEnabled(),
		deadline: $deadline
	);
}

/**
 * Seconds a sweep started by a script server get may take: Cacti's script
 * timeout (Spine's limit for one script server call, default 25) less 5
 * seconds for the cache read and the answer. Spine records NaN for a call
 * past its timeout; the PHP
 * poller has no such limit and would stall instead, so the bound applies
 * to both. A sweep cut short is retried in the next cycle, and the get
 * answers from the last stored sample, or U.
 */
function fabric_script_sweep_seconds(): float {
	$timeout = (int) read_config_option('script_timeout');

	return (float) max(0, ($timeout > 0 ? $timeout : 25) - 5);
}

/** Resolve role inheritance against Cacti's current user and group grants. */
function fabric_auth_alternate_realms(): void {
	global $user_auth_realm_filenames;

	static $deferred = array();

	foreach ($deferred as $file => $realm) {
		$user_auth_realm_filenames[$file] = $realm;
	}
	$deferred = array();

	if (!api_plugin_is_enabled('fabric')) {
		return;
	}

	if (empty($_SESSION['sess_user_id'])) {
		// Core restores cookie and Basic authentication after this hook. Only
		// these entrypoints defer the realm check to their post-auth guard.
		$page = get_current_page();

		if (in_array($page, array('fabric.php', 'fabric_operator.php'), true) && isset($user_auth_realm_filenames[$page])) {
			$deferred[$page]                  = $user_auth_realm_filenames[$page];
			$user_auth_realm_filenames[$page] = -1;
		}

		return;
	}
	$operator = 'fabric_operator.php';
	$admin = 'fabric_admin.php';
	$files = array('fabric.php');
	$realm = $user_auth_realm_filenames[$admin] ?? 0;
	if ($realm > 0 && is_realm_allowed($realm)) {
		$files[] = $operator;
	} else {
		$realm = $user_auth_realm_filenames[$operator] ?? 0;
		if ($realm <= 0 || !is_realm_allowed($realm)) {
			return;
		}
	}
	foreach ($files as $file) {
		$user_auth_realm_filenames[$file] = $realm;
	}
}

/** Recheck the authenticated user before a plugin page reads data or acts. */
function fabric_page_allowed(): bool {
	global $current_user;

	fabric_auth_alternate_realms();
	$userId    = $current_user['id'] ?? null;
	$sessionId = $_SESSION['sess_user_id'] ?? null;

	return (is_int($userId) || (is_string($userId) && ctype_digit($userId)))
		&& (is_int($sessionId) || (is_string($sessionId) && ctype_digit($sessionId)))
		&& (int) $userId > 0 && (int) $userId === (int) $sessionId
		&& api_plugin_is_enabled('fabric')
		&& ($current_user['enabled'] ?? '') === 'on'
		&& api_plugin_user_realm_auth(get_current_page());
}

/**
 * poller_bottom hook: launch poller_fabric.php in the background for each
 * enabled fabric assigned to this poller whose discovery sweep is due.
 */
function fabric_poller_bottom(): void {
	global $config;

	if (!fabric_boot()) {
		return;
	}

	if (!(new \Somethingwithproof\Fabric\Schema(false))->isCurrent()) {
		return;
	}

	if (!function_exists('exec_background')) {
		include_once($config['library_path'] . '/poller.php');
	}

	$php = trim((string) read_config_option('path_php_binary'));

	if ($php === '' || !is_file($php) || !is_executable($php)) {
		return;
	}

	$poller_id = (int) ($config['poller_id'] ?? 1);
	$clock     = new \Somethingwithproof\Fabric\SystemClock();
	$due       = (new \Somethingwithproof\Fabric\FabricRepository(false, $clock))->dueForPoller($poller_id);

	foreach ($due as $fabric_id) {
		// @phpstan-ignore argument.type
		exec_background($php, array('-q', __DIR__ . '/poller_fabric.php', '--fabric=' . (int) $fabric_id));
	}
}

/**
 * replicate_out hook: replicate non-secret fabric definitions assigned to a
 * remote collector. Vault entries (plugin_fabric_secret) and external key
 * files are never replicated.
 *
 * @param mixed $data
 * @return mixed
 */
function fabric_replicate_out($data) {
	if (!fabric_boot() || !is_array($data)) {
		return $data;
	}

	$remote_poller_id = isset($data['remote_poller_id']) && is_numeric($data['remote_poller_id']) ? (int) $data['remote_poller_id'] : 0;
	$rcnn_id          = $data['rcnn_id'] ?? false;

	if ($remote_poller_id < 2 || $rcnn_id === false || !function_exists('replicate_out_table')) {
		return $data;
	}

	$replicator = new \Somethingwithproof\Fabric\ReplicatorRepository(false);
	$fabrics    = $replicator->fabricsForPoller($remote_poller_id);

	// @phpstan-ignore argument.type
	replicate_out_table($rcnn_id, $fabrics, 'plugin_fabric_fabric', $remote_poller_id);

	return $data;
}

/**
 * Health evaluator.
 *
 * @return \Somethingwithproof\Fabric\HealthEvaluator
 */
function fabric_health_evaluator(): \Somethingwithproof\Fabric\HealthEvaluator {
	$clock = new \Somethingwithproof\Fabric\SystemClock();

	return new \Somethingwithproof\Fabric\HealthEvaluator(
		new \Somethingwithproof\Fabric\FabricRepository(false, $clock),
		new \Somethingwithproof\Fabric\NodeRepository(false),
		new \Somethingwithproof\Fabric\FabricHealthRepository(false),
		new \Somethingwithproof\Fabric\NodeSweepRepository(false),
		new \Somethingwithproof\Fabric\RelinkRepository(false, $clock),
		new \Somethingwithproof\Fabric\SampleRepository(false),
		$clock,
		new \Somethingwithproof\Fabric\MaintMaintenance(),
		fabric_settings(),
		new \Somethingwithproof\Fabric\Schema(false),
	);
}

/**
 * Config service.
 *
 * @return \Somethingwithproof\Fabric\ConfigService
 */
function fabric_config_service(): \Somethingwithproof\Fabric\ConfigService {
	$clock = new \Somethingwithproof\Fabric\SystemClock();

	return new \Somethingwithproof\Fabric\ConfigService(
		new \Somethingwithproof\Fabric\SettingsRepository(false),
		new \Somethingwithproof\Fabric\FabricRepository(false, $clock),
		new \Somethingwithproof\Fabric\CredentialRepository(false, $clock),
		new \Somethingwithproof\Fabric\ConfigRepository(false),
	);
}

/**
 * User view allowed.
 *
 * @param int $userId
 * @return bool
 */
function fabric_user_view_allowed(int $userId): bool {
	if ($userId < 1 || !function_exists('is_realm_allowed')) {
		return false;
	}

	foreach ((new \Somethingwithproof\Fabric\RealmRepository(false))->viewRealmIds() as $realmId) {
		if (is_realm_allowed($realmId, $userId)) {
			return true;
		}
	}

	return false;
}

/**
 * User device allowed.
 *
 * @param int $userId
 * @param int $hostId
 * @return bool
 */
function fabric_user_device_allowed(int $userId, int $hostId): bool {
	if ($userId < 1 || $hostId < 1) {
		return false;
	}

	if (function_exists('is_device_allowed')) {
		return (bool) is_device_allowed($hostId, $userId);
	}

	return false;
}

/**
 * abilities_register hook: expose read-only Fabric abilities to Cacti MCP /
 * Abilities API v2, gated by the Fabric View realm.
 *
 * @param mixed $registrar
 */
function fabric_abilities_register($registrar): void {
	if (!fabric_boot() || !is_object($registrar)) {
		return;
	}

	$provider = new \Somethingwithproof\Fabric\AbilitiesProvider(
		fabric_health_evaluator(),
		static function (int $userId): bool {
			return fabric_user_view_allowed($userId);
		},
		static function (int $userId, int $hostId): bool {
			return fabric_user_device_allowed($userId, $hostId);
		},
		static function (int $userId): bool {
			return fabric_user_view_allowed($userId);
		},
	);

	$provider->register($registrar);
}

/**
 * doctor_register_checks hook: return evaluated diagnostic checks for Cacti Doctor.
 *
 * @param mixed $context
 * @return list<array<string, mixed>>
 */
function fabric_doctor_register_checks($context = array()) {
	if (!fabric_boot()) {
		return array();
	}

	return (new \Somethingwithproof\Fabric\DoctorProvider(fabric_health_evaluator()))->checks();
}

/**
 * casc_register_providers hook: register Fabric CasC provider descriptor.
 *
 * @param mixed $providers
 * @return mixed
 */
function fabric_casc_register_providers($providers = null) {
	if (!fabric_boot()) {
		return $providers;
	}

	$descriptor = (new \Somethingwithproof\Fabric\CascProvider(fabric_config_service()))->descriptor();

	if (is_object($providers) && method_exists($providers, 'register')) {
		$providers->register($descriptor);

		return $providers;
	}

	return $descriptor;
}

/**
 * doctor_register_debug_info hook: contribute a redacted plain-array
 * diagnostic snapshot for Cacti Doctor without importing external plugin classes.
 *
 * @param mixed $context
 * @return list<array<string, mixed>>
 */
function fabric_doctor_register_debug_info($context = array()) {
	if (!fabric_boot()) {
		return array();
	}

	return (new \Somethingwithproof\Fabric\DoctorProvider(fabric_health_evaluator()))->debugInfo();
}

/**
 * api_register_resources hook: expose plain-array Google AIP resource
 * descriptors without importing external API plugin classes.
 *
 * @param mixed $resources
 * @return list<mixed>
 */
function fabric_api_register_resources($resources = array()) {
	if (!fabric_boot()) {
		return is_array($resources) ? array_values($resources) : array();
	}

	$provider = new \Somethingwithproof\Fabric\ApiResourceProvider(
		fabric_health_evaluator(),
		static function (int $userId): bool {
			return fabric_user_view_allowed($userId);
		},
		static function (int $userId, int $hostId): bool {
			return fabric_user_device_allowed($userId, $hostId);
		},
		static function (int $userId): bool {
			return fabric_user_view_allowed($userId);
		},
	);

	$descriptors = $provider->resources();

	if (is_array($resources) && $resources !== array()) {
		return array_values(array_merge(array_values($resources), $descriptors));
	}

	return $descriptors;
}
