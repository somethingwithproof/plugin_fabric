# Decision records

Each record captures one architecture decision: its context, the decision, its consequences, and the alternatives rejected. Records are numbered and never renumbered. A reversed decision gets a new record that supersedes the old one.

| Record | Decision | Status |
|---|---|---|
| [0001](0001-observe-mode-first.md) | Ship read-only observe mode first, in phases that each release | Accepted |
| [0002](0002-alert-through-cacti-data-sources.md) | Alert through polled Cacti data sources, not a plugin alert engine | Accepted |
| [0003](0003-identify-by-guid-and-port-number.md) | Identify nodes by GUID and ports by GUID plus port number | Accepted, context corrected 2026-11-15, pending hardware evidence for switches |
| [0004](0004-read-only-fabric-access.md) | Read the fabric only: no reset, no shell, no sudo by default | Accepted |
| [0005](0005-collect-through-a-sweep-cache.md) | Collect through a sweep cache, not one command per port | Accepted, timing pending evidence |
| [0006](0006-use-cacti-remote-collectors.md) | Reach remote fabrics through Cacti remote data collectors, not a custom bridge | Accepted |
| [0007](0007-php-82-floor-with-80-parse-guard.md) | Require PHP 8.2, keep entry points parseable on PHP 8.0 | Accepted |
| [0008](0008-lazy-per-device-sweep-with-a-claim.md) | Sweep lazily per device, under a claim, from the script server | Accepted, timing pending live measurements |
| [0009](0009-command-allowlist-and-privileges.md) | Run three allowlisted tools with fixed arguments; no smpquery, no sudo, no ibdiagnet | Accepted |
| [0010](0010-one-device-per-node.md) | Map each fabric node to one Cacti device, created only on confirmation | Accepted |
| [0011](0011-derive-counters-and-gauge-alert-values.md) | Graph counters as DERIVE; alert only on GAUGE values the plugin computes | Accepted |
| [0012](0012-saturated-basic-counters-read-as-unknown.md) | Prefer 64-bit counters; a saturated basic counter reads as unknown | Accepted, pending hardware evidence |

Open decisions are tracked as issues labelled `type: decision`.
