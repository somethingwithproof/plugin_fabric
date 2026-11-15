# Cacti core behavior

Research for [#24](https://github.com/somethingwithproof/plugin_fabric/issues/24), run 2026-11-15. Question: does every Cacti API and behavior the plan names hold at `release/1.2.31` and on the current `1.2.x` branch? This follows the method and reuses the verified findings of plugin_cloud's `docs/research/cacti-core.md`; each reused finding below was re-checked against the refs in this file, not copied.

**Result.** All fifteen named functions have identical signatures at both refs, and every hook the plan uses is called by core with the arguments recorded below. Four plan statements need correcting. Maint's hook `is_device_in_maintenance` has no caller; Thold calls `plugin_maint_check_cacti_host()` directly. A script data query served by the script server gets no "Index Count Changed" detection unless the XML defines `arg_num_indexes`, so ports appearing and disappearing are not noticed otherwise. The index delimiter in a script data query must be set to something other than `:`, because core splits each output line at the first delimiter and a `<GUID>:<port>` index contains one. And the plan's `poller_bottom` hook runs on a remote collector only when the plugin declares `remote_poller:1`. Two further findings matter for design: the PHP poller reads a script server reply with a blocking `fgets` and no timeout (the `script_timeout` setting applies to Spine only), and a COUNTER data source with the default `max` of 0 is created with `max` 1. Nothing here was executed against Cacti; the cloud research's executed script-server query on a device with availability None is reused as evidence, not repeated.

## Refs used

| Name | Ref | Commit |
|---|---|---|
| release/1.2.31 ("R") | `refs/tags/release/1.2.31` | `1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1` |
| 1.2.x ("B") | `refs/remotes/origin/1.2.x` | `e2d5c25893221c3cd54ceb8293fba6ca38c936e6` (committed 2026-11-12; plugin_cloud's run used `30010fc5ff3eb7000c29db93631330a8ee163d0a`) |
| plugin_thold | default branch `develop` | `dc76952ee52e8b4877b2d5b830caab1f7c43dde9` (same as plugin_cloud's run) |
| plugin_maint | default branch `develop` | `9422c7a4ce9916d0f0edf88b93afb194e0675b4a` (same) |
| plugin_servcheck | default branch `main` | `10ab7ac7aa456d375449d05cffba2ec6384607e5` |
| plugin_webseer | default branch `develop` | `706744874f30291c6cff838a2577494a1766f8a1` |
| Spine | `refs/remotes/origin/develop` | `c81b1c07aff3d16e4b9fe5371a194b5d88ebe245` |

Read with `git show` and `git grep` on those refs; the Thold, Maint, servcheck, and webseer files were read from GitHub at the pinned commits. Cacti was not run.

## Function signatures

Compared as complete signature text (including the 29 parameters of `api_device_save`). "line R / B" are the definition lines.

| Function | File | Line R / B | Result | Status |
|---|---|---|---|---|
| `api_plugin_register_hook($plugin, $hook, $function, $file, $enable = false)` | lib/plugins.php | 1030 / 1218 | same | confirmed |
| `api_plugin_register_realm($plugin, $file, $display, $admin = true)` | lib/plugins.php | 1137 / 1372 | same | confirmed |
| `api_plugin_db_table_create($plugin, $table, $data)` | lib/plugins.php | 461 / 581 | same signature; behavior differs, below | confirmed |
| `api_device_save($id, $device_template_id, $description, $hostname, ... $bulk_walk_size = -1)` | lib/api_device.php | 939 / 948 | same | confirmed |
| `is_device_allowed($device_id, $user_id = 0)` | lib/auth.php | 853 / 884 | same | confirmed |
| `get_allowed_devices($sql_where = '', $sql_order = 'description', $sql_limit = '', &$total_rows = 0, $user_id = 0, $device_id = 0)` | lib/auth.php | 2768 / 2810 | same | confirmed |
| `import_xml_data(&$xml_data, $import_as_new, $profile_id, $remove_orphans = false, $replace_svalues = false, $import_hashes = array(), $class = '')` | lib/import.php | 25 / 38 | same | confirmed |
| `query_script_host($host_id, $snmp_query_id)` | lib/data_query.php | 645 / 715 | same | confirmed |
| `exec_background($filename, $args = '', $redirect_args = '')` | lib/poller.php | 132 / 189 | same signature; quoting differs, below | confirmed |
| `register_process_start($tasktype, $taskname, $taskid = 0, $timeout = 300)` | lib/poller.php | 2383 / 2868 | same | confirmed |
| `call_remote_data_collector($poller_id, $url, $logtype = 'WEBUI')` | lib/functions.php | 6380 / 7340 | same | confirmed |
| `api_device_remove($device_id)`, `api_device_remove_multi($device_ids, $delete_type = 2)` | lib/api_device.php | 45, 179 / 47, 183 | same | confirmed |
| `api_data_source_remove($local_data_id)` | lib/api_data_source.php | 57 / 74 | same | confirmed |
| `remote_client_authorized()` | remote_agent.php | 151 / 186 | same name; B reads `REMOTE_ADDR` only and says a collector connects directly | confirmed |

Entry-point rule (reused, re-read): `api_plugin_valid_entrypoint()` accepts a call only when the third frame's function name contains `install`, `upgrade`, or `setup`; otherwise it logs a PLUGIN warning and returns false. At B the check uses `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)` and treats a file-scope call as invalid. A hook or realm registered from a helper class method is therefore rejected. Lines: [R plugins.php](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/plugins.php), [B plugins.php](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/plugins.php). Status: confirmed (read, not run).

`api_plugin_db_table_create()` differs: at R it lists tables with `SHOW TABLES` and creates a missing one; at B it returns early in one case and uses `db_table_exists()` and `db_table_create()`. Migrations must not depend on either behavior for an existing table. Status: confirmed by reading.

`exec_background()` differs: at R it builds `$filename . ' ' . $args` and runs it through `exec()` with no quoting of `$filename`; at B it quotes `$filename` with `cacti_escapeshellarg()`. Both go through a shell, so the plugin passes the PHP binary as `$filename`, the sweep script and options as `$args`, and puts no operator text in them. Status: confirmed.

## Hooks the plan uses

| Hook | Called from (R / B) | Arguments and return | Status |
|---|---|---|---|
| `config_settings` | include/global_settings.php:2939 / 3015 | `api_plugin_hook()`, no arguments | confirmed |
| `config_arrays` | include/global_arrays.php:2938 / 2954 | `api_plugin_hook()` | confirmed |
| `draw_navigation_text` | lib/functions.php:3777 / 4399 | `api_plugin_hook_function`, receives and returns the `$navigation` array | confirmed |
| `poller_bottom` | poller.php:864, 943, 956 / 897, 981, 994 | `api_plugin_hook()`. The first call is inside the per-cycle loop, so on a poller process that runs several cycles it runs each cycle; the others are at the end of a run. | confirmed |
| `poller_output` | lib/poller.php:816 / 1020 | `api_plugin_hook_function('poller_output', $rrd_update_array)` | confirmed |
| `device_remove` | lib/api_device.php:52, 186 / 54, 190 | an **array of device ids** (`array($device_id)` or `$device_ids`) | confirmed |
| `data_source_remove` | lib/api_data_source.php:62, 183 / 79, 211 | an **array of local data ids** | confirmed |
| `api_device_save` | lib/api_device.php:1036 / 1046 | receives the `$save` host row array and must return it | confirmed |
| `replicate_out` | lib/api_device.php:880, lib/poller.php:1783 / 886, 2048 | array with `remote_poller_id`, `rcnn_id`, `class` | confirmed |
| `remote_agent` | remote_agent.php:107 / 107 | `api_plugin_hook_function('remote_agent', get_request_var('action'))`; the default branch treats a falsy return as "unknown action" | confirmed |

Remote collector gating (reused, re-read): on a collector (`poller_id > 1`) `api_plugin_run_plugin_hook()` runs `poller_bottom` only for plugins whose capability string contains `remote_poller:1`, and `poller_top`, `poller_output`, and similar only with `remote_collect:1`; hooks not in the gating table run when the plugin declares no capability string or the connection is online ([R plugins.php:187-260](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/plugins.php#L187-L260); same table at B lines 229 to 305). servcheck and webseer both declare `capabilities = online_view:1, online_mgmt:1, offline_view:0, offline_mgmt:0, remote_collect:1` and no `remote_poller` ([servcheck INFO](https://github.com/Cacti/plugin_servcheck/blob/10ab7ac7aa456d375449d05cffba2ec6384607e5/INFO), [webseer INFO](https://github.com/Cacti/plugin_webseer/blob/706744874f30291c6cff838a2577494a1766f8a1/INFO)). So the plan's statement that `INFO` declares "the same capability set that servcheck and webseer declare" is accurate, but that set does not run `poller_bottom` on a collector; phase 3 needs `remote_poller:1` as well. Status: confirmed.

## Script data query

| Plan item | Result | Status | Source |
|---|---|---|---|
| `arg_index`, `arg_query`, `arg_get`, `arg_num_indexes` | `query_script_host()` runs `arg_index` for the list of indexes and `arg_query <field>` once per field; `arg_get` is used at poll time. Both calls run the script as a separate process; for a script-server query the command is `"|path_php_binary|" -q <script_path>`. | confirmed | [data_query.php:645-760](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/data_query.php#L645-L760) |
| Query output format | each `arg_query` line is split with `/(.*?)<delimiter>(.*)/`; the part before the **first** delimiter is the index. The delimiter comes from `output_delimiter` (or the older spelling `output_delimeter`) in the XML. If neither is set, no line matches and nothing is stored. | confirmed | [data_query.php:733-752](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/data_query.php#L733-L752) |
| Consequence for `<GUID>:<port>` indexes | With a `:` delimiter, `0x0002c9030000abcd:12:value` yields index `0x0002c9030000abcd`. The XML must name another delimiter (one the plugin guarantees never appears in an index) or the index must avoid the delimiter. | confirmed by reading; not run | same |
| Index storage | `host_snmp_cache.snmp_index`, `data_local.snmp_index`, and another table are `varchar(255)`; a `0x` GUID plus port is 21 characters | confirmed | [cacti.sql:1265, 1608, 1965](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/cacti.sql#L1265) |
| Index text in RRD file names | The path is built from `<path_rra>/<host description>_<ds name>_<local_data_id>.rrd` or, with extended paths, from ids only; the index is never in the path | confirmed | [functions.php:3046-3110](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/functions.php#L3046-L3110) |
| Colons in graph text | Graph item text goes through `rrdtool_escape_string()`, which escapes `"` and `:`; the title goes through `cacti_escapeshellarg(html_escape(...))` | confirmed | [rrd.php:2309, 1198; escape function at 2559](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/rrd.php#L2559) |
| Reindex when ports appear and disappear | The recache list asks for "Index Count Changed" with `arg_num_indexes` if the XML has it; for a plain script query without it, core counts `arg_index` entries (`POLLER_ACTION_SCRIPT_COUNT`); for a **script-server** query without `arg_num_indexes`, core pushes nothing ("omit the assert value until we are able to run an 'index' command through script server") | confirmed | [poller.php:410-445](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/poller.php#L410-L445), [data_query.php:672-690](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/data_query.php#L672-L690) |
| Same logic on `1.2.x` | The recache block and `query_script_host` text were not diffed beyond line shifts | unverified at B | |
| Script server input: cost per call | One request line `<file> <function> <params>` over a pipe to one persistent PHP process; the reply is a single `fgets` of up to 8,192 bytes; the text `ERROR` in the reply becomes `U`. Per-call cost against a cache table was not measured. | confirmed by reading; cost *live* | [poller.php:70-96](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/poller.php#L70-L96) |
| Script server input: a blocking script | In the PHP poller the read is a blocking `fgets` with no timeout; the setting `script_timeout` says it "is only valid for the spine Data Collector" (default 25 seconds). A script that blocks stalls its `cmd.php` process. | confirmed by reading | [global_settings.php:1339-1346](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/include/global_settings.php#L1339-L1346) |
| Script server file and function paths | The included file and the function's source must resolve under `base_path` (at B also the `scripts_path` setting); the function must return a scalar and print nothing | confirmed at both refs (reused, re-read) | [R script_server.php:260-351](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/script_server.php#L260-L351), [B script_server.php:295-369](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/script_server.php#L295-L369) |
| Polled values are stamped at write time | `poller_output.time` is `CURRENT_TIMESTAMP()` ([cmd.php:399-402](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/cmd.php#L399-L402)) | confirmed for `cmd.php` | see [mad-budget.md](mad-budget.md) |
| A device's items run in one `cmd.php` process; Spine can split a device by `device_threads` | [poller.php:693-720](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/poller.php#L693-L720), [spine.c:482](https://github.com/Cacti/spine/blob/c81b1c07aff3d16e4b9fe5371a194b5d88ebe245/spine.c#L482) | confirmed | |

## Data sources

| Plan item | Result | Status | Source |
|---|---|---|---|
| DS names | `data_template_rrd.data_source_name` is `varchar(19)`; the template form validates `^[a-zA-Z0-9_]{1,19}$` at both refs (reused, re-read). The long InfiniBand counter names (for example `PortRcvRemotePhysicalErrors`, 27 characters) cannot be used as DS names. | confirmed | [cacti.sql:1573](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/cacti.sql#L1573), [data_templates.php:149](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/data_templates.php#L149) |
| COUNTER `max` | `rrd_maximum` may hold a data query variable (substituted at RRD creation); a value `<=` the minimum becomes `U` only for GAUGE and ABSOLUTE and becomes `min + 1` for COUNTER, so `0` on a COUNTER becomes 1. The block is identical at both refs. | confirmed by reading, not run | [rrd.php:690-712](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/rrd.php#L690-L712); see [counters.md](counters.md) |
| Values above `max` | Handled by RRDtool at update time (stored as unknown); Cacti does not filter them. Confirmed by the offline RRDtool run in [counters.md](counters.md), not by running Cacti's update path. | partly confirmed | |
| Device template availability | `host_template` has `id`, `hash`, `name`, `class` only; availability is per device (`host.availability_method`), set through `api_device_save()` (reused, re-read; identical table at both refs) | confirmed | [cacti.sql host_template](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/cacti.sql) |

## Remote collectors

| Plan item | Result | Status |
|---|---|---|
| `remote_agent` authorization | Peer address only; B reads `REMOTE_ADDR` and documents that proxies make every client appear as the proxy | confirmed |
| `call_remote_data_collector()` | exists with the same signature at both refs | confirmed |
| Connection modes | `include/global.php` sets `online` (default), `recovery`, or `offline`; offline takes precedence; R lines 306-355, B lines 320-369 | confirmed |
| Plugin tables replicate with `replicate_out` | hook called from `replicate_out`/poller sync code at both refs | confirmed |
| Remote job writes to which database | Reused finding: on a collector CLI jobs use the local database by default; web requests switch to the main database only when it is reachable and the mode is not `recovery` | reused, not re-derived |

## Alerting

| Plan item | Result | Status | Source |
|---|---|---|---|
| Thold registers `poller_output` and `poller_bottom` | `thold_poller_output` and `thold_poller_bottom` registered in `setup.php` lines 78 and 94 | confirmed | [setup.php:78, 94](https://github.com/Cacti/plugin_thold/blob/dc76952ee52e8b4877b2d5b830caab1f7c43dde9/setup.php#L78) |
| Maint hook and Thold's check | Maint registers `is_device_in_maintenance` (setup.php:83, 184), but nothing in core, Thold, or Cacti calls that hook name. Thold requires Maint's functions file and calls `plugin_maint_check_cacti_host($thold_data['host_id'])` when `api_plugin_is_enabled('maint')` and `function_exists(...)`. Core calls the same function from `lib/snmpagent.php:436`. | confirmed | [maint setup.php:83](https://github.com/Cacti/plugin_maint/blob/9422c7a4ce9916d0f0edf88b93afb194e0675b4a/setup.php#L83), [thold includes/functions.php:3424-3432](https://github.com/Cacti/plugin_thold/blob/dc76952ee52e8b4877b2d5b830caab1f7c43dde9/includes/functions.php#L3424-L3432) |
| Maint on remote collectors | Maint declares `remote_collect:0` | confirmed | [maint INFO](https://github.com/Cacti/plugin_maint/blob/9422c7a4ce9916d0f0edf88b93afb194e0675b4a/INFO) |
| Thold sees only polled data sources | Values reach it through `poller_output` | confirmed | thold setup.php |

## Credential store (answers a plan assumption)

At `release/1.2.31` core has no secret-encryption helper a plugin can call. On `1.2.x` core has `cacti_encrypt_secret()` and `cacti_decrypt_secret()` (AES-256-GCM) with the key kept in the `settings` table; they were added for core's shared SNMP auth cache and are absent from 1.2.31. See [ufm-rest.md](ufm-rest.md). Status: confirmed by grep at both refs.

## Plan corrections

1. "Cacti core 1.2.31" table, Maint row (wrong, as in plugin_cloud): replace with the two-part fact above, and change "Maint's device maintenance hook" in the Measurement state table to "Maint's `plugin_maint_check_cacti_host()` (guarded function call)". The "Maintenance" paragraph states that Maint works with no change, which stays true for Thold alerts; Maint cannot suppress on a remote collector.
2. Script query row (imprecise): add that a script-server data query needs `arg_num_indexes` for reindex detection, and that `output_delimiter` must not be `:` for `<GUID>:<port>` indexes.
3. Cacti objects, Data sources row: state that DS names are at most 19 characters of `[A-Za-z0-9_]` and that `max` must be set explicitly (0 becomes 1 on COUNTER), optionally from a data query variable.
4. Compatibility, "Plugin lifecycle and hooks" bullet: append the entry-point rule (register hooks and realms from `plugin_fabric_install()` and `plugin_fabric_upgrade()` directly) and the `api_plugin_db_table_create()` and `exec_background()` differences.
5. Compatibility, "Plugin metadata": append that `poller_bottom` on a collector needs `remote_poller:1`.
6. Upstream behavior, script server: add that the PHP poller has no timeout on the script server read.
7. The statement "Milestone 1 records any difference on the current `1.2.x` branch": none of the fifteen signatures differ; the differences are the four above.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #24 | Run a script server data query on a device with a `<GUID>:<port>` index, with `output_delimiter` set to `:` and to another character | That the colon splits as read |
| #24 | Add and remove a port in the cache and check whether "Index Count Changed" fires for a script-server query with and without `arg_num_indexes` | The reindex rule |
| #24 | Create a COUNTER data source from a template with `rrd_maximum` 0, and with a data query variable | The created `max` |
| #24 | Time script server calls that read a cache row, at 100 and 10,000 data sources per poller | Per-call cost |
| #24 | Block a script server function under the PHP poller and under Spine | The behavior without and with `script_timeout` |
| #24 | Run `remote_agent` and `poller_bottom` on a collector with and without `remote_poller:1`, in online, offline, and recovery modes | The remote hook set |

## Limits

Source reading at two refs. 1.2.x equivalents of the recache and `query_script_host` text were not diffed line by line. Spine's write path and its script server handling were not read beyond the host query. Thold and Maint were read at their default branches only.
