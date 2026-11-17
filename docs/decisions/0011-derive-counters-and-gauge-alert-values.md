# 0011. Graph counters as DERIVE; alert only on GAUGE values the plugin computes

Status: Accepted (2026-11-15); the saturation treatment is [ADR 0012](0012-saturated-basic-counters-read-as-unknown.md)

## Context

Fabric counters are cumulative. Basic counters are 8 to 32 bits wide and stop at their maximum instead of wrapping; extended counters are 64 bits. Another tool, such as OpenSM's performance manager, may reset them. RRDtool's COUNTER type treats a decrease as a wrap and records a spike; DERIVE with a minimum of 0 records unknown instead.

Thold 1.8.2 fails with a PHP TypeError when a thresholded COUNTER or DERIVE data source reads `U`, and Cacti then disables Thold for the whole site. Every value the plugin publishes is `U` at some point: unsupported, saturated, stale, or just after a reset.

## Decision

- Counters as received are DERIVE data sources with minimum 0 and a plausibility maximum, for graphs only: Fabric Port Traffic, Errors, and Congestion. Data counters stay in the tools' unit of 4 octets; the graph multiplies by 4.
- Values meant for thresholds are GAUGE data sources: the link state values (`active_lanes`, `speed_rank`, `degraded`, `port_state`, `age`) and five rates in Fabric Port Alert Rates (`symbol_err_rate`, `rcv_err_rate`, `link_downed_rate`, `xmit_discard_rate`, `xmit_wait_rate`).
- The plugin computes each rate from its own two most recent samples of the port. A decrease is a discontinuity: the rate is unknown, the port's discontinuity count and time go up, and the port page shows it. A counter at its maximum, now or in the previous sample, is saturated and its rate unknown. A rate needs a current sample.
- A saturated or unsupported counter publishes `U`, never `0` and never a reset. The plugin prefers the 64-bit form when the port supports it, and keeps one width per data source.

## Consequences

- A reset never draws a spike and never fires or clears a threshold by itself. Thold sees `U` on a GAUGE and skips the check.
- The rates are over the plugin's sampling interval, not Cacti's. If the fabric's sweep interval differs from the poller interval, a repeated sample reads as a rate of 0 or the same rate twice; the fabric interval should equal the poller interval.
- 27 data sources per port, against the 25 of the research layout: the alert rates are added, and the port error group holds nine counters (QP1Dropped and the unicast and multicast packet counters are left out).

## Alternatives rejected

- **COUNTER type.** Draws a spike on every reset.
- **Thresholds on DERIVE data sources.** Disables Thold site-wide on the first `U`.
- **Resetting saturated counters.** Writes to the fabric ([ADR 0004](0004-read-only-fabric-access.md)).
