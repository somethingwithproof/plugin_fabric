# 0010. Map each fabric node to one Cacti device, created only on confirmation

Status: Accepted (2026-11-15); decides issue #9. The Cacti maintainers may still revisit it before an upstream proposal

## Context

Issue #9 asked how fabric nodes map to Cacti devices. Ports have to belong to a Cacti device: data queries, graphs, Thold thresholds, and Maint schedules all hang off a device. The plan left the granularity open: one device per node, one per fabric, or switches only. The research ([cacti-core.md](../research/cacti-core.md)) found that Thold checks thresholds only on a device whose status is up, that Maint schedules devices, and that device permissions are the main scoping tool in Cacti. A device per fabric would put every port of a fabric under one permission and one maintenance switch. Switches only would leave adapter ports without alerts.

Many sites already have a Cacti device for each server and switch, polled over SNMP. Creating a second device for the same machine doubles its device count and splits its graphs.

## Decision

- One Cacti device per fabric node, switch or adapter host, keyed by node GUID, with the node's ports as indexes of the "Fabric Port" data query. A node attaches to an existing device when an operator maps it, and otherwise gets a device created for it.
- A fabric node maps to at most one Cacti device, by node GUID. An operator maps it to an existing device on the node's admin page, which lists devices whose host name or description starts with the node description's first word as evidence; the plugin never maps by itself.
- When no device fits, an operator with Cacti's device management permission (`host.php`), on top of Fabric Admin, can create one. A confirmation page lists what is added. The device uses the "Fabric Node" device template, a reserved host name `<GUID>.fabric.invalid`, availability checks and SNMP off, and the poller that sweeps the fabric. If the creating user cannot see the new device under their device permissions, or the node changed meanwhile, the device is removed again.
- Mapping or creating adds the "Fabric Port" data query to the device and reindexes it. Unmapping or a confirmed re-link reindexes the old device. No graph or data source is created; operators choose ports on Cacti's New Graphs page.
- A device on a poller other than the fabric's is refused. More than one node may map to one device, for a server with two adapters; the index carries the node GUID.
- Deleting a mapped device in Cacti clears the mapping through the `device_remove` hook. Deleting a fabric or uninstalling the plugin keeps the devices.

## Consequences

- Thold, Maint, device permissions, and graph trees work per node, as for any device.
- A created device has no availability check, so its status stays as Cacti's poller sets it for such a device. The end-to-end test shows Thold reads it as up.
- A server already in Cacti gets its fabric ports beside its other graphs, with no second device.
- Created devices outlive the plugin's records. That is a divergence from REQ-LIFE-003, which expected the plugin's devices to go with it; removing a device also removes its RRD history, and that is left to the operator.

## Alternatives rejected

- **One device per fabric.** One permission and one maintenance switch for every port.
- **Switches only.** No alerts on adapter ports.
- **Automatic creation at discovery.** Adds devices nobody asked for and needs device management rights the poller does not have.
