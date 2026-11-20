# 0009. Run three allowlisted tools with fixed arguments; no smpquery, no sudo, no ibdiagnet

Status: Accepted (2026-11-15); allowlisted tool files added to the sweep check (2026-11-19)

## Context

[ADR 0004](0004-read-only-fabric-access.md) set the read-only rules and left three choices to milestone 2. First, whether to add `smpquery portinfo` to read enabled link width and speed: the allowlisted tools do not print them, and without them a link below best cannot be told apart from one an administrator limited ([research](../research/link-downgrade.md)). Second, whether to document `sudo` for the tools. Third, whether `ibdiagnet` is an input in phases 1 to 3. The user MAD device has no capability check. Anyone who can open `/dev/infiniband/umad<N>` can send any management datagram, including the port changes `ibportstate` makes ([research](../research/privileges.md)).

## Decision

- The allowlist is `perfquery`, `ibnetdiscover`, and `iblinkinfo`, resolved from one administrator-set absolute directory. Each tool has a fixed option set:
  - all three: `-t <ms>`, `-C <adapter>`, `-P <port>`
  - `perfquery`: also `-x`, then exactly a LID and a port list
  - `ibnetdiscover`: also `--node-name-map`
  - `iblinkinfo`: also `--line` and `--node-name-map`
- Every value is validated before it reaches an argument vector:
  - LIDs are 1 to 0xBFFF and ports 1 to 254. A port list has at most 64 ports and no repeats.
  - Adapter names cannot start with `-` and contain no separator. Paths are absolute, contain no `.` or `..` part, and use a restricted character set.
  - A test enumerates every vector the builder produces.
- Commands run without a shell. They run with `PATH` and `LC_ALL` only, from `/`, with standard input from `/dev/null`, and with a time and output limit. Output that is cut short is never parsed. A failure is recorded as an error, never as a zero.
- No `smpquery`. A link below best is reported as below best with the cause unknown, and its degraded value is unknown (`U`) until the UFM backend supplies enabled values.
- `sudo` is never used and never documented. The documented grant is a dedicated group with mode 0660 on `umad*`. The plugin records on its health page when it runs as root, set-ID, from a tool directory others can write to, or with a world-accessible UMAD node. The tool directory itself must be an absolute, canonical path to a real directory with no symbolic link in it, owned by root, and writable by neither group nor others. The settings form refuses any other directory, and each sweep checks it again and runs nothing when it fails. Each allowlisted tool present in it must also be owned by root, writable by neither group nor others, and not set-ID; a sweep that finds one that is not runs nothing, because whoever can write the file chooses what the poller runs. The health page still records these conditions as warnings.
- `ibdiagnet` is excluded from phases 1 to 3.

## Consequences

- An administratively limited link and a degraded one look the same in phase 1. Alerts on degradation wait for the UFM backend or a later decision.
- No extra SMP per port per sweep.
- The poller user still holds a fabric privilege through the UMAD group. M_Key protection on the fabric remains the main control.

## Alternatives rejected

- **`smpquery portinfo` per port.** One more tool with set operations in the same package, and one SMP per port per sweep, for a value only the degraded rule uses.
- **Documented `sudo` rules for exact command lines.** Adds a root path and a second policy to maintain. The group grant gives the same access.
- **`ibdiagnet`.** Not in rdma-core, writes files, takes two counter snapshots by default, has reset options, and its CSV columns are undocumented.
