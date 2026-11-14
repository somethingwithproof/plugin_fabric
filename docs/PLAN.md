# Cacti 1.2 InfiniBand Fabric Plugin Plan

Status: proposal, revision 1 (2026-11-14). Nothing described here as a plugin capability exists yet. Statements about infiniband-diags, UFM, the Linux kernel, RRDtool, Cacti core, and Thold describe upstream behavior and cite the file or page that was checked. Anything not checked is listed under [Assumptions to confirm](#assumptions-to-confirm) and has a research issue in milestone 1. Owner and estimates are not assigned; milestone 1 produces the estimates.

`plugin_fabric` brings InfiniBand port counters, link width and speed, and congestion indicators into Cacti 1.2 as ordinary devices, data sources, and graphs, so operators see fabric health next to the hosts and switches it belongs to, under Cacti's own permissions, and alert on it with Thold and Maint.

## Why this plugin

A link that trained at 1X instead of 4X still passes traffic. Message-passing jobs that were tuned for the full link slow down, and nothing in an SNMP interface graph shows why, because the InfiniBand port state is "active" and the interface counters of the host look normal. The information is in the fabric: each port reports its active, enabled, and supported link width and speed, and a set of error and congestion counters. Reading it needs the InfiniBand management tools or a fabric manager API, not SNMP.

Running those tools by hand or from scripts leaves gaps:

1. **No history.** `perfquery` and `iblinkinfo` report the present. A link that degraded last Tuesday, or a counter that began climbing before a job slowed down, is visible only if someone was recording.
2. **No device context.** Fabric ports are not Cacti objects, so the port that feeds a host cannot be graphed beside that host's CPU and Ethernet interfaces.
3. **No path into Cacti alerting.** Thold evaluates Cacti data sources and Maint suppresses Cacti devices. A script that prints a warning reaches neither.
4. **Counters that cannot be trusted raw.** Basic counters cap at small values and other tools can reset them, so naive graphing of the raw numbers produces flat lines, spikes, or negative rates.

Existing InfiniBand exporters for other monitoring systems read the same tools (for example, [infiniband_exporter](https://github.com/treydock/infiniband_exporter) runs `ibnetdiscover` and `perfquery`, and its README documents that the poller user needs access to the UMAD device or `sudo`). This plugin takes the same sources into Cacti and adds the parts Cacti operators expect: devices, permissions, Thold, Maint, and remote data collectors.

The Cacti GitHub organization has no InfiniBand plugin today (searched 2026-11-14).

## Goals and non-goals

**Goals**

- A read-only view of port counters, link width and speed, and port state for the nodes of an InfiniBand fabric, with history in Cacti's RRD files.
- A precise, tested rule for "degraded link": active width or speed below the best both ends support, excluding links an administrator limited on purpose.
- Congestion indicators (PortXmitWait and discard counters) shown as rates with documented meaning.
- Identity by GUID and port number, never by LID or name, so history survives renames and re-addressing.
- Alerting and maintenance through Thold and Maint, so one notification path covers SNMP and fabric data.
- Least privilege: no root, no `sudo` by default, no shell, and no counter reset, ever.
- Remote data collectors for fabrics that a poller on the main server cannot reach.
- No required Cacti core patches.

**Non-goals**

- Replacing a fabric manager, a subnet manager, or UFM.
- Changing fabric state: no port enable or disable, no counter reset, no configuration writes.
- A plugin-owned incident, escalation, or notification engine.
- A new daemon, network protocol, or credential store.
- A reporting engine.
- Ethernet or RoCE fabrics (research).
- A public HTTP API or CLI in the first releases.

## Release phases

Each phase ships to users. Later phases are re-estimated after the phase before them.

| Phase | Label | Scope | Depends on |
|---|---|---|---|
| 0 | Research | Version matrix, upstream behavior confirmation, counter behavior on hardware, privilege model, MAD budget, UFM API (milestone 1) | Nothing |
| 1 | Planned | Observe mode with the diagnostics-tools backend: inventory by GUID, devices, port data query, counter and link-state data sources, Thold alerting, Maint suppression, health page | Phase 0 |
| 2 | Planned | UFM REST backend, credential handling, identity continuity between backends | Phase 1, security review |
| 3 | Planned | Remote data collectors, replication, `remote_agent` actions, sharded sweeps for large fabrics | Phase 2, security review |
| - | Research | Congestion control counters, cable and FEC data, topology events, RoCE, public API, host-side counter source, node-to-node latency probes | Demand after phase 1 |

## Compatibility

**Cacti.** The minimum Cacti release is 1.2.31, with no core patches. The plugin uses only APIs present in that release (each checked in `release/1.2.31`):

- Plugin lifecycle and hooks: `api_plugin_register_hook()`, `api_plugin_register_realm()`, and `api_plugin_db_table_create()` ([lib/plugins.php](https://github.com/Cacti/cacti/blob/release/1.2.31/lib/plugins.php)).
- Devices and permissions: `api_device_save()` ([lib/api_device.php](https://github.com/Cacti/cacti/blob/release/1.2.31/lib/api_device.php)), `is_device_allowed()` and `get_allowed_devices()` ([lib/auth.php](https://github.com/Cacti/cacti/blob/release/1.2.31/lib/auth.php)).
- Templates: `import_xml_data()` ([lib/import.php](https://github.com/Cacti/cacti/blob/release/1.2.31/lib/import.php)).
- Script data queries: `query_script_host()` and its `arg_index`, `arg_query`, `arg_get`, and `arg_num_indexes` elements ([lib/data_query.php](https://github.com/Cacti/cacti/blob/release/1.2.31/lib/data_query.php)).
- Background work: `exec_background()` and `register_process_start()` ([lib/poller.php](https://github.com/Cacti/cacti/blob/release/1.2.31/lib/poller.php)).
- Remote collectors: `call_remote_data_collector()` ([lib/functions.php](https://github.com/Cacti/cacti/blob/release/1.2.31/lib/functions.php)) and the `remote_agent` hook ([remote_agent.php](https://github.com/Cacti/cacti/blob/release/1.2.31/remote_agent.php)).

Milestone 1 records any difference on the current `1.2.x` branch.

**PHP.** The plugin requires PHP 8.2 or later, matching the Cacti 1.2.x floor. The `1.2.x` branch declares `"php": ">=8.2"` and pins Composer resolution to `platform.php: 8.2.0` ([branch composer.json](https://github.com/Cacti/cacti/blob/1.2.x/composer.json), Cacti/cacti#8569). PHP 8.0 and 8.1 are not supported, even on a 1.2.31 installation whose `composer.json` still says `>=8.0` ([release composer.json](https://github.com/Cacti/cacti/blob/release/1.2.31/composer.json)).

Because Cacti includes plugin files on every request, a parse error in a plugin breaks the whole Cacti UI. A 1.2.31 site can still run PHP 8.0 or 8.1, so:

- `setup.php`, `INFO`, and every file Cacti includes before the version check use only PHP 8.0 syntax.
- `plugin_fabric_check_config()` and `plugin_fabric_install()` refuse to proceed when `version_compare(PHP_VERSION, '8.2.0', '<')`, with a message naming the required version.
- Hook shims return early under the same condition, before the autoloader is registered, so no class file is parsed on an unsupported runtime.
- CI lints `setup.php` and the shim files under PHP 8.0.

Code loaded after the check uses language features through PHP 8.2 where they make the code clearer: `match`, enums, `readonly` properties and classes, first-class callable syntax, DNF types. Features from 8.3 and later are not used. Properties are always declared, because PHP 8.2 deprecates dynamic properties. PHPStan runs at `phpVersion` 8.2. No upper PHP bound is declared; the CI matrix defines the tested ceiling.

**Fabric tools.** The design was checked against rdma-core tag `v65.1`; the latest release when this plan was written is `v66.0`. Milestone 1 builds the matrix of packaged versions on each distribution with full vendor support, plus the vendor driver stack's tools, and drops lines no supported distribution ships.

**UFM.** The UFM backend (phase 2) targets the UFM Enterprise REST API as documented in the release 6200 guide path. Milestone 1 builds the matrix of releases operators run.

**Plugin metadata.** `INFO` declares `compat = 1.2.31` and the same remote-collector capability set that servcheck and webseer declare, confirmed in milestone 1.

## Upstream behavior the design depends on

Each row is a milestone 1 test, because a change in any of them changes the design. Source paths are at the pinned tag or release named in the table heading.

**infiniband-diags and libibmad, rdma-core `v65.1`.** Paths are under [linux-rdma/rdma-core at v65.1](https://github.com/linux-rdma/rdma-core/tree/v65.1).

| Fact | Consequence | Source |
|---|---|---|
| `perfquery` reads PerfMgt GMPs: PortCounters, PortExtendedCounters, and optional groups such as PortXmitDataSL, PortRcvErrorDetails, PortExtendedSpeedsCounters, and congestion-control groups. | Counters come from the performance management agent on each port, not from SNMP. | `infiniband-diags/man/perfquery.8.in.rst` |
| Data counters (PortXmitData, PortRcvData) are octets divided by 4. | Graph definitions multiply by 4 to show bytes. | `perfquery.8.in.rst` |
| `-x` shows extended counters; the attribute is optional, and `perfquery` warns when the PerfMgt ClassPortInfo capability mask indicates no extended counter support. | Extended counters must be probed per port, not assumed. | `perfquery.8.in.rst`; `infiniband-diags/perfquery.c` (`dump_perfcounters`) |
| Basic PortCounters field widths: SymbolErrorCounter 16 bits, LinkErrorRecoveryCounter 8, LinkDownedCounter 8, PortRcvErrors 16, PortXmitDiscards 16, VL15Dropped 16, LocalLinkIntegrityErrors 4, ExcessiveBufferOverrunErrors 4, PortXmitData, PortRcvData, PortXmitPkts, PortRcvPkts, and PortXmitWait 32. | Narrow counters reach their cap quickly. | `libibmad/fields.c` (PortCounters fields) |
| `perfquery.c` states that InfiniBand counter semantics cap a counter that exceeds its limit rather than wrap it. | A capped counter stops changing; it cannot be told from an idle counter without other evidence. | `infiniband-diags/perfquery.c` (comment above `aggregate_4bit`) |
| In basic PortCounters, PortXmitWait is used only when the capability mask indicates support (`IB_PM_PC_XMIT_WAIT_SUP`); otherwise `perfquery` sets it to zero. | A zero PortXmitWait can mean "unsupported". It must not be treated as "no congestion". | `perfquery.c` (`dump_perfcounters`) |
| Extended counters give 64-bit PortXmitData, PortRcvData, PortXmitPkts, PortRcvPkts, and, when supported, unicast and multicast packet counts. 64-bit forms of the error counters, including PortXmitWait, are printed only when the second capability mask indicates additional extended counters (`IB_PM_IS_ADDL_PORT_CTRS_EXT_SUP`). | 64-bit data counters are widely available; 64-bit error and wait counters are not guaranteed. | `perfquery.c` (`dump_perfcounters_ext`); `libibmad/fields.c` (extended and "More PortCountersExtended" fields) |
| `-r` resets after read; `-R` resets only. `ibqueryerrors` clears error counters with `-k` and data counters with `-K`, "regardless of if they are printed or not". | These options change fabric state seen by other tools and are never used. | `perfquery.8.in.rst`; `infiniband-diags/man/ibqueryerrors.8.in.rst` |
| `-a` aggregates counters over ports, `-l` loops per port, and port 255 selects all ports. Several ports can be given as a list or range. | One command can read many ports of a node, but aggregate output loses per-port values. The plugin must use the per-port form. | `perfquery.8.in.rst` |
| `iblinkinfo` reports link information for each port of each node and has a one-line mode (`--line`), partial scans, `--switches-only`, and `--cas-only`. | One scan yields width, speed, and state for every port. | `infiniband-diags/man/iblinkinfo.8.in.rst` |
| `iblinkinfo` prints `Could be <width>` for a port when its active width is not within the widest width supported at both ends. | This is the existing definition of a width downgrade and the basis of the plugin's rule. The speed side is checked in milestone 1. | `infiniband-diags/ibdiag_common.c` (`get_max_msg`); `infiniband-diags/iblinkinfo.c` |
| Link width values decode as 1 to 1X, 2 to 4X, 4 to 8X, 8 to 12X, and 16 to 2X. | The plugin stores lane counts as numbers using this mapping. | `libibmad/dump.c` (`mad_dump_linkwidth`) |
| `ibnetdiscover -p` prints one line per port: node type, LID, port number, port GUID, active width, and speed, followed by the remote node type, LID, port, GUID, and the two node descriptions. `--cache` saves a discovery for reuse. | Inventory and links come from one discovery run; LIDs appear but change. | `infiniband-diags/ibnetdiscover.c` (`dump_ports_report`); `infiniband-diags/man/ibnetdiscover.8.in.rst` |
| The node information record carries SystemGuid, node Guid, and PortGuid. | A node GUID is available separately from a port GUID. | `libibmad/fields.c` (NodeInfo fields) |

**Linux kernel, [v6.12](https://github.com/torvalds/linux/blob/v6.12/Documentation/ABI/stable/sysfs-class-infiniband).**

| Fact | Consequence | Source |
|---|---|---|
| Host adapters expose `node_guid`, and per port `lid`, `rate` (active width times active speed), `state`, `phys_state`, and a `counters/` directory with files including `symbol_error`, `link_downed`, `port_xmit_data`, and `port_xmit_wait`. | A host's own counters can be read with no management datagrams. Whether to use this is a milestone 1 question. | `Documentation/ABI/stable/sysfs-class-infiniband` |
| `port_xmit_wait` is described as ticks during which the port had data to transmit but did not send, for lack of credits or arbitration. | It indicates waiting, not a fault. The tick length is not given. | same file |

**NVIDIA UFM Enterprise REST API guide, release 6200 path.** Pages: [Ports](https://networking-docs.nvidia.com/ufmenterpriserestapi/6200/ports-rest-api), [Monitoring](https://networking-docs.nvidia.com/ufmenterpriserestapi/6200/monitoring-rest-api).

| Fact | Consequence | Source |
|---|---|---|
| `GET /ufmRest/resources/ports` returns ports, with filters including `sys_type`, `system`, `active`, `external`, `high_ber_only`, and `cable_info`. Per-port fields include `guid`, `number`, `system_name`, `physical_state`, `logical_state`, `active_width`, `enabled_width`, `supported_width`, `active_speed`, `enabled_speed`, `supported_speed`, `lid`, and peer fields. | Inventory, link state, and the data for the downgrade rule are available from one resource. | Ports REST API page |
| The monitoring API has `POST /ufmRest/monitoring/start` (create and start a session), `POST /ufmRest/monitoring/snapshot` (one-time session with data), and `GET /ufmRest/monitoring/attributes` (available counters). Counter attributes include `Infiniband_SymbolErrors`, `Infiniband_LinkDowned`, `Infiniband_XmitWait`, `Infiniband_RcvErrors`, `Infiniband_XmtDiscards`, and `_Delta` variants for several error counters. | A snapshot reads counters without leaving a session behind; milestone 1 confirms that. | Monitoring REST API page |
| The guide documents certificate-based client authentication under `/ufmRest/app/client_authentication/...`, with user and password as the fallback. | Authentication has more than one mode. Basic and token modes are in the assumptions table. | Client authentication page |

**ibdiagnet.** NVIDIA's [ibdiagnet dump file documentation](https://docs.nvidia.com/networking/display/ibdiagnetusermanualv219/ibdiagnet+dump+files) lists an `ibdiagnet2.db_csv` internal database and an `ibdiagnet2.net_dump` link dump among its output files; file sets differ by version. Whether and how the plugin uses it is a milestone 1 question.

**RRDtool.** The [rrdcreate documentation](https://oss.oetiker.ch/rrdtool/doc/rrdcreate.en.html) states that on a COUNTER overflow RRDtool checks whether the overflow happened at the 32-bit or 64-bit border, that a `max` setting on a 64-bit counter eliminates the possibility of mistaking a reset for a wrap, and that a value outside `min` and `max` is regarded as unknown. A reset therefore produces a large false rate unless `max` is set.

**Cacti core 1.2.31** (`release/1.2.31`).

| Fact | Consequence | Source |
|---|---|---|
| A script data query declares `script_path` with `arg_index`, `arg_query`, `arg_get`, and optionally `arg_num_indexes`; "Index Count Changed" detection depends on `arg_num_indexes` or on counting `arg_index` entries. | Ports can be indexes of a script query on the node's device. | `lib/data_query.php` (`query_script_host`) |
| `api_device_remove()` and `api_device_remove_multi()` call the `device_remove` hook; `api_data_source_remove()` calls `data_source_remove`. | A plugin can clear mappings when Cacti objects are removed. | `lib/api_device.php`, `lib/api_data_source.php` |
| `remote_agent.php` dispatches unknown actions to plugins through the `remote_agent` hook and authorizes callers with `remote_client_authorized()`. `call_remote_data_collector()` exists in `lib/functions.php`. | The main server can call plugin actions on a remote collector. | `remote_agent.php`, `lib/functions.php` |
| `include/global.php` sets the connection mode to `recovery` or `offline` for remote collectors depending on the main database state and local backlog. | Remote jobs must work in both modes. | `include/global.php` |
| Thold registers `poller_output` and `poller_bottom` hooks. | Only polled data sources reach Thold. | plugin_thold `setup.php` |

**Prior art.** The [infiniband_exporter README](https://github.com/treydock/infiniband_exporter#readme) records that `ibnetdiscover` and `perfquery` must be present, that a non-root user needs the UMAD device made accessible or `sudo` rules for exactly those two programs, and that collecting receive-error details costs one `perfquery` call per port.

### Assumptions to confirm

Not checked, and therefore not stated as fact. Each has a milestone 1 issue.

| Assumption | Why it matters |
|---|---|
| Switch external ports report a PortGUID that is distinct per port, or the switch's port 0 GUID. | Decides whether port identity is node GUID plus port number alone. |
| The length of a PortXmitWait tick. | Decides whether a time ratio can be shown. |
| Management datagram rates a fabric accepts from a monitoring node without disturbing the subnet manager or switch management CPUs. | Sets the minimum poll interval and the maximum fabric size per poller. |
| Which device nodes the tools open, their default permissions, and how to grant access to a dedicated user. | Defines the least-privilege model. |
| UFM token creation endpoint, token lifetime, read-only roles, response paging, and whether a snapshot creates server-side state. | Defines the UFM backend and its credential handling. |
| The speed side of the downgrade rule, including extended speeds and FDR10. | Completes the degraded-link rule. |
| `perfquery` on a node returns both basic and extended counters in two queries per port, and a port's capability mask is read once per sweep. | Sets the query count in the arithmetic below. |
| Colons and `0x` text in data query index values are safe in RRD file names and Cacti filters. | Decides the port index format. |
| Script server input suits a per-port value set at the required call rate. | Decides the poll path. |
| Whether Cacti has a credential store plugins can use for secrets at 1.2.31. | Decides UFM credential storage. |
| Counters behavior at the cap, after reset, and on reboot, on switch and HCA models. | Decides counter handling. |
| Subnet discovery works without a running subnet manager and with which adapter port. | Defines failure behavior. |
| The role of `ibdiagnet`. | Decides whether it is an input. |

## Architecture

```mermaid
flowchart TB
    subgraph Main["Cacti main server"]
        UI[Plugin pages] --> PERM[Cacti realms and device permissions]
        GR[Cacti graphs] --> PERM
        DB[(Cacti database: devices, data sources, plugin tables)]
        THOLD[Thold and Maint]
    end
    subgraph Poller["Cacti poller that can reach the fabric: main server or remote collector"]
        PB[poller_bottom] --> SW[Sweep job]
        SW --> RUN[Command runner: fixed argv, no shell]
        SW --> UFMC[UFM client, phase 2]
        SW --> CACHE[(Inventory and sample cache)]
        SS[Script server input] --> CACHE
        DQ[Port data query] --> CACHE
        RA[remote_agent actions]
    end
    subgraph Fabric["InfiniBand fabric"]
        TOOLS[infiniband-diags tools] --> NODES[Switches and adapters]
        UFM[UFM Enterprise] --> NODES
    end
    RUN --> TOOLS
    UFMC --> UFM
    SS -->|poller_output| THOLD
    UI -->|remote fabrics| RA
```

A fabric is watched by one poller: the main server or a remote data collector that can reach it. That poller does all fabric work. The main server never talks to the fabric directly.

| Component | Responsibility | Interfaces | Failure behavior |
|---|---|---|---|
| Plugin pages | Fabrics, inventory, mapping, ports, health | Cacti page framework, CSRF protection, realms | Show cached data with its age; never block on the fabric |
| Sweep job | Discover topology, read link state and counters, update the cache and health | `poller_bottom`, `exec_background()`, `register_process_start()`, filtered by `poller_id` | One instance per fabric; time budget; resumes next cycle; a failed port does not discard others |
| Command runner | Run the three allowlisted tools with fixed arguments | Process API with an argument array | Time and output limits; failure recorded; never a shell |
| UFM client (phase 2) | Read ports and counters over HTTPS | UFM REST API | Timeout; stale data shown as stale |
| Script server input | Return per-port values from the cache | Cacti script server | `U` for unknown or stale; `age` always numeric |
| Port data query | List ports of a node as indexes | Cacti script data query | Reads the inventory cache only |
| `remote_agent` actions (phase 3) | Inventory and health on a remote collector | `remote_agent` hook, `call_remote_data_collector()` | Collector unreachable means data shows as stale, never healthy |

### Collection backends

| Backend | Phase | What it provides | Requires |
|---|---|---|---|
| Diagnostics tools (`ibnetdiscover`, `iblinkinfo`, `perfquery`) | 1 | Topology, width and speed, counters, directly from the fabric | A node attached to the fabric, access to the management device, the tools installed |
| UFM REST | 2 | The same through the fabric manager | Network access to UFM and a read-only account |
| Host sysfs | Research | A host's own counters | A transport from the host to a poller |

Both phase 1 and phase 2 backends fill the same inventory and sample cache, so everything above the cache is backend independent.

### Topologies

1. **Poller attached to the fabric** (phase 1). The Cacti main server or a poller host has an HCA on the fabric and the tools installed.
2. **Poller reaches UFM over the network** (phase 2). No fabric attachment is needed on the poller.
3. **Remote data collector attached to the fabric** (phase 3). The collector runs the sweep and script server input; summary values reach the main server through the normal poller output path. The cost is a full Cacti remote collector (PHP, a web server, and a local database) on that host.

## Fabric identity and inventory

Identity is the one decision everything else depends on ([ADR 0003](decisions/0003-identify-by-guid-and-port-number.md)).

- A **node** (switch, channel adapter, or router) is identified by its node GUID.
- A **port** is identified by node GUID and port number, written `<node GUID>:<port>` with the GUID as 16 lowercase hexadecimal digits and a `0x` prefix.
- **LIDs** are assigned by the subnet manager and change. NodeDescription is operator-editable. Neither is identity. Both are shown as attributes.

Each node record has its type, GUID, display name, fabric, mapped Cacti device (if any), first and last seen times, and state. Each port record has its node, number, remote node and port, link state, and last sample.

**Discovery** runs the discovery tool through the command runner. A parse failure leaves the previous inventory in place and raises a health error. Two nodes reporting one GUID are recorded as a conflict and excluded from sweeps until an operator resolves them.

**Replacement.** A replaced adapter or switch gets a new GUID. When a node disappears and a new node appears with the same neighbor and port, the plugin proposes a re-link of the Cacti device and leaves both records in place until an operator confirms. It never re-links automatically.

**Unmapped nodes** are visible only to administrators until mapped to a device.

## Cacti objects

Ports appear as indexes of a script data query on the node's Cacti device ([decision pending](#open-decisions) on device granularity):

| Object | Created from | Notes |
|---|---|---|
| Device | "Fabric Node" device template, availability none unless an address exists, on the poller that sweeps the fabric | Existing devices can be mapped instead; created only on operator confirmation |
| Data query | "Fabric Port", a script query reading the inventory cache | Index `<node GUID>:<port>`; ports down with no remote end are skipped by default |
| Data sources | Data templates for traffic, errors, congestion, and link state per port | Layout chosen by milestone 1 measurements |
| State data source | "Fabric Port State" using the script server input | Polled; reaches Thold through `poller_output` |
| Graphs | Graph templates per group, plus a fabric overview | Placed in graph trees and protected by graph permissions like any Cacti graph |

Templates ship as Cacti template XML and are imported with `import_xml_data()` at install and upgrade, matched by hash so re-imports update rather than duplicate.

Devices created by the plugin appear in device lists and in plugins such as monitor and Thold. They carry the "Fabric Node" template so operators can filter them.

## Counters and link state

**Counters** are stored as received from the port.

- Basic counters are narrow and cap instead of wrapping (see the facts table). With no reset, a narrow counter eventually stops changing. The plugin uses the 64-bit forms where the port supports them, marks a capped basic counter as saturated and publishes unknown for it, and shows the reason on the port page. The alternative of resetting is rejected ([ADR 0004](decisions/0004-read-only-fabric-access.md)). A decision issue tracks the treatment and waits for hardware evidence.
- Data counters are octets divided by 4. Stored values stay in the tools' unit; graph definitions multiply by 4.
- Data sources are COUNTER type with a `max` chosen per width, so a reset or reboot becomes a gap instead of a spike. The plugin also compares each sample to the previous one and publishes unknown for one cycle after a decrease, and counts discontinuities on the port page.
- A value the port does not support is unknown, never `0`. This applies in particular to PortXmitWait in basic counters, which the tools zero when unsupported.

**Congestion.** PortXmitWait is graphed as ticks per second. It is converted to time or a ratio only if milestone 1 verifies the tick length. A high value shows that the port waited for credits or arbitration. It does not show a faulty port or cable. Transmit discards and constraint errors are graphed separately. The plugin ships no default congestion thresholds.

**Link state.** For each port the plugin records active, enabled, and supported width and speed, physical state, and logical state. A port is **degraded** when its active width or speed is below the best value supported at both ends, the rule `iblinkinfo` uses to print `Could be <width>`. A link whose enabled value is deliberately below supported is not degraded for that reason. A port that is down, or has no remote end, is down, not degraded. Unknown stays unknown. Milestone 1 completes the speed side and the fixtures: 4X to 1X, 4X to 2X, speed-only, administrative limit, and down.

## Measurement state

| Dimension | States | Evidence |
|---|---|---|
| Freshness | Current, delayed, stale, never observed | Sample observation time against the cycle length |
| Execution | Completed, partial, failed | Sweep result per port |
| Link | Up, degraded, down, unknown | The link-state rule above |
| Counter validity | Valid, discontinuity, saturated, unsupported | Comparison with the previous sample and the port's capability flags |
| Administrative | Enabled, disabled | Plugin records |
| Maintenance | In maintenance, not in maintenance | Maint's device maintenance hook |

Each cached sample carries observation time, ingestion time, backend, and the capability flags observed for the port. A recent page refresh never counts as a recent measurement.

## Alerting and maintenance

The "Fabric Port State" data source returns values from the cache:

| Value | Meaning | Missing or stale data |
|---|---|---|
| `active_lanes` | Active width as a lane count | `U` |
| `speed_rank` | Active speed as an ordered number, defined by milestone 1 | `U` |
| `degraded` | 1 when the port is degraded, else 0 | `U` |
| `port_state` | Logical state as a number | `U` |
| `age` | Seconds since the port's last sample | Always numeric once the port has been sampled |

Thold thresholds and Maint schedules apply with no change to either plugin ([ADR 0002](decisions/0002-alert-through-cacti-data-sources.md)). Counter rate data sources can carry thresholds too, for example on symbol errors; a discontinuity must not trigger them.

Stated limits:

- **Sampling alignment.** Counters are cumulative and Cacti computes rates from the time it stores each value. The sweep timing decision matters for this reason.
- **Summary only.** The state data source shows the present port state. History of counters is in the RRD files.
- **Overlap with other tools.** Operators may run UFM alarms or `ibqueryerrors` in cron. The plugin does not disable them.

**Maintenance.** Device maintenance works through Maint's device type with no change. Measurements continue during maintenance; only notifications are suppressed.

## Collection and the sweep cache

A sweep reads every watched port once per cycle and writes samples to a cache table. Nothing starts one process per port per poll ([ADR 0005](decisions/0005-collect-through-a-sweep-cache.md)). The sweep runs from `poller_bottom` through `exec_background()` as one `register_process_start()` process per fabric, with a time budget and bounded concurrency. Runs are idempotent: an interrupted run leaves the cache consistent and the next run continues.

When the sweep runs relative to the poll that stores values is an open decision: a lazy per-device sweep on the first script server call, or a prefetch. The choice depends on measurements in milestone 1.

Command lines are built from fixed templates and validated values. The builder cannot produce any reset or clear option, and a test enumerates what it can produce ([ADR 0004](decisions/0004-read-only-fabric-access.md)).

## UFM backend (phase 2)

The backend reads ports and counters from UFM with read-only requests and fills the same cache. Identity comes from the `guid` and `number` fields. It uses a monitoring snapshot rather than a persistent session if milestone 1 confirms that a snapshot leaves no server-side state. TLS verification is on by default. Credentials never appear on a command line, in a log, in an export, or on a page; their storage is a decision issue. Switching a fabric between backends keeps devices, indexes, and RRD files because identity is the same.

## Remote and scale (phase 3)

When the fabric is reachable only from a remote data collector, that collector runs the sweep and script server input, following the servcheck and webseer pattern:

- Plugin configuration tables replicate to collectors through the `replicate_out` hook.
- The sweep on each poller handles only fabrics assigned to that poller.
- The sweep writes the cache to the collector's local database; summary values reach the main server through the normal poller output path, which handles offline and recovery modes.
- The main server fetches inventory and health on demand through plugin actions in the `remote_agent` hook, called with `call_remote_data_collector()`. Actions are read-only and take fabric identifiers only.

`remote_agent` authorizes callers by address, which is Cacti's existing trust model for remote collectors. The documentation requires https between pollers and a restricted network path ([ADR 0006](decisions/0006-use-cacti-remote-collectors.md)).

A fabric too large for one poller's budget is split into shards of nodes, each assigned to one poller. Every node belongs to exactly one shard, and sharding changes neither identity nor RRD files.

## Code structure

Plugin code is object-oriented wherever Cacti's plugin contract allows it. Cacti calls plugins by function name, so these entry points stay procedural. Each is a thin shim, parseable on PHP 8.0, that checks the PHP version, validates input, and hands off to a class:

- `setup.php` lifecycle functions: `plugin_fabric_install()`, `plugin_fabric_uninstall()`, `plugin_fabric_version()`, `plugin_fabric_check_config()`, `plugin_fabric_upgrade()`.
- Hook callbacks such as `config_arrays`, `config_settings`, `draw_navigation_text`, `poller_bottom`, `replicate_out`, `remote_agent`, `device_remove`, and `data_source_remove`.
- Page scripts, the script server input function, and the sweep CLI entry point.

**Layout.**

- Classes live in `lib/*.php` under the `Cacti\Fabric` namespace, one class per file, loaded by a small `spl_autoload_register()` loader registered only after the PHP version check. No Composer runtime dependency is added. Files stay at depth 2 or less because the translation tooling scans only that deep.
- Settings use the `config_settings` hook. Logging uses `cacti_log()` with a `FABRIC` tag. Strings use the `fabric` text domain with a `locales/` directory and POT file.
- Realms: two, "Fabric View" and "Fabric Admin".

**Planned classes.** Names are illustrative; milestone 2 settles the set.

| Kind | Examples | Notes |
|---|---|---|
| Value objects | `Guid`, `PortRef`, `Sample`, `LinkState`, `Fabric` | `readonly` classes built by named constructors such as `Guid::fromString()` and `Fabric::fromRow()` |
| Enums | `Freshness`, `LinkCondition`, `CounterValidity`, `NodeType`, `Backend` | String-backed; stored as `varchar` values |
| Repositories | `NodeRepository`, `PortRepository`, `SampleRepository` | The only classes that issue SQL, always through prepared helpers; take an explicit connection |
| Boundaries | `CommandRunner`, `UfmClient`, `Clock` | Interfaces with real and fake implementations |
| Parsers | `PerfqueryParser`, `IblinkinfoParser`, `IbnetdiscoverParser` | Pure functions over recorded output |
| Services | `LinkStateEvaluator`, `CounterValidator`, `InventoryImporter`, `SweepPlanner` | Return decisions as objects; a runner applies side effects |

**Rules.** Dependencies come in through constructors, with no service locators and no static state apart from Cacti's globals, which only shims touch. `fromRow()` is the only place loose database values are normalized, and tests pin its behavior on empty strings, `NULL`, and malformed values.

## Storage, jobs, upgrades, and recovery

Plugin tables hold fabrics, nodes, ports, mappings, samples, discontinuity counts, and health. They are created and upgraded with Cacti's tracked plugin table API, and the stored plugin version changes only after a migration succeeds. The fabric is never written.

Disabling the plugin stops sweeps and leaves devices and RRD files in place. Uninstalling offers an export, asks whether to drop plugin tables, and asks whether to delete plugin-created devices; it never contacts the fabric. Backup documentation covers the Cacti database and RRD files. A restore test is part of release qualification.

## Performance

Capacity is stated in watched ports. Fixtures cover 100, 1,000, and 10,000 ports. The following is arithmetic from stated assumptions, not a measurement.

At 10,000 ports with a 300 second interval, two PerfMgt queries per port (basic and extended, an assumption above):

- 20,000 counter queries per cycle, about 67 per second on average. At a 60 second interval, about 333 per second.
- Topology and link state at the same cadence add at least one query per port, or are read less often; milestone 1 measures.
- Four RRD files per port gives 40,000 updates per cycle: about 133 per second at 300 seconds, 667 per second at 60 seconds.
- About 25 data source columns per port across those files, or about 250,000 `data_template_rrd` rows, and about 30,000 graphs if each port has three.

Engineering targets, to be measured, not yet demonstrated:

- Plugin overview page under two seconds at 10,000 ports.
- Cacti's device, data source, and graph pages within normal response time at that row count.
- No page request runs a command, queries UFM, or waits for a remote collector.
- The sweep stays within its time budget at 10,000 ports.
- A documented minimum poll interval derived from the measured MAD budget.

The setup screen shows expected queries per cycle and RRD updates per second before a bulk import.

## Security

| Boundary | Control |
|---|---|
| Browser to Cacti | Cacti session, CSRF protection, realms, device and graph permissions, prepared queries, contextual escaping |
| Cacti to the fabric (tools) | A dedicated user with access to the management device and nothing else; no `sudo` unless research concludes otherwise; allowlisted executables resolved from an absolute directory; argument arrays, never a shell; no operator text in command lines except validated values; time and output limits; reset and clear options impossible to compose; minimal environment |
| Cacti to UFM (phase 2) | HTTPS with verification on by default; read-only account; credentials never on a command line, in logs, exports, or pages; no redirect to another host; timeouts |
| Main server to remote collector (phase 3) | Cacti's `remote_agent` authorization and the configured https scheme; actions are read-only and accept identifiers only |
| Discovered data to the UI | GUIDs, node names, and tool output are untrusted text from the fabric: escaped on output, length-limited, and never used in file paths or commands |

Management access to a fabric is a privilege. A process that can open the management device can send more than counter reads, so granting it to the Cacti poller user is a real change in that host's exposure. Milestone 1 documents the exact grant per distribution and what a compromised poller user could do with it.

Phase 1 needs a review of the command runner and the argument builder before release. Phase 2 needs a security review covering credential storage and the UFM client. Phase 3 needs a review of the `remote_agent` actions.

## Testing

- **Fixtures.** Recorded output of the real tools for each matrix version: healthy fabric, 4X to 1X and 4X to 2X downgrades, speed-only downgrade, administrative limit, down port, capped counter, counter reset, reboot, link flap, duplicate GUID, replaced adapter. Fixtures are scrubbed of site names.
- **Fake runner.** A fake command runner with scripted output, delays, and failures replaces the tools in tests.
- **Matrix.** Cacti 1.2.31 and current `1.2.x`, on every PHP minor in the CI matrix. `setup.php` and shims are also linted under PHP 8.0.
- **Argument safety.** A test enumerates every argument vector the builder can produce and asserts none contains a reset or clear option; injection tests use shell metacharacters in names and port lists.
- **Counters.** Series with cap, reset, and wrap produce documented values and never a spike or a negative rate.
- **Permissions.** Every page, graph, and data source as an administrator, a scoped user, a user whose access was just revoked, and a user with no access to unmapped nodes.
- **Alerting.** Thold fires and recovers on a fixture downgrade; Maint suppresses it.
- **Remote.** Collector offline, recovery mode, replication after reconnect.
- **Failure scenarios before each phase release:** subnet manager unreachable, adapter port down, tool missing, permission denied on the management device, command timeout, partial discovery, clock skew, interrupted sweep, duplicate sweep start, interrupted upgrade.
- **Harness.** Use the harness maintained Cacti plugins use, and confirm the choice with maintainers before adding test or CI infrastructure.

## Milestones

| Milestone | Phase | Deliverables | Exit evidence |
|---|---|---|---|
| 1. Compatibility and upstream research | 0 | Version matrix; confirmation of every fact and assumption; counter behavior on hardware; downgrade rule; privilege model; MAD budget; UFM API; Cacti core confirmation; estimates | Every row confirmed or corrected; the timing and saturation decisions have evidence |
| 2. Foundation | 1 | PHP guard, lifecycle, realms, settings, class layout, fabric records, command boundary, inventory, sweep, link-state model, health, harness | Fresh install and upgrade at 1.2.31; install refused cleanly on PHP 8.1; unauthorized access rejected |
| 3. Cacti objects and alerting | 1 | Templates, device mapping, data query, counter and state data sources, discontinuity handling, congestion graphs, permissions, scale measurements | Fixture downgrade fires and clears a Thold alert; Maint suppresses it; resets never spike |
| 4. Phase 1 release | 1 | Documentation, packaging, restore test, failure qualification, pilots | Pilots on a small and a larger fabric |
| 5. UFM backend | 2 | UFM client, credentials, identity continuity, security review | Same fixture, same objects from either backend |
| 6. Remote and scale | 3 | Replication, `remote_agent` actions, sharded sweeps, security review | Offline, recovery, and sharding scenarios correct |

## Open decisions

| Decision | Options | Needed before | Decided by |
|---|---|---|---|
| Sweep timing | Lazy per-device sweep (proposed); prefetch; prefetch with sample time | Milestone 2 sweep work | Milestone 1 timing evidence |
| Saturated basic counters | Prefer 64-bit and publish unknown on saturation (proposed); opt-in reset; events only | Milestone 3 | Milestone 1 counter evidence |
| Device granularity | One device per node (proposed); one per fabric; switches only | Milestone 3 | Cacti maintainers |
| UFM credential storage | Cacti storage if available; file outside web root; environment | Phase 2 | Milestone 1 UFM research and security review |
| Repository and name | `Cacti/plugin_fabric`, `Cacti/plugin_infiniband`, or community plugin | Before any code is published | Cacti maintainers |
| Test harness | Whatever maintained plugins use | Milestone 2 | Cacti maintainers |

## Risks

| Risk | Effect | Mitigation |
|---|---|---|
| Polling load disturbs the subnet manager or switch management CPUs | Fabric instability caused by monitoring | Measured MAD budget, documented minimum interval, bounded concurrency, per-fabric time budget |
| Basic counters saturate with no reset | Flat graphs that look healthy | 64-bit counters where supported; saturation flagged and shown as unknown; documentation |
| Management device access is broader than counter reads | A compromised poller user gains fabric management reach | Documented least-privilege grant, allowlisted executables, no shell, review before release |
| PortXmitWait misread as a fault or as zero | Wrong conclusions or false comfort | Unit and unsupported state documented; no default thresholds; unsupported shown as unknown |
| GUID reuse or duplicate GUIDs | Wrong history attached to a device | Conflict state, re-link proposals only |
| Object counts slow Cacti at 10,000 ports | Poor experience for all Cacti users | Measure at milestone 3; document a supported port count |
| UFM API differs between releases | Backend breaks on upgrade | Matrix of releases, fixture servers, version check in health |
| Address-based `remote_agent` authorization | A host sharing a poller's address can call actions | https, restricted network, read-only actions |
| Volunteer capacity | Later phases stall | Each phase ships on its own |

## Research

Not scheduled; revisited after phase 1:

- Node-to-node latency distributions over the fabric as a probe profile of the SmokePing plugin proposal. This is cross-plugin research, with no dependency in either direction.
- Congestion control counters and per-VL data.
- Cable, transceiver, and FEC data.
- Topology change events.
- Ethernet and RoCE fabrics.
- A public API and CLI.
- A host-side collector for sysfs counters.

## Upstream process

This repository holds the proposal and tracks its requirements as GitHub issues and milestones. Before code is proposed for the Cacti organization, open an issue there describing the problem, the phase plan, and the open decisions, and ask maintainers about the repository, name, device handling, and test harness. Maint changes are proposed as issues on the Maint repository first. No Thold change is needed.
