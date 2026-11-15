# Estimates for phases 1 to 3

Research for [#26](https://github.com/somethingwithproof/plugin_fabric/issues/26), made 2026-11-15 after the milestone 1 documentation work. Question: how large is each later milestone, and what gates it?

**Result.** Size is estimable from measured code; elapsed time is not. Production PHP for phase 1 (milestones 2 to 4) is about 8,300 to 10,800 lines, phase 2 (milestone 5) adds about 1,000 to 2,000, and phase 3 (milestone 6) adds at least 1,200 with no upper bound that the measured code supports. Tests are expected at 0.8 to 1.9 times production lines, the range the two comparison plugins show. No elapsed-time estimate is published: the only throughput evidence is commit spans of two single-author, AI-assisted repositories (24 days and 2 days), which include no review latency and no hardware, and the fabric plugin's gates are mostly outside the code (a fabric to measure against, security reviews, maintainer decisions). The gates are listed per milestone so the owner can attach dates once they are known. The estimates need publishing in `docs/PLAN.md` (done in this change) and on the epics (#2 to #6), which this research did not edit because the task forbids changing GitHub.

## Basis

Two plugins by the same author with the same layout and tooling, measured on 2026-11-15 from each `main`:

| Plugin | Commit | Production lines | Test lines | Commit span | Scope |
|---|---|---|---|---|---|
| [plugin_cloudflare](https://github.com/somethingwithproof/plugin_cloudflare) | `c08c99bbdfd0` | 10,479 | 20,347 | 2026-10-21 to 2026-11-14 | One provider: accounts, secret vault, curl transport, discovery, metrics, script server, templates |
| [plugin_smokeping](https://github.com/Cacti/plugin_smokeping) | `481547c54050` | 14,932 | 11,328 | 2026-11-13 to 2026-11-14 | Observe mode, devices, data sources, smoke graphs, release qualification |

Method: `git ls-files` on `main`, files ending in `.php`, `.sh`, `.pl`, or `.py`, excluding `vendor/` and `node_modules/`; production excludes `tests/` and `features/`; lines counted with `wc -l`. The commit span is when commits landed, not effort, and both repositories were written with AI assistance. These totals are higher than plugin_cloud's estimates document recorded a day earlier (10,324 and 13,249 production lines) because both repositories gained commits since.

Measured areas in plugin_cloudflare, production lines: `includes/` 3,409 (pages, lifecycle, settings, Cacti objects, templates, script server, polling); `src/Account` 1,052; `src/Api` 993; `src/Discovery` 979; `src/Metrics` 842; `src/Storage` 788; `src/Http` 604; repository root 595; `src/Secret` 438; `src/Jobs` 377; `src/Support` 184. Individual files used below: `includes/cacti_objects.php` 507, `includes/templates.php` 306, `includes/script_server.php` 136, `includes/polling.php` 171. In plugin_smokeping: `lib/` 12,601 in a flat directory (no area breakdown), `tools/` 437, `cli/` 383, `scripts/` 55.

## Per milestone

| Milestone | Production lines | Grounding (sums of measured areas) | Gates outside the code |
|---|---|---|---|
| 2. Foundation | 5,800 to 7,000 | Lower bound: cloudflare `includes/` + `Account` + `Storage` + `Jobs` + `Support` = 5,810 (pages, lifecycle, records, migrations, job runner). Upper bound: add `Http` (604) as the nearest measured analog for the command boundary and three parsers, plus the root files (595) = 7,009. The Account area stands in for fabric records and backend selection. | Test harness choice and repository home with Cacti maintainers (#10, #11) |
| 3. Cacti objects and alerting | 2,000 to 2,900, plus template XML | Lower bound: cloudflare `includes/cacti_objects.php` + `templates.php` + `script_server.php` + `polling.php` + `Metrics` = 1,962. Upper bound: add `Discovery` (979) for the GUID-based device mapping and re-link proposals = 2,941. Counter discontinuity handling and the degraded-link evaluation have no measured analog. | The sweep timing decision #7 and the saturated-counter decision #8, both waiting on live evidence; device granularity decision #9 (Cacti maintainers); the 10,000-port performance run needs a Cacti host and a fixture fabric |
| 4. Phase 1 release | 500 to 900, mostly tests and documentation | smokeping `tools/` + `scripts/` = 492; plus `cli/` = 875 | Security review of the command runner and argument builder; a pilot on a diagnostics-tools deployment and on a larger fabric (#54) |
| 5. UFM backend | 1,000 to 2,000 | Lower: cloudflare `Http` + `Secret` = 1,042 (client and credentials, with no wrapper layer). Upper: add `Api` = 2,035. UFM's read-only resources are a smaller surface than a provider API, so the upper bound is the more cautious figure. | A UFM instance with a read-only account for the live checks in [ufm-rest.md](ufm-rest.md); the credential storage decision #12; security review |
| 6. Remote and scale | at least 1,200, no upper bound | Neither plugin has production code for remote collectors: a search of both repositories' production PHP for `remote_agent`, `replicate_out`, `call_remote_data_collector`, `remote_poller`, and the recovery mode found one `function_exists('replicate_out')` guard in smokeping's `setup.php` and a protocol document, not code, in cloudflare (`docs/collector-protocol.md`). The only lower bound is the size of the nearest analogs: `Jobs` (377) for sweep partitioning plus `Storage` (788) for replicated tables = 1,165. | A Cacti remote collector test bed with offline and recovery scenarios; security review of `remote_agent` actions |

Tests: plugin_cloudflare has 1.94 test lines per production line and plugin_smokeping 0.76. Applying that range, phase 1 would add roughly 6,300 to 21,000 test lines; the spread is the evidence's, not a forecast.

## Totals

| Scope | Production lines |
|---|---|
| Phase 1 (milestones 2 to 4) | about 8,300 to 10,800 |
| Through phase 2 (add milestone 5) | about 9,300 to 12,800 |
| Through phase 3 (add milestone 6) | about 10,500 to 14,000 or more |

For comparison, plugin_cloudflare is 10,479 lines and plugin_smokeping is 14,932.

## Capacity assumptions (issue #26)

The issue asks for stated volunteer time and review latency. No source for either was found in this research: no data on Cacti maintainers' review turnaround was collected, and the owner's available time is not in the repository. The estimate therefore states sizes and gates, and leaves elapsed time for the owner to assign once these three are known:

1. How many hours per week the owner can commit to each milestone.
2. The maintainers' response time on the repository home, name, harness, and device granularity decisions (#9, #10, #11), which block milestones 2 and 3.
3. When a fabric (switches, an HCA, and ideally a UFM instance) is available for the live checks in these research documents.

## Uncertainty

- Milestone 2 carries the parser work: three tools with output that changes by version, and recorded fixtures that do not exist yet ([infiniband-diags-versions.md](infiniband-diags-versions.md)). Fixture capture needs hardware.
- Milestone 3's upper bound depends on whether `smpquery` joins the allowlist for enabled link values ([link-downgrade.md](link-downgrade.md)); that adds a tool, a parser, and per-port queries.
- Milestone 3 also depends on the sweep timing decision: a lazy per-device sweep and a prefetch differ in the script server and polling code ([mad-budget.md](mad-budget.md)).
- Milestone 6 has no direct precedent in either plugin.
- Any live check that contradicts a source reading in these documents changes the milestone that depends on it. The most exposed are the counter behavior (milestone 3), the switch GUID behavior (milestone 2), and the MAD budget (milestones 2 and 3).
