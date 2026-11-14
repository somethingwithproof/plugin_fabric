# 0005. Collect through a sweep cache, not one command per port

Status: Accepted (2026-11-14); timing pending evidence

## Context

A fabric has thousands of ports and each query is a management datagram. Starting a process per port per poll multiplies process cost and fabric load. Cacti's poller reads values through script server calls, one data source at a time.

## Decision

A sweep job per fabric reads all watched ports once per cycle, through the command runner or the UFM client, and writes samples with their observation times to a cache table. The script server input and the port data query read only the cache. No page request and no poll call starts a sweep on its own, except as the timing decision may allow for a per-device sweep on the first call of a cycle.

## Consequences

- Fabric load is bounded by the sweep, not by the number of data sources.
- Poll calls are fast and need no privilege.
- Counter rates depend on how the sample time relates to the time Cacti stores the value, so sweep timing is a decision with evidence requirements.
- The cache is a second copy of values that also go to RRD files.

## Alternatives rejected

- **One command per port per poll.** Too many processes and too many queries.
- **A permanent daemon.** A new service, credential surface, and lifecycle.
