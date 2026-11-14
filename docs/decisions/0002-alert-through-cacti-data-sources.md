# 0002. Alert through polled Cacti data sources, not a plugin alert engine

Status: Accepted (2026-11-14)

## Context

Operators want alerts on link downgrades, error bursts, and stale data through the same path as their other Cacti alerts, with Maint suppression. Thold receives values through Cacti's `poller_output` hook, so it only sees data sources the Cacti poller polls.

## Decision

Each port gets a "Fabric Port State" data source using a script server input that returns `active_lanes`, `speed_rank`, `degraded`, `port_state`, and `age` from the sweep cache. Counter data sources are ordinary polled data sources too. Thold thresholds and Maint schedules apply with no change to either plugin. State values return `U` when unknown or stale; `age` is always numeric so staleness itself can alert.

## Consequences

- One notification path for SNMP and fabric data.
- Alerts see present state and counter rates; history is in the RRD files.
- Cacti's poll interval and the sweep cadence are independent, so Thold durations should be at least one poll interval.
- A sweep that falls behind raises `age` before anything else changes.

## Alternatives rejected

- **Plugin-owned incident engine.** Duplicates Thold and Maint and adds a third notification path.
- **Relying on UFM alarms only.** Leaves out sites without UFM and keeps alerts outside Cacti's permissions and maintenance.
