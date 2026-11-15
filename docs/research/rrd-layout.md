# Data template layout and RRD cost per port

Research for [#25](https://github.com/somethingwithproof/plugin_fabric/issues/25), run 2026-11-15. Question: how should counters be grouped into data templates so that RRD count, update cost, and graph usefulness stay reasonable?

**Result.** Grouping A (four files per port: traffic, errors, congestion, state) is the better layout on every figure that differs. Both layouts store the same 25 data sources per port and use almost the same disk (2.34 MB per port for A, 2.39 MB for B, with Cacti 1.2.31's default "5 Minute Collection" profile). They differ in file count: 4,000 files for A and 25,000 for B at 1,000 ports. Applying one polling cycle of updates through one rrdtool pipe took 0.29 seconds for A and 1.67 seconds for B at 1,000 ports. Linear extrapolation to 10,000 ports gives about 2.9 and 17 seconds, which is arithmetic, not a measurement. These are rrdtool and file system costs on one development machine with warm caches; they are not Cacti poll times. Cacti's own row counts (data source, graph, and poller tables) were not measured because Cacti was not installed, and the "documented fabric size" claims in the plan stay unmeasured until M3. The plan's updates-per-second figures are file updates per cycle and hold as arithmetic.

## Method

[tests/rrd-layout/run.php](../../tests/rrd-layout/run.php) creates the files in a temporary directory, measures create time and disk size, and times two update cycles through one persistent `rrdtool -` process, which is how Cacti sends updates. It was run as `php tests/rrd-layout/run.php 100 1000`.

- Profile: Cacti 1.2.31 `5 Minute Collection`: step 300, heartbeat 600, xff 0.5, consolidation functions AVERAGE, MIN, MAX, LAST over 600, 700, 775, and 797 rows at 1, 6, 24, and 288 steps ([cacti.sql:1321, 1339-1342, 1371-1374](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/cacti.sql#L1321); function ids 1 to 4 are AVERAGE, MIN, MAX, LAST ([global_arrays.php:555-560](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/include/global_arrays.php#L555-L560))).
- Data sources per port, 25: traffic 4 (COUNTER), errors 12 (COUNTER), congestion 4 (COUNTER), state 5 (GAUGE). Names are at most 19 characters because Cacti limits data source names to that length ([cacti-core.md](cacti-core.md)).
- Host: an Apple `Mac15,10` with 14 cores, APFS solid state storage, rrdtool 1.11.0, PHP 8.5.12, caches warm, nothing else heavy running. Cacti uses rrdtool 1.7 or 1.8 on Linux with other file systems, so the absolute numbers will differ.

## Measurements

| Layout | Ports | Files | Data sources | Create (s) | Bytes per port | Update cycle (s) | File updates per second |
|---|---|---|---|---|---|---|---|
| A, four files per port | 100 | 400 | 2,500 | 0.42 | 2,344,168 | 0.034 | 11,894 |
| B, one file per data source | 100 | 2,500 | 2,500 | 1.44 | 2,390,200 | 0.154 | 16,184 |
| A | 1,000 | 4,000 | 25,000 | 3.64 | 2,344,168 | 0.294 | 13,599 |
| B | 1,000 | 25,000 | 25,000 | 15.23 | 2,390,200 | 1.674 | 14,934 |

Observed, from those rows:

- Disk per port is dominated by the data sources and consolidation functions: 25 data sources times 11,488 rows times 8 bytes is 2.30 MB, and A adds about 2.0 percent and B about 4.0 percent for file headers.
- The update cost tracks the file count: at 1,000 ports B took about six times as long per cycle as A for the same data.
- Disk at 10,000 ports, from bytes per port: about 23.4 GB for A and 23.9 GB for B with this profile. Four consolidation functions is Cacti's default profile; a profile with only AVERAGE and MAX would shrink both.

Extrapolation, not measured: 10,000 ports would be 40,000 files and about 2.9 seconds per cycle for A, and 250,000 files and about 17 seconds for B. B's per-file cost rose between 100 and 1,000 ports (0.062 to 0.067 ms), so the linear estimate may understate it. A cold file cache would cost more.

## Poll-path and database counts (arithmetic from table structure)

| Quantity per port | A | B | Basis |
|---|---|---|---|
| Local data sources | 4 | 25 | one per file |
| Data source items (`data_template_rrd` rows) | 25 | 25 | one per data source |
| Polled items (`poller_item` rows) | 4 if each group is one multi-output item | 25 | `poller_item.rrd_num` is the number of data sources in the item ([cacti.sql:2205](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/cacti.sql#L2205)); the output format of a multi-output script item was not read |
| Script server calls per cycle | 4 | 25 | one per polled item |

At 10,000 ports that is 40,000 versus 250,000 polled items, which also means 250,000 script server round trips. The plan's "about 250,000 `data_template_rrd` rows" holds for both layouts and the "about 30,000 graphs" depends on graph templates, not layout. The `data_template_data` and `data_local` counts follow the file count: 40,000 versus 250,000. These counts were not measured in a Cacti database.

## Decision supported

Use grouping A. Group the counters by the reasons they are read: traffic, errors, congestion, and state. Keep the state group separate because it is GAUGE data with a different meaning, and keep GAUGE and COUNTER in different files so a data source type is never changed for an existing file.

Open points the research did not settle:

- Adding a data source to an existing group after release (a template change) must be handled by tuning files, and that was not tested. A new counter in a later release may need a new group instead of a change to an existing one.
- One missing or unsupported counter in a group is unknown within the file. That works with the plan's rule (unknown, never zero).
- The `max` setting differs per counter within a file (see [counters.md](counters.md)); Cacti stores `max` per data source item, so a group can hold counters with different maxima.

## Plan consequences

- "Cacti objects" Data sources row: replace "Layout chosen by milestone 1 measurements" with grouping A and the figures above.
- "Performance" section: keep the arithmetic, label the update-rate lines as file updates per cycle, and add the measured rrdtool figure for 1,000 ports and the extrapolation caveat.
- "Cacti database" rows at 10,000 ports remain engineering targets until M3 measures them in Cacti.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #25 | Install Cacti 1.2.31 on a Linux host, import the templates, and poll 1,000 and 10,000 fixture ports with layouts A and B | Real poll time, `poller_item` and data source row counts, database size, and page response |
| #25 | Repeat the update timing on the target file system with a cold cache and with boost or other deferred update settings | Whether the extrapolation holds |
| #25 | Add a data source to a deployed group through a template resync | Whether tuning existing files works |

## Limits

One host, rrdtool only, warm cache, no Cacti. The extrapolations are straight lines through two points.
