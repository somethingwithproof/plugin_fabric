# What ibdiagnet adds

Research for [#22](https://github.com/somethingwithproof/plugin_fabric/issues/22), run 2026-11-15. Question: does ibdiagnet provide anything the plugin needs that `perfquery`, `ibnetdiscover`, and `iblinkinfo` do not (link error summaries, cable data, a reusable topology file), and should the plugin use it?

**Result.** Recommendation: exclude ibdiagnet from phases 1 to 3, and record cable and PHY data as the one case where it might return as an optional input (research issue #66). The reasons are all from NVIDIA's own manual. It is not in rdma-core; it ships in the `ibutils2` package with DOCA-Host and UFM, so the plan's "tools installed" prerequisite would change. Its default run is a full diagnostic (discovery, duplicate-GUID and LID checks, two counter snapshots one second apart, speed and width checks, a network dump) and the manual never states that a run is read-only. It has several options that reset or clear counters, including one the manual recommends in its bit-error-rate procedure. It writes a directory of files under `/var/tmp/ibdiagnet2/` by default. And the manual does not document the column names of the `ibdiagnet2.db_csv` tables that carry link width, speed, state, or counters, so no parser requirement can be written without a captured file. The `--skip` option lets a run omit stages, and `--load_from_file` and `--discovery_only` can reuse a discovery, which would reduce fabric load, but the plugin gets its topology and width and speed from the three simpler tools already. This is a decision for the owner, with the evidence below.

## Method

NVIDIA's ibdiagnet manual, version 2.27.0 web pages (`networking-docs.nvidia.com/ibdiagnetutilityum/2.27.0/`), plus the 2.19 to 2.26 pages that exist, the v2.13 and v2.24 PDFs, fetched 2026-11-14. The requested URL for the 2.19 dump-files page redirects to a page-not-found; the same content exists under the 2.19 and later paths. The rdma-core tree at `v66.0` was searched for an ibdiagnet path. ibdiagnet was not run.

## Findings

| Fact | Result | Status | Source |
|---|---|---|---|
| What it does | "ibdiagnet scans the fabric using directed route packets and extracts all the available information regarding its connectivity and devices." | confirmed | ibdiagnet 2.27.0 release notes |
| Default stages | Fabric discovery; duplicated GUIDs check; duplicated node description check; LID check; links check; subnet managers check; port counters snapshot and checks in a one second period; nodes information check; speed and width check; virtualization info; partition keys check; temperature sensing; a network dump "similar to the ibnetdiscover format" | confirmed | 2.27.0, running ibdiagnet without parameters |
| Stages can be skipped | `--skip dup_guids, dup_node_desc, lids, sm, nodes_info, pkey, aguid, links, pm, speed_width_check, temp_sensing, virt, hca_cfg_check, all` | confirmed | 2.27.0, basic commands |
| Width and speed check | "Checks that fabric links are operating at maximum supported speed and width"; `--ls` and `--lw` set expected values; degraded ports "are reported in ibdiagnet.log" | confirmed | 2.27.0, functionality and fabric links validation |
| Duplicate GUID check exists | The default stage list includes it. This is the detection the plain tools lack ([identity.md](identity.md)). | confirmed | same |
| MAD windows | `--smp_window` 8, `--gmp_window` 128 (outstanding MADs), `--mads_timeout` 500 ms, `--mads_retries` 2, `--max_hops` 64 | confirmed | 2.27.0, MAD parameters |
| Read-only | The manual does not say a run is read-only. Collection options read; the options below clear. | NOT STATED; inference only | |
| Counter reset and clear options | `--pc` ("Resets all fabric IB spec compliant port counters (PortCounters and PortCountersExtended), RN, AR and HBF counters"), `--pm_clear_all` ("activates --scr --pc"), `--scr` (diagnostic counters), `--reset_phy_info`, `--clear_congestion_counters`, `--sharp_opt csc`. The documented bit-error-rate procedure runs `ibdiagnet --pc --reset_phy_info`. No `--clear_counters` option exists in any manual version read. | confirmed | 2.27.0, port counters, basic commands, BER |
| Default counter behavior | Two samples `--pm_pause_time` apart (default 1 second; 0 means one sample); the difference goes to the `PM_DELTA` section. Nothing in the default run is documented as clearing. | confirmed; "does not clear by default" is inference | 2.27.0, basic commands |
| Counters it reads | PortCounters, PortCountersExtended, PortRcvErrorDetails, PortXmitDiscardDetails by default; optional per-SL/VL, diagnostic, extended-speed, per-lane, and a combined `--pm_get_all` | confirmed | 2.27.0, port counters |
| Output | Default directory `/var/tmp/ibdiagnet2/`: `.log`, `.lst`, `.net_dump`, `.net_dump_ext`, `.pm`, `.db_csv`, `.nodes_info`, `.cables`, `.ibnetdiscover`, `.iblinkinfo`, and others | confirmed | 2.27.0, dump files |
| `db_csv` sections documented | `NODES`, `PM_INFO`, `PM_DELTA`, `PORTS` (columns `CapMsk2`, `FECActv`, `RetransActv` named), PHY, BER, congestion sections. The format is `START_<NAME>`, a header row, rows, `END_<NAME>`. | confirmed | 2.27.0 pages |
| Tables named in the issue and plan (`CABLE_INFO`, `LINKS`, `PORT_INFO`) | Not documented in any version read. Column names for width, speed, state, and counters are not documented either. | NOT FOUND | |
| Cable data | The cable plugin is deprecated ("Cable plugin and its parameters are now obsolete and not supported in the CMIS cables, please use --get_phy_info instead"). `--get_phy_info` "Queries all ports for PHY information including BER counters" into `db_csv` and `net_dump_ext`. | confirmed | 2.27.0, cable and PHY pages |
| Where it ships | Not in rdma-core (no ibdiagnet or ibutils path in the `v66.0` tree); "distributed as part of ibutils2 package which is part of DOCA-Host and UFM software packages" (v2.24 PDF); earlier "part of MLNX_OFED and UFM" (v2.13). It is also offered from NVIDIA's InfiniBand management tools download. | confirmed | v2.24 and v2.13 PDFs; rdma-core tree |
| License and open-source status | License link on the legal page only; no public repository confirmed | NOT FOUND | |
| Privileges | The manual does not state them. It selects the local port with `-i`, `-p`, `-g`; it must open the same umad devices; fabric keys have options (`--m_key`, `--pm_key`, `--vs_key`, `--cc_key`, `--security_keys`). | NOT STATED; inference | |
| Run time and fabric load | No figures in the manual | NOT FOUND | |
| Manual versions | 2.19, 2.22 to 2.27 web pages; v2.13 and v2.24 PDFs; the tool prints its version with `-V` | confirmed | 2.27.0 revision history |

## Role

| Possible role | Verdict |
|---|---|
| Phase 1 collector for width, speed, counters | No. The three simpler tools already provide them from rdma-core packages. ibdiagnet adds a package source, resetting options, and a file-based output the plugin would have to manage. |
| Topology cross-check | No for the first release. Its duplicate GUID and LID checks are useful, but the plugin can detect duplicates from its own inventory rules ([identity.md](identity.md)). It could be an operator-run diagnostic that the plugin does not call. |
| Optional input for cable and PHY data | Possible later, under research issue #66. `--get_phy_info` is the documented route, and its tables need captured files before a parser is specified. |
| Reusable topology file | `ibnetdiscover --cache` and `--load-cache` already exist in the tools the plugin uses; ibdiagnet also writes `ibdiagnet2.ibnetdiscover` and `ibdiagnet2.iblinkinfo`. |

If ibdiagnet is ever called, its argument builder must keep every reset option (`--pc`, `--pm_clear_all`, `--scr`, `--reset_phy_info`, `--clear_congestion_counters`, `--sharp_opt csc`) unreachable, as ADR 0004 requires for the other tools.

## Requirements if it returns

- A parser for the `START_<NAME>` sections of `ibdiagnet2.db_csv`, built from captured files per manual version, never from the manual.
- A temporary output directory owned by the plugin and removed after parsing (`-o`).
- An allowlist test that no reset option can be composed.
- A statement of the package source and version in the install documentation.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #22 | Run `ibdiagnet --skip all --get_phy_info` or the default run on a lab fabric; keep `ibdiagnet2.db_csv` | The real table and column names; the read-only behavior (compare counters before and after); the run time and load |
| #22 | Compare a default run's `PM_DELTA` with `perfquery` readings taken around it | Whether the default run alters counters |
| #22 | Check the privileges needed to run it as a non-root user with umad access | Privilege model |

## Limits

Documentation only, and the 2.19 page named in the issue is unreachable at its old URL. The IBTA behavior behind its checks was not examined. No part of the claim that the default run is non-destructive is documented.
