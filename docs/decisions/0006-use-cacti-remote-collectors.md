# 0006. Reach remote fabrics through Cacti remote data collectors, not a custom bridge

Status: Accepted (2026-11-14)

## Context

A fabric may be reachable only from a host that is not the Cacti main server. Cacti already has remote data collectors: core provides `call_remote_data_collector()` and a `remote_agent` hook for plugin actions, and `include/global.php` handles the offline and recovery connection modes. servcheck and webseer run work on them.

## Decision

The sweep runs on the Cacti poller that can reach the fabric. A remote host runs a Cacti remote data collector. Configuration tables replicate through `replicate_out`. The main server calls read-only plugin actions through `remote_agent`, which take fabric identifiers only.

## Consequences

- No new daemon, socket, credential store, or protocol.
- A remote fabric host needs a full Cacti remote collector: PHP, a web server, and a local database.
- Remote CLI jobs must be explicit about which database they write and must work in offline and recovery modes.
- `remote_agent` authorizes callers by address, not cryptographically. Documentation requires https and a restricted network between pollers.

## Alternatives rejected

- **Custom bridge service.** The largest security surface and duplicates what Cacti provides.
- **Direct SSH from the main server.** Needs key management and a remote command path.
