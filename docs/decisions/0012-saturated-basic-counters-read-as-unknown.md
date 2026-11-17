# 0012. Prefer 64-bit counters; a saturated basic counter reads as unknown

Status: Accepted (2026-11-15); confirming the behavior needs captures from real hardware

## Context

Basic PortCounters fields are narrow: LinkDownedCounter and LinkErrorRecoveryCounter are 8 bits, SymbolErrorCounter and PortRcvErrors are 16 bits, and PortXmitWait is 32 bits. infiniband-diags documents them as capping at their maximum, not wrapping. The plugin never resets counters ([ADR 0004](0004-read-only-fabric-access.md)), so a narrow counter that reaches its cap stops changing, and a graph of it would look healthy. The 64-bit forms exist only on ports that report extended counter support. Issue #8 listed three treatments and named the first as the proposal; [ADR 0004](0004-read-only-fabric-access.md) points to this decision.

## Decision

- The plugin reads the 64-bit extended form of a counter whenever the port reports support for it, and keeps one width per data source.
- A basic counter at its maximum is saturated. Its value and any rate computed from it publish `U`, never the capped value and never `0`, and the port page shows the counter as saturated. A counter at its maximum in the previous sample also gives no rate ([ADR 0011](0011-derive-counters-and-gauge-alert-values.md)).
- The plugin never resets or clears a counter to recover from saturation, through any tool, option, or setting.

## Consequences

- On ports without extended counters, a saturated error counter leaves a gap in its graph and in its alert rate until something else resets it; the port page says why.
- A reset by another tool shows as a discontinuity, and the counter is read again from there.
- The behavior is built and tested against generated fixtures and the ibsim simulator. Whether real HCAs and switches cap exactly at the documented widths, and which report extended support, needs captures from real hardware ([research](../research/counters.md)); until then this decision rests on the tools' documentation.

## Alternatives rejected

- **Opt-in reset of basic counters per fabric.** Keeps graphs alive on old hardware but clears counters that other tools and operators read, and breaks the read-only guarantee.
- **Basic error counters as events only.** Alerting on change without graphs loses the history operators use to judge a link, and a saturated counter still never changes.
