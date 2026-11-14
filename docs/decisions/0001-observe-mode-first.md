# 0001. Ship read-only observe mode first, in phases that each release

Status: Accepted (2026-11-14)

## Context

A fabric plugin could try to cover everything at once: several backends, remote collection, sharding, reports, topology events, and fabric changes. Nothing would reach users until every piece was done, and anything that writes to a fabric needs a far larger review than reading from it.

## Decision

Release in phases that each ship on their own:

1. Observe mode with the diagnostics-tools backend: inventory by GUID, Cacti devices, counters, link state, Thold alerting, Maint suppression, health.
2. A UFM REST backend for sites with a fabric manager.
3. Remote data collectors and sharded sweeps for large fabrics.

The plugin never changes fabric state in any phase. Congestion control data, cable and FEC data, topology events, RoCE, and an API are research.

## Consequences

- Phase 1 is useful to anyone with a node attached to the fabric and the standard tools installed.
- No phase depends on a later one, so a stalled later phase does not strand earlier work.
- Sites that only have UFM access wait for phase 2.

## Alternatives rejected

- **Single full-scope release.** Delays all value and carries the largest maintenance load.
- **UFM first.** Excludes the many fabrics that run without UFM and ties the first release to one vendor's API.
- **A plugin that can change port state.** Needs a write path and its own review, and is a different product.
