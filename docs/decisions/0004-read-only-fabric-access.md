# 0004. Read the fabric only: no reset, no shell, no sudo by default

Status: Accepted (2026-11-14)

## Context

`perfquery -r` and `-R` reset counters, and `ibqueryerrors -k` and `-K` clear them regardless of what is printed. Counters are shared state: other tools, operators, and fabric managers read them. The tools open the management device, so the user that runs them holds a privilege beyond counter reads. Operator-supplied values such as fabric names and port lists would otherwise reach a command line.

## Decision

- The plugin never resets or clears counters through any tool or API. The argument builder cannot produce reset or clear options, and a test lists every command it can produce.
- Commands run as argument arrays with no shell, from an allowlist of `perfquery`, `ibnetdiscover`, and `iblinkinfo` resolved from a configured absolute directory, with time and output limits and a minimal environment.
- The poller user gets the minimum access research documents per distribution. `sudo` is not used unless research concludes it is acceptable, and then only for exact command lines.
- UFM access is read-only requests only.

## Consequences

- Narrow basic counters can reach their cap and stay there. The plugin prefers 64-bit counters where supported and shows capped counters as unknown ([ADR 0012](0012-saturated-basic-counters-read-as-unknown.md)).
- A reset by another tool shows as a gap.
- The plugin cannot repair a fabric; it only reports.

## Alternatives rejected

- **Reset after read.** Keeps narrow counters alive but destroys other users' data and cannot be undone.
- **Shell wrappers.** Unneeded and open to injection.
- **Running as root.** Gives a monitoring job more privilege than it needs.
