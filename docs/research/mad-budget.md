# MAD budget, sweep timing, and poll alignment

Research for [#20](https://github.com/somethingwithproof/plugin_fabric/issues/20), with input to the decision [#7](https://github.com/somethingwithproof/plugin_fabric/issues/7), run 2026-11-15. Questions: how many management datagrams per second can the fabric accept from a monitoring node, how long does a sweep take, and when should the sweep run relative to the Cacti poll cycle?

**Result.** The measurements the issue asks for (sweep time at 100, 1,000, and 10,000 ports; load on the subnet manager and switch management CPUs) need a fabric and were not made. Nothing here is a measured rate, and the plan's performance arithmetic stays labelled as arithmetic. What the sources settle is the structure of the cost: `perfquery` is strictly serial (one datagram outstanding per process), a failed query ends the invocation, a dead port costs three seconds at the defaults, and every invocation spends at least one extra query on `ClassPortInfo`. Discovery is parallel but defaults to two SMPs in flight. For comparison, an existing consumer of the same counters, OpenSM's optional performance manager, sweeps every 180 seconds by default and, in its documentation, resets counters when they reach about three quarters of their range, which the plugin will see as discontinuities. UFM collects all port statistics every 30 seconds by default and serves them from a default session, which is a cheaper source than direct queries for any site that has UFM. For the timing decision, Cacti stamps every polled value with the time of the database insert, not the measurement time, so only a lazy per-device sweep keeps sample time close to stored time, and Spine can split one device across threads, which makes a lazy sweep need a claim. The recommended minimum interval is *live*.

## Method

rdma-core `v66.0` (`0fa90c60fbc274edb3495f30c1afd02b3f5fd38e`) for the tools; OpenSM `master` at `8fe74e778470b9c9357506c6c153cada7dfa934d` for the performance manager documentation; the UFM REST API guide 6.26.1 pages fetched 2026-11-14; ibdiagnet manual 2.27.0 for NVIDIA's own tool defaults; Cacti `release/1.2.31` (`1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1`) and Spine `origin/develop` (`c81b1c07aff3d16e4b9fe5371a194b5d88ebe245`) for polling. Nothing was run against a fabric.

## What the sources say about cost

| Fact | Result | Status | Source |
|---|---|---|---|
| `perfquery` sends one query at a time | `pma_query_via` sends, waits for the reply, and retries inside one call; there is no pipelining | confirmed | [rpc.c:120-190](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibmad/rpc.c#L120-L190) |
| Default timeout and retries | 1000 ms and 3 retries (`MAD_DEF_TIMEOUT_MS`, `MAD_DEF_RETRIES`); a silent port costs "timeout after 3 retries" or about 3 seconds; `-t` overrides the timeout | confirmed | [mad.h:1429-1430](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibmad/mad.h#L1429-L1430) |
| A failed query ends the process | `IBEXIT("perfquery")` on the first failure, so later ports in a list are not read | confirmed | [perfquery.c:409-412](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L409-L412) |
| Each invocation first sends `ClassPortInfo`, and resolves the target | One extra datagram per invocation, plus an SA path query if the target is given as a GUID | confirmed | [perfquery.c:940-945](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L940-L945) |
| One invocation may cover a list or range of ports of one node | `perfquery <lid> 1-36` reads 36 ports with one `ClassPortInfo`; `-x` is a separate invocation (basic and extended are never one call) | confirmed | [perfquery.c:896-922, 1086-1100](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L896-L922) |
| Discovery concurrency | 2 SMPs on the wire, 1000 ms, 3 retries by default; `-o` raises the SMP count in `ibnetdiscover` and `iblinkinfo` | confirmed | [internal.h:56-58](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibnetdisc/internal.h#L56-L58) |
| `iblinkinfo` and `ibnetdiscover` each run their own full discovery | Two separate discoveries per sweep unless `--load-cache` or one tool's output is reused | confirmed by reading; cost *live* | [iblinkinfo.c:640-700](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/iblinkinfo.c#L640-L700) |
| NVIDIA's own tool uses larger windows | `ibdiagnet`: 8 SMPs and 128 GMPs on the wire, 500 ms timeout, 2 retries by default. These are the vendor tool's defaults, not safe-polling guidance. | confirmed (manual) | [ibdiagnet 2.27.0, infiniband-mad-specific-parameters](https://networking-docs.nvidia.com/ibdiagnetutilityum/2.27.0/) |
| ibdiagnet's default takes two counter samples one second apart | `--pm_pause_time` default 1; no second sample if 0 | confirmed (manual) | ibdiagnet 2.27.0, basic commands |
| Another consumer of the same counters | OpenSM's performance manager: `perfmgr_sweep_time_s` default 180 seconds; the HOWTO example uses `perfmgr_max_outstanding_queries 500`. It is optional and compiled in only with `--enable-perfmgr`. | confirmed (documentation) | [opensm.8.in:449-458](https://github.com/linux-rdma/opensm/blob/8fe74e778470b9c9357506c6c153cada7dfa934d/man/opensm.8.in#L449-L458), [performance-manager-HOWTO.txt:66-80](https://github.com/linux-rdma/opensm/blob/8fe74e778470b9c9357506c6c153cada7dfa934d/doc/performance-manager-HOWTO.txt#L66-L80) |
| The performance manager resets counters | "Automatic reset of counters when they reach approximately 3/4 full" and "Automatically detects 'outside' resets of counters"; its known issues include that data counters will be lost on high data rate links | confirmed (documentation) | [performance-manager-HOWTO.txt:14-24](https://github.com/linux-rdma/opensm/blob/8fe74e778470b9c9357506c6c153cada7dfa934d/doc/performance-manager-HOWTO.txt#L14-L24) |
| UFM collects port statistics itself | "UFM default session which collects all port statistics every 30 seconds (by default) can be queried by using session ID 0": `GET /ufmRest/monitoring/session/0/data`; data carries per-object `last_updated` | confirmed (documentation); whether UFM reads the fabric live for other sessions is NOT FOUND | [UFM REST API guide 6.26.1, Monitoring REST API](https://networking-docs.nvidia.com/ufmenterpriserestapi/6.26.1/monitoring-rest-api) |
| Vendor or SM guidance on safe polling intervals for a monitoring node | NOT FOUND | unverified | |

## Arithmetic from the defaults (not measurements)

A sweep that runs one `perfquery -l <lid> <ports>` per node, then one `perfquery -x` per node, costs per node 2 `ClassPortInfo` queries plus 2 counter queries per port: for a fabric of `N` ports in `M` nodes, `2M + 2N` datagrams, serialized per process. The wall time is the sum of round trips, so it depends on a round-trip time that was not measured, and on how many processes the runner starts. The plan's "two queries per port" figure is conservative for switches (one invocation covers many ports) and correct for the datagram count per port, but its 67 and 333 queries-per-second figures describe only what the fabric sees; they say nothing about whether the fabric accepts it.

Dead or isolated ports dominate the worst case: at the defaults each costs about 3 seconds before its process exits, and the ports after it in the same invocation are skipped. If a fraction `f` of a sweep's single-port invocations reach a dead port, they add about `3 s x f x (number of invocations)` of serialized time. That shows why the plan needs a per-node time budget and a per-sweep failure budget; it is not a measurement.

## Source options for the sweep

| Source | Cost to the fabric | Needs | Freshness |
|---|---|---|---|
| Direct `perfquery` | Datagrams as above, serial per process | HCA on the fabric and umad access | At query time |
| UFM default session | None beyond what UFM already does | UFM and an account | At most about one UFM collection interval old (30 seconds by default); each object has `last_updated` |
| Host sysfs | A local PerfMgt MAD per file read, none on the wire | Code on the host | At read time ([sysfs-hca.md](sysfs-hca.md)) |

## Input to decision #7 (when a sweep runs)

| Fact | Result | Status | Source |
|---|---|---|---|
| Cacti stamps polled values with the time they are stored, not measured | The PHP poller builds each `poller_output` row with `CURRENT_TIMESTAMP()` in the SQL, so the time is the moment of the `INSERT`, which runs after every 2,000 items and at the end of the process's host range. Spine was not read for this. | confirmed for `cmd.php` | [cmd.php:399-410](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/cmd.php#L399-L410) |
| So a prefetched sample with age `a` yields a rate computed against a time off by about `a` | follows from the row above | inference |  |
| The third option (carry sample time into the data source) has no mechanism for polled data sources | The `time` column is always `CURRENT_TIMESTAMP()` in `cmd.php`; no hook receives or overrides it before the write | confirmed for `cmd.php`; the `poller_output` hook can see rows, not set measurement times | [cmd.php:341, 399-402](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/cmd.php#L341) |
| PHP poller: all items of a device run in one process | `poller.php` assigns host ranges (`--first`, `--last`) to each `cmd.php` process | confirmed | [poller.php:693-720](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/poller.php#L693-L720) |
| Spine can split one device's items across threads | `device_threads` and `picount/device_threads AS tppi` in Spine's host query | confirmed | [spine.c:482](https://github.com/Cacti/spine/blob/c81b1c07aff3d16e4b9fe5371a194b5d88ebe245/spine.c#L482) |
| So a lazy sweep keyed by device can have two first callers at once under Spine | follows; needs an atomic claim (a database lock or a unique insert) so only one sweeps | inference |  |
| A lazy sweep blocks the data source that triggers it for the sweep's duration | Sweep time per device adds to that device's poll. The PHP poller stops its host loop with the warning "cmd.php poller has run over its polling interval and therefore is ending" once elapsed time exceeds the poll interval, so a slow sweep costs the remaining hosts of that process their polls. The script server read has no timeout under the PHP poller ([cacti-core.md](cacti-core.md)). | confirmed | [cmd.php:424-430](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/cmd.php#L424-L430) |
| Where the plan's `poller_bottom` sweep fits | `poller_bottom` runs after polling in the poller process (and on a remote collector only with `remote_poller:1`); a sweep there fills the cache for the *next* cycle, so its samples are one cycle old when stored | confirmed for the hook's position | [poller.php:864-958](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/poller.php#L864-L958), [plugins.php:187-200](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/plugins.php#L187-L200) |

The evidence favors the proposed lazy per-device sweep on one point (stored time and sample time stay within one device batch) and against it on two (a device's poll stretches by its sweep time, and Spine needs a claim). The prefetch options have a rate error equal to the sample age and no core mechanism to correct it. The decision is not made here: the sweep time per device, the number of ports per device, and the acceptable poll stretch are *live*. A hybrid that fits the evidence is a claimed lazy sweep per device with a stored sample age, which the state data source already carries (`age`).

## Plan consequences

- The plan's Collection section says the sweep runs from `poller_bottom`. That fits a prefetch design (samples one cycle old), not a lazy one. If the lazy design is chosen, the sweep is triggered from the script server input, and `poller_bottom` is for housekeeping and health only. This needs the owner's decision with decision #7.
- "Two PerfMgt queries per port" becomes "two per node per invocation plus two per port" in the arithmetic.
- State per-fabric time and failure budgets, because one dead port costs three seconds by default.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #20 | Time `perfquery` per port, per node list, with and without `-x`, on fabrics near 100, 1,000, 10,000 ports | Sweep time per size; the real round trip |
| #20 | Watch SM and switch management CPU and counters during a sweep at stepped concurrency | The datagram rate a fabric accepts |
| #20 | Time `ibnetdiscover` and `iblinkinfo` with default and larger `-o`, and with `--cache` and `--load-cache` | Discovery cost and cache value |
| #20 | Compare a UFM default-session read with the same counters read by `perfquery` | Whether UFM is a cheaper source in practice |
| #20 | Ask a vendor or the site SM team for polling guidance | The minimum interval |
| #7 | Measure device batch time with a lazy sweep under the PHP poller and Spine at `device_threads` 1 and 2 | Whether the lazy design's poll stretch and claim are acceptable |

## Limits

No measurements. The 30 second UFM interval and the 180 second OpenSM sweep are documented defaults, not observed behavior. Spine's write path for `poller_output` was not read.
