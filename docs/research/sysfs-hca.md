# HCA counters from sysfs on the host

Research for [#23](https://github.com/somethingwithproof/plugin_fabric/issues/23), run 2026-11-15. Question: can the plugin read a host's own HCA counters from the Linux sysfs interface with no management datagrams and no special privilege, and when is that preferable?

**Result.** Partly. The files are world readable (mode `0444`) and need no device grant, which makes them the least-privileged source in the whole plan. They are not free of management datagrams: every read of a file in `counters/` makes the kernel build a PerfMgt MAD and hand it to the local device driver (`process_mad`) for that port. That datagram is handled by the local device, not sent into the fabric, but each file read is one local query, 17 files per port for the basic set. The counters have the same widths as the basic PortCounters, and `port_xmit_wait` stays 32-bit even when the data counters are 64-bit. The `hw_counters/` directory is a separate, driver-specific set read from hardware with a documented update lifespan (10 ms default). A Cacti poller on another host has no built-in way to read these files, so any use needs a transport: SNMP `extend` scripts or a Cacti remote data collector on each host. Recommendation: keep sysfs as research and as a documented later source for sites that cannot grant umad access; it does not replace the fabric-wide sweep, and it sees only the host's own ports, not switch ports or links. The side-by-side comparison with `perfquery` on one port needs hardware (*live*).

## Method

Linux v6.12: `Documentation/ABI/stable/sysfs-class-infiniband` and `drivers/infiniband/core/sysfs.c`. net-snmp `snmpd.conf` manual page at `master` (`33d75f8ead9e87f7f73f71a2753126819c31355b`). Nothing was run.

## Findings

| Fact | Result | Status | Source |
|---|---|---|---|
| Files per port | `/sys/class/infiniband/<device>/ports/<port-num>/` has `lid`, `rate`, `state`, `phys_state`, `sm_lid`, and `counters/` with `symbol_error`, `link_error_recovery`, `link_downed`, `port_rcv_errors`, `port_rcv_remote_physical_errors`, `port_rcv_switch_relay_errors`, `port_xmit_discards`, `port_xmit_constraint_errors`, `port_rcv_constraint_errors`, `local_link_integrity_errors`, `excessive_buffer_overrun_errors`, `VL15_dropped`, `port_xmit_data`, `port_rcv_data`, `port_xmit_packets`, `port_rcv_packets`, `port_xmit_wait`, plus unicast and multicast packet files in the extended set | confirmed | [sysfs-class-infiniband:39-105](https://github.com/torvalds/linux/blob/v6.12/Documentation/ABI/stable/sysfs-class-infiniband#L39-L105) |
| Mode | The PMA counter attributes are `S_IRUGO` (read by all) | confirmed | [sysfs.c:540-551](https://github.com/torvalds/linux/blob/v6.12/drivers/infiniband/core/sysfs.c#L540-L551) |
| Cost of one read | `show_pma_counter()` calls `get_perf_mad()`, which fills a PerfMgt Get MAD and calls the device's `process_mad` for that port; the reply is parsed for one field. It is a local call, not a send on the wire. | confirmed by reading | [sysfs.c:105-145, 602-640](https://github.com/torvalds/linux/blob/v6.12/drivers/infiniband/core/sysfs.c#L105-L145) |
| Basic or extended set chosen once | At port setup the kernel reads PerfMgt `ClassPortInfo` and picks the group: extended (`IB_PMA_CLASS_CAP_EXT_WIDTH`), no-IETF extended, or basic | confirmed | [sysfs.c:795-811](https://github.com/torvalds/linux/blob/v6.12/drivers/infiniband/core/sysfs.c#L795-L811) |
| Widths | Same as the basic PortCounters (16, 8, 8, 16, 16, 16, 16, 8, 8, 4, 4, 16); data and packet counters 32-bit basic or 64-bit in the extended groups; `port_xmit_wait` is 32-bit in all three groups | confirmed | [sysfs.c:643-671, 690-736](https://github.com/torvalds/linux/blob/v6.12/drivers/infiniband/core/sysfs.c#L643-L671) |
| `rate` | "Port data rate (active width * active speed)"; the code computes it in deci-Gb/s from the active speed names (DDR, QDR, FDR10, FDR, EDR, HDR, NDR, XDR) and width | confirmed | [sysfs-class-infiniband:55](https://github.com/torvalds/linux/blob/v6.12/Documentation/ABI/stable/sysfs-class-infiniband#L55), [sysfs.c:304-350](https://github.com/torvalds/linux/blob/v6.12/drivers/infiniband/core/sysfs.c#L304-L350) |
| `state` and `phys_state` | Printed as `"%d: %s"` (number and name) | confirmed for `state` | [sysfs.c:213-232](https://github.com/torvalds/linux/blob/v6.12/drivers/infiniband/core/sysfs.c#L213-L232) |
| What sysfs lacks for the plugin | No peer port, no switch ports, no enabled or supported width and speed, so the degraded-link rule cannot be evaluated from sysfs. Only local active values are available (the `rate` file). | confirmed by absence in the ABI document | same |
| `port_xmit_wait` | "ticks during which the port had data to transmit but no data was sent ... (either because of insufficient credits or because of lack of arbitration)"; no tick length | confirmed | [sysfs-class-infiniband](https://github.com/torvalds/linux/blob/v6.12/Documentation/ABI/stable/sysfs-class-infiniband) |
| `hw_counters/` | An optional per-device or per-port directory of driver-specific counters; "lifespan" sets how often the core refreshes them when read (milliseconds, 10 by default, 0 to 10000 settable by writing the file) | confirmed | [sysfs-class-infiniband:199-215](https://github.com/torvalds/linux/blob/v6.12/Documentation/ABI/stable/sysfs-class-infiniband#L199-L215) |
| Which counters `hw_counters/` holds on a vendor driver stack | Not examined | unverified; *live* | |
| The files on the matrix kernels and on the vendor driver stack | Checked at v6.12 only. The `Date` lines in the ABI document place `counters/` in v2.6.12 and `hw_counters` in v4.6. | confirmed for v6.12; other kernels *live* | same |

## Transport options for a Cacti poller

| Option | How | Trust and cost | Status |
|---|---|---|---|
| SNMP `extend` | `snmpd.conf` directive `extend [-cacheTime TIME] [-execType TYPE] [MIBOID] NAME PROG ARGS`; results appear in `nsExtendOutput1Table` and `nsExtendOutput2Table`; `-cacheTime` limits how often the program runs | The agent runs a program on request, as the snmpd user; community or v3 credentials guard read access; each value is a string the plugin must parse | confirmed (manual page); security posture of a site's snmpd *live* |
| Cacti remote data collector on each HCA host | Reuses ADR 0006 | A full Cacti remote collector (PHP, web server, database) per host; heavy for a counter file read | confirmed as the plan's only Cacti-native remote mechanism |
| A host agent of the plugin's own | | A new daemon and protocol; rejected by the plan's non-goals | rejected by plan |
| SSH from the poller | | Key management and a remote command path; rejected by ADR 0006 | rejected by plan |

Source: [snmpd.conf manual page](https://github.com/net-snmp/net-snmp/blob/33d75f8ead9e87f7f73f71a2753126819c31355b/man/snmpd.conf.5.def#L1403-L1420).

## Recommendation

Not a phase 1 source. The fabric-wide sweep needs peer and enabled data that only the management tools or UFM give, and sysfs cannot supply the degraded-link inputs. Keep it as a later, optional source for sites that cannot grant umad access, with `snmpd extend` as the transport and the same cache and identity model. This is a recommendation, not an owner decision.

If it is built: read the `counters/` files once per sweep per port, record the width of each file, and treat a missing file as unsupported, never as zero. Compare each file with `perfquery` on the same port first.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #23 | List `/sys/class/infiniband/*/ports/*/counters` and `hw_counters` on each matrix kernel and on the vendor stack | The file set per kernel and driver |
| #23 | Read each counter file and run `perfquery` and `perfquery -x` on the same port, close in time, on one HCA model | Whether values match, and the width of `port_xmit_wait` |
| #23 | Count the PerfMgt queries the HCA sees per read, if the HCA exposes the figure | The real cost of reading 17 files |
| #23 | Compare `hw_counters` names with PortCounters names on the vendor stack | Whether extra congestion counters are available |

## Limits

Source and documentation only, at Linux v6.12. No HCA, no vendor driver stack.
