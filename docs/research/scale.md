# Scale at the documented fabric sizes

Research for [#49](https://github.com/somethingwithproof/plugin_fabric/issues/49), run 2026-11-15. Question: how do the plugin's own steps (topology import, counter sweeps, data query answers, the overview page) and Cacti's objects for its ports grow from about 100 to about 10,000 ports?

**Result.** The plugin's own work stays small at all three sizes. Importing the topology of a 9,992 port fabric took 2.7 seconds, a counter sweep of one adapter node about 0.09 seconds, and the data query answered in about 1 millisecond. The overview page rendered in 0.04 seconds but was 1.5 MB of HTML at 9,992 ports, which is the figure to watch. The cost that grows fastest is Cacti's, not the plugin's: every data source of a port is one poller item, 27 per port, so all graphs of 10,000 ports would be 270,000 poller items. One cmd.php run over 919 items took 1.7 seconds. Linear extrapolation to 270,000 items gives about 500 seconds for one cmd.php process, more than a 300 second interval; that is arithmetic, not a measurement. These numbers come from fake tools on one development machine, not from a fabric.

## Machine caveat

- One development machine: Apple M3 Max, 14 cores, 36 GB, running Docker 29.8.2 with a 14 CPU, 16 GB Linux VM.
- PHP 8.2 CLI in the `cloud-ci:8.2` replay image, Cacti `1.2.x`, and MariaDB in a separate container on the same VM, with warm caches.
- The diagnostic tools are the plugin's fake tools: PHP scripts that print generated output. Tool time is PHP start-up, never a fabric round trip, so the sweep figures measure the plugin's parsing and database work only. Sweep time on a real fabric and the management datagram budget remain live checks ([mad-budget](mad-budget.md)).

## Method

[tests/bin/scale.php](../../tests/bin/scale.php) runs inside the replay (`tests/bin/e2e.sh` installs the fake tools in a root-owned directory first). For each size it:

1. builds a fabric with the [fabric generator](../../tests/Support/FabricGenerator.php) (`forCas(n)`: n adapters behind leaf and spine switches),
2. runs the production sweep for one switch with the topology forced, which runs `ibnetdiscover` and `iblinkinfo`, imports the topology, and sweeps that switch's counters,
3. sweeps the counters of 25 adapters as the poller does, without forcing the topology again,
4. maps the switch to a device and times the data query's `index` answer and every `get` answer for its ports,
5. renders the inventory overview as for an administrator.

At the first size only it also creates all five graphs for each of the switch's 33 ports through `create_complete_graph_from_template()`, pushes them to the poller cache as `cli/add_graphs.php` does, and runs `poller.php --force` once. The device has availability checks off, as a created device has.

## Measurements

| Adapters | Nodes | Ports | Topology and one node (s) | Counter sweep per node (s) | Index answer (ms) | Get answer (ms) | Overview render (s) | Overview size (bytes) | Peak memory (MB) |
|---|---|---|---|---|---|---|---|---|---|
| 40 | 42 | 112 | 0.18 | 0.051 | 1.5 | 0.002 | 0.001 | 13,565 | 14 |
| 470 | 485 | 1,010 | 0.53 | 0.060 | 1.0 | 0.001 | 0.004 | 151,202 | 18 |
| 4,700 | 4,847 | 9,992 | 2.71 | 0.090 | 1.1 | 0.001 | 0.036 | 1,515,555 | 60 |

Each size ran `ibnetdiscover` and `iblinkinfo` once and `perfquery` two times per swept node (basic and extended); at the first size the poller run swept the switch once more. The index answer covers the switch's 33 ports at every size.

Cacti objects for one switch's 33 ports, all five graphs each:

| Graphs | Data sources | Data source items | Poller items | Poller run |
|---|---|---|---|---|
| 165 | 165 | 891 | 891 | 1.72 s, cmd.php, 1 process, 919 items on 5 hosts, 174 RRD files updated |

That is 5 graphs, 5 data sources, 27 data source items, and 27 poller items per port. The [layout research](rrd-layout.md) left open whether a script data query item returns several outputs in one item; as built, each output is its own poller item, the same as layout B's item count though with layout A's file count.

## What follows

- **Per port, Cacti grows faster than the plugin.** All graphs on 10,000 ports would mean 50,000 graphs and 270,000 poller items. Sites at that size should graph selected ports, or poll with Spine and several processes. The data query answers from the cache, so each item costs one script server call of about a millisecond of plugin work plus Cacti's per-item overhead.
- **The overview page needs paging before 10,000 ports.** 1.5 MB of HTML renders quickly on the server but is heavy in a browser.
- **The topology import is linear enough for a 300 second interval** at about 10,000 ports on this machine. A real `ibnetdiscover` on such a fabric takes far longer than the import itself; that is a live check.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #49 | Repeat the run on a Linux host with Spine, the real tools, and a fabric or the ibsim simulator at about 1,000 ports | Sweep time with real tools and a realistic poller |
| #49 | Poll all graphs of 1,000 ports through a full cycle | Measured poll time instead of the extrapolation above |
| #49 | Time the overview page in a browser at 10,000 ports | Whether paging is needed before release |
