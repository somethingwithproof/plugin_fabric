# 0008. Sweep lazily per device, under a claim, from the script server

Status: Accepted (2026-11-15); timing pending live measurements

## Context

[ADR 0005](0005-collect-through-a-sweep-cache.md) left open when a sweep runs relative to the poll that stores its values. The evidence ([research](../research/mad-budget.md)): the PHP poller stamps each value with its database insert time and core cannot store a value under another time, so a prefetch from `poller_bottom` skews rates by the change in sample age between cycles. Spine can split one device across `device_threads`, so more than one caller can ask for the same device in a cycle. A prefetch on a remote collector also needs `remote_poller:1`. Live timing on a fabric has not been measured.

## Decision

- A sweep starts lazily, on the first script server call for a mapped device in a cycle (milestone 3 wires the call). `fabric_sweep.php --fabric=ID [--node=GUID] [--force]` runs the same code for an operator.
- A claim row (`plugin_fabric_claim`) per fabric topology and per node, taken with one conditional `UPDATE` and an expiry, makes exactly one caller sweep. Others read the cache. Cacti's `register_process_start()` is not used. In 1.2.31 it reads the row and then inserts, which is not atomic. It identifies a holder by PID, and the sweeping script server process lives on to serve other devices. It sends SIGTERM to a holder that timed out, which here would be a script server process, and logs that as an error.
- A caller checks that the work is still due after it takes the claim, since another sweep may have finished it in between. A topology claim covers one tool run and its import, and is renewed before the second run. Each import reads the claim with a locking read just before it commits and rolls back if the claim expired or moved, so a sweep that outlived its claim never overwrites a newer one. A node claim covers the node budget, the last command started inside it, and its writes.
- A node is due when its last attempt is at least half its fabric's interval old. A topology sweep (`ibnetdiscover`, then `iblinkinfo`) runs first when it is due.
- Each node sweep has a time budget and a failure budget. A spent budget leaves the rest unread and records it as skipped.
- No page request starts a sweep. No `poller_bottom` hook is registered in milestone 2.

## Consequences

- A sample is taken in the same cycle that stores it, so sample age stays about constant and rates are not skewed by a prefetch.
- The first poll of a device waits for its sweep. A slow sweep lengthens that device's poll, bounded by the node budget.
- The claim table is one more table to keep consistent. An expired claim is taken over, so a killed sweep blocks its node for at most the claim time.
- The timing still needs measurement on a fabric. If it shows the lazy sweep is too slow, a prefetch can be added behind the same claim.

## Alternatives rejected

- **Prefetch from `poller_bottom`.** Skews rates by sample age and needs `remote_poller:1` on remote collectors.
- **Cacti process registration as the claim.** Not atomic, keyed by PID, and kills a timed-out holder.
- **A page view that refreshes the cache.** Lets any viewer cause fabric traffic.
