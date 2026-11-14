# 0003. Identify nodes by GUID and ports by GUID plus port number

Status: Accepted (2026-11-14), pending hardware evidence for switches

## Context

Each fabric tool prints LIDs, node descriptions, and GUIDs. LIDs are assigned by the subnet manager and change. Node descriptions are set by operators and firmware. GUIDs are assigned to the hardware. The node information record carries a node GUID separate from the port GUID (`libibmad/fields.c`), and the discovery tool prints a port GUID per port (`ibnetdiscover.c`). Whether switch ports report distinct port GUIDs is not confirmed.

## Decision

A node is identified by its node GUID. A port is identified by node GUID and port number, written `<node GUID>:<port>` with a 16-digit lowercase hexadecimal GUID and a `0x` prefix. LID and node description are stored as attributes and never as identity. A replaced adapter or switch has a new GUID and produces a re-link proposal; the plugin never re-links on its own. Two nodes with one GUID are a conflict and are excluded from sweeps.

## Consequences

- Re-addressing and renaming do not break history.
- Hardware replacement needs an operator decision, which keeps old history readable.
- A `Guid` value object is the only place GUID text is normalized.
- If research finds that node GUID plus port number is not unique for some node type, this record is superseded.

## Alternatives rejected

- **LID as identity.** Changes with subnet manager decisions.
- **Node description as identity.** Operator-editable and duplicated in practice.
- **Port GUID alone.** May be shared by all ports of a switch.
