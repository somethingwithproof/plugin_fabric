# Counter widths, saturation, reset, and RRD handling

Research for [#15](https://github.com/somethingwithproof/plugin_fabric/issues/15), with input to the decision [#8](https://github.com/somethingwithproof/plugin_fabric/issues/8), run 2026-11-15. Questions: what do ports do when basic counters reach their cap, when another tool resets them, and when a node reboots or a link retrains; and what `max` on an RRDtool COUNTER data source turns a reset into a gap instead of a spike?

**Result.** The hardware half is *live*: no switch or HCA was available, so cap, reset, reboot, and flap behavior is not recorded for any model. What could be settled offline is the RRDtool half, by running synthetic series (stated as synthetic, not captured) through a COUNTER data source with and without `max` (script in [tests/rrd-counter](../../tests/rrd-counter/run.sh), RRDtool 1.11.0 on the research host). The result changes the plan: a `max` turns a reset into an unknown value only when the false rate RRDtool computes exceeds it, and for two common cases it does not. For counters 16 bits or narrower, a `max` equal to the counter's cap per interval always catches a reset. For a 64-bit data counter reset from a value below 2^32, and for 32-bit counters generally, no usable `max` separates a reset from real traffic, so the plan's second guard (compare each sample with the previous and publish unknown after a decrease) is required, not optional. A capped counter stores a rate of 0, indistinguishable by RRDtool from an idle port. Cacti lets the `max` be filled per port from a data query field, which makes per-port, per-speed maxima possible.

## Method

- Counter widths from rdma-core `v66.0` `libibmad/fields.c` and the Linux sysfs table (v6.12), which agree.
- RRDtool behavior from the rrdcreate documentation and by running the script. Series start on a step boundary because unaligned update times make RRDtool spread one sample across several rows (the first run showed that artifact and was corrected before the numbers below).
- Cacti's handling of a data source `max` from `lib/rrd.php` at `release/1.2.31` (`1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1`) and the head of `refs/remotes/origin/1.2.x` (`e2d5c25893221c3cd54ceb8293fba6ca38c936e6`).

## Widths and where a 64-bit form exists

| Counter | Basic width | 64-bit form | Status | Source |
|---|---|---|---|---|
| SymbolErrorCounter, PortRcvErrors, PortRcvRemotePhysicalErrors, PortRcvSwitchRelayErrors, PortXmitDiscards, VL15Dropped | 16 | Only if `CapabilityMask2` has additional-extended-counters support | confirmed | [fields.c:243-262, 984-1001](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibmad/fields.c#L243-L262) |
| LinkErrorRecoveryCounter, LinkDownedCounter, PortXmitConstraintErrors, PortRcvConstraintErrors | 8 | same | confirmed | same |
| LocalLinkIntegrityErrors, ExcessiveBufferOverrunErrors | 4 | same | confirmed | same |
| PortXmitData, PortRcvData, PortXmitPkts, PortRcvPkts, PortXmitWait | 32 | Data and packets: 64-bit under `-x` always printed. PortXmitWait: only with the additional-extended bit. | confirmed | [perfquery.c:308-329](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L308-L329) |
| Which hardware sets the additional-extended bit | not recorded | none | *live* | none |
| Linux `counters/` files use the same widths; `port_xmit_wait` stays 32-bit even when the data counters switch to 64-bit | same widths; no wider form for `port_xmit_wait` | confirmed | [sysfs.c:643-671, 690-736](https://github.com/torvalds/linux/blob/v6.12/drivers/infiniband/core/sysfs.c#L643-L671) |

The widths are narrow enough that a 4-bit counter reaches 15 and an 8-bit counter reaches 255 in normal operation. A `LinkDownedCounter` that has seen 255 link-downs over the life of a port stays at 255 if the counter caps, and it can never be reset by this plugin.

## Cap behavior

| Fact | Status | Source |
|---|---|---|
| `perfquery.c` states "IB semantics is to cap counters if count has exceeded limits" and caps its own aggregates at the field maximum | confirmed as a source comment about the tool | [perfquery.c:110-125](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L110-L125) |
| The InfiniBand Architecture Specification text for counter saturation | NOT FOUND. The specification is member-gated; no public copy was reached. | unverified |
| What a real port reads after reaching its cap, on any model | *live* | |
| What other tools or the SM do to counters (resets from a second host, SM-driven clears) | *live* | |
| Which counters restart from zero on reboot and on link retraining, per model | *live* | |
| A capped counter looks like an idle counter to RRDtool (rate 0), see the experiment below | confirmed (synthetic series) | script output |

## RRDtool experiment

Synthetic series at a 60 second step, COUNTER, heartbeat 120, min 0. The stored rate at the sample where the reset occurs, per second:

| Case | `max` | Stored rate at the reset sample | Result |
|---|---|---|---|
| 32-bit counter (PortXmitWait) reset from 3,000,000,000 to 0 | none | 2.16e7 | spike |
| same | 1,000,000 | unknown | gap |
| 8-bit counter (LinkDownedCounter) capped at 255, then reset to 0 | none | 7.16e7 | spike |
| same | 10 | unknown | gap |
| 64-bit data counter reset from 9e12 (above 2^32) | none | 3.07e17 | spike |
| same | 3e10 | unknown | gap |
| 64-bit data counter reset from 3,000,003,000 (below 2^32) | none | 2.16e7 | spike |
| same | 3e10 | 2.16e7 | **spike remains** |
| 32-bit counter pinned at 4,294,967,295 | none or 1,000,000 | 0 | looks idle |

The mechanism, from the rrdcreate documentation and the numbers: on a decrease RRDtool assumes a 32-bit wrap first, then a 64-bit wrap. The false rate is `(2^32 - previous + new) / dt` for a previous value below 2^32 and about `2^64 / dt` above it. A `max` protects only when the false rate is larger than any real rate:

| Counter class | Largest real rate | False rate after a reset | Does a `max` help? |
|---|---|---|---|
| 4, 8, 16-bit counters | at most `cap / dt` | at least `(2^32 - cap) / dt` | Yes. `max = cap / step` always separates them. |
| 32-bit counters | up to `(2^32 - 1) / dt` | anywhere from near zero to `2^32 / dt` | No `max` separates them in general. Needs the plugin's own decrease check. |
| 64-bit counters, previous above 2^32 | link capacity | about `(2^64 - previous + new) / dt`, at most `2^64 / dt` | Yes, with `max` near link capacity, unless previous is within link capacity times `dt` of 2^64 (then the false rate is small); those resets need the plugin's decrease check. |
| 64-bit counters, previous below 2^32 | link capacity | up to `2^32 / dt`, below any link-capacity `max` | No. Needs the plugin's own check. |

For data counters, a link-capacity `max` in words (octets divided by 4) per second is `lanes x per-lane Gbit/s x 1e9 / 8 / 4`, using the per-lane rates the tools print as the upper bound (raw signalling, so slightly above payload). Examples: 4X HDR 6.64e9, 4X NDR 1.33e10, 4X XDR 2.66e10, 12X XDR 7.97e10 words per second. These are derived from the rates in `ibnd_dump_agg_linkspeedext` ([ibnetdisc.c:1084-1115](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibnetdisc/ibnetdisc.c#L1084-L1115)), not measured.

## Cacti's handling of `max`

| Fact | Result | Status | Source |
|---|---|---|---|
| The data source `max` can be a data query field | `rrd_maximum` containing a `query_<field>` variable (written between vertical bars in the template) is substituted with `substitute_snmp_query_data()` at RRD creation; the `ifSpeed` and `ifHighSpeed` variables are special-cased to a computed speed | confirmed at both refs (the block is identical) | [rrd.php:690-710](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/rrd.php#L690-L710) |
| A `max` of 0 on a COUNTER data source becomes 1 | When `rrd_maximum <= rrd_minimum` and the type is neither GAUGE (1) nor ABSOLUTE (4), `max` becomes `min + 1`. For COUNTER (2) with min 0 and max 0 that is 1. Only GAUGE and ABSOLUTE get `U`. | confirmed by reading; not executed | [rrd.php:703-712](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/rrd.php#L703-L712) |
| The `max` is applied when the RRD file is created; a later change needs `rrdtool tune` | Cacti has `rrdtool_function_tune()` ([rrd.php:914](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/rrd.php#L914)). A link that changes speed after creation keeps its old `max` unless something tunes it. | confirmed by reading; behavior on speed change not tested | same |
| `rrd_maximum` column is `varchar(20)` | `cacti.sql` | confirmed | [cacti.sql:1565](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/cacti.sql#L1565) |

A template must therefore set `max` explicitly (or `U`), never leave the default 0.

## Input to decision #8 (saturating basic counters)

The decision issue lists three options. This research adds:

- The proposed option (prefer 64-bit, mark capped counters saturated and publish unknown) is workable only if the plugin can tell "capped" from "idle". RRDtool cannot (row above), so the plugin must test a basic counter against its width's all-ones value across two samples. That test is cheap and needs the width table above.
- It also needs to know which ports return 64-bit error counters. The tool prints the capability masks in each counter header, so the plugin can record them per port; which hardware sets the bit is *live*.
- The "opt-in reset" option does not help with the false-rate problem and conflicts with ADR 0004; nothing found here changes that.
- "Events only" fits the 4-bit and 8-bit counters, where the lifetime cap is reached quickly, but loses rates for 16-bit error counters that stay useful for a long time.
- The decision should stay open until the hardware checks run. The offline evidence supports the proposed option's mechanism, not its coverage.

## Plan and ADR consequences

- Plan "Counters" bullet "Data sources are COUNTER type with a `max` chosen per width, so a reset or reboot becomes a gap instead of a spike" is true only for counters of 16 bits or less and for 64-bit counters above 2^32. State that and keep the previous-sample check as a requirement.
- Plan facts-table row on widths: add the four omitted fields.
- ADR 0004 is unaffected.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #15 | On one switch and one HCA model, drive a basic counter to its cap and read it repeatedly | Cap behavior per counter and model |
| #15 | Reset counters with `perfquery -R` from a second host and watch a poller | What a poller sees after another tool resets |
| #15 | Reboot a node and flap a link; record which counters restart | Reboot and retrain behavior |
| #15 | Capture `CapMask` and `CapMask2` from `perfquery` and `perfquery -x` headers per model | Which hardware has 64-bit error counters and PortXmitWait |
| #8 | Feed recorded series from those runs into the script | Replaces the synthetic series |

## Limits

Synthetic series only. The RRDtool run used the host's RRDtool 1.11.0; Cacti's own `rrdtool update` path, its heartbeat and step settings, and the stored consolidated values were not exercised.
