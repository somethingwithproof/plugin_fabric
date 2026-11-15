# infiniband-diags versions and output behavior

Research for [#13](https://github.com/somethingwithproof/plugin_fabric/issues/13) and [#14](https://github.com/somethingwithproof/plugin_fabric/issues/14), run 2026-11-15. Questions: which rdma-core releases must the plugin support, which options and output formats do `perfquery`, `ibnetdiscover`, and `iblinkinfo` have in each, and does each row of the plan's infiniband-diags facts table hold across them?

**Result.** Every row of the plan's facts table holds in rdma-core `v66.0`, and the files that carry those facts are byte-identical between `v65.1` and `v66.0`, so the plan's `v65.1` pin can move to `v66.0` with no re-test. The option tables of the three tools are identical from `v28.0` to `v66.0`. Across the packaged versions that matter (`v39.0` to `v62.0`) three things differ: `perfquery.c` changes only in how it opens the port, `libibmad` gains the XDR speed fields in `v50.0` (absent in `v39.0`, `v44.0`, `v48.0`), and `--pkey-idx` appears in `v65.0`. Two plan statements need correction: `ibnetdiscover -p` and `iblinkinfo --line` print port GUIDs, not node GUIDs, so node GUID plus port cannot be built from them alone (details in [identity.md](identity.md)); and `perfquery` ends the whole process on the first failed query, which decides how sweeps are split. Nothing here was run against a fabric or against the tools themselves: no InfiniBand tools exist on the research host, so the "`-h` output" requirement of #13 is met from the option tables in source and the *live* items below remain.

## Method

rdma-core was cloned in full to a scratch directory (`git clone https://github.com/linux-rdma/rdma-core`) and read with `git show <tag>:<path>`. For each version the blob IDs of the relevant files were compared first; where blobs differ, the diff was read. Distribution versions come from Repology's `rdma-core` project API (queried 2026-11-15) and support end dates from endoflife.date; both are third-party aggregators and are listed as such. Nothing was built or executed.

## Sources pinned

| Name | Ref | Commit |
|---|---|---|
| rdma-core `v66.0` (latest release, tagged within the last week of this run) | tag | `0fa90c60fbc274edb3495f30c1afd02b3f5fd38e` |
| rdma-core `v65.1` (the plan's pin) | tag | `a30f609baa5c97f2f6da958913f49e35c12baa88` |
| `v62.0` | tag | `31af04ec84378724cb6256814d4ffde359a7123b` |
| `v61.0` | tag | `558104fc33266abe8a9deb50a81769d1a72fbf72` |
| `v57.0` | tag | `18696ad67a90a8e36621570d8a006abc598b6a13` |
| `v56.1` | tag | `45b7e6deffadbd81e6c95bb783926c051a5c0fa8` |
| `v54.0` | tag | `77128e6b6d32a1aad7c1bec9d6eb1f061b63cf39` |
| `v50.0` | tag | `bc6b4bc134532e952fe7f8efc251e1f89b912098` |
| `v48.0` | tag | `3e039515b959fea70356b010ed093e43cf360e99` |
| `v44.0` | tag | `91392a91eb41affee3bd75dc7d03eb86b4854aea` |
| `v39.0` | tag | `b2fedc4b1b891afd2c26ebfd21f9e440e83c28ff` |

## Version matrix

"Version" is the upstream rdma-core version the package tracks, from Repology. Distributions backport patches and enterprise releases move versions between minor releases, so the row is a starting point for the fixture matrix, not a statement about a specific package build. End dates are endoflife.date's "eol" field.

| Distribution | Support end (endoflife.date) | rdma-core (Repology) | Status |
|---|---|---|---|
| Ubuntu 22.04 | 2027-06-01 | 39.0 | unverified against the archive; keep until 2027-06 |
| Ubuntu 24.04 | 2029-05-31 | 50.0 | unverified against the archive |
| Ubuntu 26.04 | 2031-05-29 | 61.0 | unverified against the archive |
| Debian 12 | 2028-06-30 (LTS) | 44.0 | unverified against the archive |
| Debian 13 | 2030-06-30 | 56.1 | unverified against the archive |
| RHEL 8 family (Rocky 8, Alma 8 as proxy) | 2029-05-31 | 48.0 | RHEL itself is not in Repology; proxy only |
| RHEL 9 family (Rocky 9, Alma 9 as proxy) | 2032-05-31 | 61.0 | proxy only; CentOS Stream 9 shows 54.0, 57.0, 61.0 over time |
| RHEL 10 family | 2035-05-31 | CentOS Stream 10 shows 57.0, 61.0, 62.0 | proxy only |
| SLES 15 SP7 and 16 | 2031-07-31 and 2027-11-30 (extended 2034-07-31, 2030-11-30) | not in Repology (openSUSE Leap 15.6 is 49.1 and reached its end 2026-04-30) | unverified |
| NVIDIA DOCA-Host (replaces MLNX_OFED) | MLNX_OFED support ends October 2027 per a search summary, not read on the page | `infiniband-diags` version not found | unverified; *live* |

Packaging facts that are confirmed in the pinned tree: the Debian source builds a binary package `infiniband-diags` ([debian/control:288](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/debian/control#L288)); the RPM spec has `%package -n infiniband-diags` ([redhat/rdma-core.spec:128](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/redhat/rdma-core.spec#L128)) and the SUSE spec has the same ([suse/rdma-core.spec:351](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/suse/rdma-core.spec#L351)). The Debian install list puts `iblinkinfo` and `ibnetdiscover` in `usr/sbin` ([debian/infiniband-diags.install](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/debian/infiniband-diags.install)); the plugin must therefore not assume `/usr/bin`. A default `/etc/infiniband-diags/ibdiag.conf` ships in the same package.

Lines to drop from the plan's support list because no listed distribution with current support ships them: rdma-core 28.0 (Ubuntu 20.04, ended 2025-05-31), 33.2 (Debian 11, ended 2026-08-31), 35.0 (CentOS 8), 42.0 and 49.1 (Leap 15.5 and 15.6, ended). That leaves a fixture matrix of `v39.0`, `v44.0`, `v48.0`, `v50.0`, `v56.1`, `v61.0`, and `v66.0` (the last standing in for the vendor stack and Tumbleweed-class builds). `v54.0`, `v57.0`, and `v62.0` are covered by the identity results below.

## What differs between versions

Blob comparison of the files the plan relies on (git blob IDs, shortened):

| File | v39.0 | v48.0 | v50.0 | v54.0 | v56.1 | v61.0 | v62.0 | v65.1 | v66.0 |
|---|---|---|---|---|---|---|---|---|---|
| `infiniband-diags/perfquery.c` | c7d320d | c7d320d | c7d320d | c7d320d | 477687b | 477687b | 477687b | 477687b | 477687b |
| `infiniband-diags/ibnetdiscover.c` | 9dcdf40 | 9dcdf40 | 1b05527 | 1b05527 | 1b05527 | 1b05527 | 1b05527 | 1b05527 | 1b05527 |
| `infiniband-diags/iblinkinfo.c` | afd2f4e | afd2f4e | b6c6d06 | b6c6d06 | 8c9d8f1 | 8c9d8f1 | 8c9d8f1 | 2f05425 | 2f05425 |
| `infiniband-diags/ibdiag_common.c` | a06438e | a06438e | 9604dd9 | 9604dd9 | 89f955e | 89f955e | 89f955e | 69b3948 | 69b3948 |
| `libibmad/fields.c` | 76a53af | 76a53af | 85477ba | 85477ba | 85477ba | 85477ba | 85477ba | 85477ba | 85477ba |
| `libibmad/dump.c` | 2dd10aa | 2dd10aa | c4933b5 | c4933b5 | c4933b5 | c4933b5 | c4933b5 | c4933b5 | c4933b5 |

| Difference | Where it starts | Effect on the plugin | Status |
|---|---|---|---|
| `perfquery.c` | `v56.1` | Source diff `v39.0` to `v66.0`, ignoring whitespace: only the change from one opened MAD port to a pair (`mad_rpc_open_port2`, `srcports->smi.port`). Counter fields, capability checks, and options are unchanged. | confirmed |
| XDR extended speed fields (`LinkSpeedExtActive2`, `LinkSpeedExtSupported2`, `LinkSpeedExtEnabled2`; 212.5 Gbps) | `v50.0` | `v39.0`, `v44.0`, `v48.0` (Ubuntu 22.04, Debian 12, RHEL 8 family) have no decoding for XDR in `libibmad` or `ibnetdiscover`. What these builds print for an XDR link is not known. | confirmed absent; behavior on XDR hardware is *live* |
| `iblinkinfo` and `get_max_msg` rewritten to aggregate extended speeds across the two speed fields | `v50.0` to `v65.1` (the speed side of the rule changed shape, not meaning) | The downgrade rule below holds for `v66.0`; on `v39.0` to `v48.0` the extended-speed branch compares the single `LinkSpeedExt` fields and cannot see XDR. | confirmed by diff |
| `--pkey-idx` / `-Y` in the common options | `v65.0` | Not used by the plugin. | confirmed |

## Option sets

The `-h` output of each tool is generated from the option table in the tool and in `ibdiag_common.c`. The tool-specific tables were extracted from source for `v28.0, v33.2, v35.0, v39.0, v44.0, v48.0, v49.1, v50.0, v54.0, v56.1, v57.0, v58.0, v61.0, v62.0, v65.1, v66.0`. They are identical in all sixteen. This is not a captured `-h` run (*live*, see below).

| Tool | Tool-specific long options (short form where it has one) |
|---|---|
| `perfquery` | `extended` (x), `xmtsl` (X), `rcvsl` (S), `xmtdisc` (D), `rcverr` (E), `extended_speeds` (T), `oprcvcounters`, `flowctlcounters`, `vloppackets`, `vlopdata`, `vlxmitflowctlerrors`, `vlxmitcounters`, `swportvlcong`, `rcvcc`, `slrcvfecn`, `slrcvbecn`, `xmitcc`, `vlxmittimecc`, `smplctl` (c), `all_ports` (a), `loop_ports` (l), `reset_after_read` (r), `Reset_only` (R) |
| `ibnetdiscover` | `full` (f), `show` (s), `list` (l), `grouping` (g), `Hca_list` (H), `Switch_list` (S), `Router_list` (R), `node-name-map`, `cache`, `load-cache`, `diff`, `diffcheck`, `ports` (p), `max_hops` (m), `outstanding_smps` (o) |
| `iblinkinfo` | `node-name-map`, `switch` (S), `port-guid` (G), `Direct` (D), `all` (a), `hops` (n), `down` (d), `line` (l), `additional` (p), `load-cache`, `diff`, `diffcheck`, `filterdownports`, `outstanding_smps` (o), `switches-only`, `cas-only` |

Sources: [perfquery.c:833-856](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L833-L856) at `v66.0`, and the matching tables in `ibnetdiscover.c` and `iblinkinfo.c`. The only common option the plugin must set is `-t` (timeout) and `-C`/`-P` (adapter and port); `-t` is documented as overriding "the default timeout for the solicited mads" ([opt_t.rst](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/man/common/opt_t.rst)).

## Facts table, re-checked

Plan rows (plan section "infiniband-diags and libibmad") against `v66.0` unless stated. Line numbers are in the `v66.0` tree.

| Plan fact | Result | Status | Source |
|---|---|---|---|
| `perfquery` reads PerfMgt GMPs including PortCounters and PortExtendedCounters | Holds. `dump_perfcounters` queries `IB_GSI_PORT_COUNTERS` or `IB_GSI_PORT_COUNTERS_EXT`. | confirmed | [perfquery.c:401](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L401) |
| Data counters are octets divided by 4 | Holds for the man page: "octets divided by 4 rather than just octets". | confirmed | [perfquery.8.in.rst:30](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/man/perfquery.8.in.rst#L30) |
| `-x` warns when the capability mask shows no extended support | Holds, with a precision: the warning fires only when neither `IB_PM_EXT_WIDTH_SUPPORTED` nor `IB_PM_EXT_WIDTH_NOIETF_SUP` is set, and the query is still sent and printed. | confirmed | [perfquery.c:428-438](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L428-L438) |
| Basic field widths (SymbolError 16, LinkErrorRecovery 8, LinkDowned 8, PortRcvErrors 16, PortXmitDiscards 16, VL15Dropped 16, LocalLinkIntegrityErrors 4, ExcessiveBufferOverrunErrors 4, data and packet counters and PortXmitWait 32) | Holds. The plan omits three: PortRcvRemotePhysicalErrors 16, PortRcvSwitchRelayErrors 16, and PortXmitConstraintErrors and PortRcvConstraintErrors 8 each. The Linux sysfs file widths agree ([sysfs.c:643-659](https://github.com/torvalds/linux/blob/v6.12/drivers/infiniband/core/sysfs.c#L643-L659)). | confirmed, plan incomplete | [fields.c:243-262](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibmad/fields.c#L243-L262) |
| IB semantics cap a counter rather than wrap it | The comment exists. It is a source comment about how the tool aggregates, not evidence about what a port does at its cap. | confirmed as a comment; port behavior *live* ([counters.md](counters.md)) | [perfquery.c:110-115](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L110-L115) |
| Unsupported PortXmitWait is printed as zero | Holds: when `IB_PM_PC_XMIT_WAIT_SUP` is clear, the tool sets the field to 0 and still prints `PortXmitWait:0`, so the output alone cannot tell "unsupported" from "no waits". The capability mask is printed in the header line (`CapMask: 0x%02X`), and the plugin can read it from there. | confirmed | [perfquery.c:412-418, 456-462](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L412-L418) |
| Extended counters: 64-bit data counters; 64-bit error counters and PortXmitWait only with `IB_PM_IS_ADDL_PORT_CTRS_EXT_SUP` | Holds, with detail: `-x` always prints the four 64-bit data and packet counters; unicast and multicast packet counts need `IB_PM_EXT_WIDTH_SUPPORTED`; the 64-bit error counters (including `PortXmitWait`) need the second capability mask bit. The header line prints both masks (`CapMask`, `CapMask2`). | confirmed | [perfquery.c:308-329](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L308-L329), [fields.c:984-1001](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibmad/fields.c#L984-L1001) |
| `-r` resets after read, `-R` resets only; `ibqueryerrors -k`/`-K` clear regardless of what is printed | Holds. | confirmed | [perfquery.c:1100-1130](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L1100-L1130), [ibqueryerrors.8.in.rst:60-64](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/man/ibqueryerrors.8.in.rst#L60-L64) |
| `-a` aggregates, `-l` loops per port, port 255 selects all; lists and ranges allowed; plugin must use the per-port form | Holds with three corrections. (1) A comma list or `a-b` range without `-a` prints each port separately, so `perfquery <lid> 1-36` is a per-port form. (2) If the port has no AllPortSelect capability and `-a` is used, the tool emulates it with a loop and warns. (3) Every `pma_query_via` failure calls `IBEXIT`, so one unresponsive port ends the process and discards the remaining ports of that invocation. | confirmed | [perfquery.c:896-922, 1086-1100, 411, 438](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L896-L922) |
| Each invocation costs two class queries before any counter | Not in the plan. Every run first queries PerfMgt `ClassPortInfo` ("classportinfo query" aborts on failure), and `-a`/`-l` over all ports adds NodeInfo and SwitchInfo SMPs. | confirmed | [perfquery.c:940-1068](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L940-L1068) |
| `iblinkinfo` one-line mode, partial scans, `--switches-only`, `--cas-only` | Holds. | confirmed | [iblinkinfo.8.in.rst:32-39](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/man/iblinkinfo.8.in.rst#L32-L39) |
| `iblinkinfo` prints `Could be <width>` when active width is not within the widest width supported at both ends | Holds. It compares against *supported* at both ends, not *enabled*. Speed side checked in [link-downgrade.md](link-downgrade.md). | confirmed | [ibdiag_common.c:819-835](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/ibdiag_common.c#L819-L835) |
| Width values decode 1 to 1X, 2 to 4X, 4 to 8X, 8 to 12X, 16 to 2X | Holds. | confirmed | [dump.c:165-190](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibmad/dump.c#L165) |
| `ibnetdiscover -p` prints node type, LID, port number, port GUID, active width, speed, then the remote side and the two descriptions | Holds, but the row also implies identity data it does not give: it has no node GUID for the local or remote node, and for a switch the LID is the switch's LID. | confirmed; consequence in [identity.md](identity.md) | [ibnetdiscover.c:680-745](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/ibnetdiscover.c#L680-L745) |
| `--cache` saves a discovery for reuse | Holds as an option. Reuse behavior and cost are *live* ([mad-budget.md](mad-budget.md)). | confirmed option | `ibnetdiscover` option table |
| Node information record carries SystemGuid, node Guid, PortGuid | Holds. | confirmed | [fields.c NodeInfo group](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibmad/fields.c) |

## Output fixtures (#14 exit criterion)

The exit criterion asks for output captured from real tools for every matrix version. That needs the tools running on an InfiniBand-attached host. None exists here, and hand-written samples are what the issue forbids. Status: **not done**; listed as *live* below. The parsers in milestone 2 should be written against captured fixtures only.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #13 | Run `perfquery -h`, `ibnetdiscover -h`, `iblinkinfo -h` and `-V` on each matrix distribution and on the vendor stack; save the output | Replaces the option-table extraction with real output; gives the vendor stack's tool version |
| #14 | Capture `perfquery`, `perfquery -x`, `iblinkinfo --line`, `ibnetdiscover`, `ibnetdiscover -p`, `ibnetdiscover -l` for a healthy fabric, a downgraded link, and a down port on each matrix version | Fixtures for the parsers; the exact header and spacing text |
| #14 | Run `perfquery -x` against a port whose capability mask lacks extended support | What the warning and the output look like on real hardware |
| #13 | On a `v39.0` to `v48.0` host attached to an XDR link, run `iblinkinfo --line` | What those builds print for XDR |
| #13 | Look up the `infiniband-diags` version inside the current DOCA-Host package | Whether the vendor stack matches the upstream version in this matrix |

## Limits

- Distribution versions are Repology's, not a read of each distribution's archive, and RHEL and SLES are proxied or missing.
- The tools were not executed; every statement about output is from source.
- Support end dates are endoflife.date's. Vendor support terms (for example, a vendor's own policy for an older rdma-core) were not checked.
