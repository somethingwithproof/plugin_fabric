# Node and port identity, GUID stability, and discovery

Research for [#18](https://github.com/somethingwithproof/plugin_fabric/issues/18), run 2026-11-15. Question: are node GUID and port number a stable identity for every node type, which tool output carries them, and what does discovery cost?

**Result.** Node GUID plus port number is a sound identity, and the source shows why port GUID alone is not: libibnetdisc gives every external port of a switch the same PortGUID, the one from the switch's NodeInfo (port 0). The plan's discovery row needs a correction, though. `ibnetdiscover -p` and `iblinkinfo --line` print port GUIDs and never print the node GUID, so identity cannot be built from them. The default `ibnetdiscover` topology output and `ibnetdiscover --list` carry the node GUID, and the topology output also prints the switch's port 0 GUID on the same line, which is the key that joins `iblinkinfo` output (which labels a switch by its port 0 GUID) to node GUIDs. The tools do not detect two devices that claim one GUID: libibnetdisc finds nodes by node GUID, so the second device is merged into the first and its links silently replace the first's. The plugin has to detect duplicates itself. Hardware behavior (whether switch port GUIDs really are shared, which GUIDs change on replacement, how virtual functions behave) is *live*; the statements below about it are source reading only.

## Method

Read rdma-core `v66.0` (`0fa90c60fbc274edb3495f30c1afd02b3f5fd38e`) `libibnetdisc/ibnetdisc.c`, `infiniband-diags/ibnetdiscover.c`, and `infiniband-diags/iblinkinfo.c`, and compared the same files at `v39.0` and `v48.0` by blob ID (see [infiniband-diags-versions.md](infiniband-diags-versions.md)). Nothing was executed.

## Findings

| Fact | Result | Status | Source |
|---|---|---|---|
| A port created while reading PortInfo gets the node's NodeInfo PortGUID | `port->guid = mad_get_field64(node->info, 0, IB_NODE_PORT_GUID_F)` | confirmed | [ibnetdisc.c:354](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibnetdisc/ibnetdisc.c#L354) |
| A port reached through a NodeInfo reply gets that reply's PortGUID | `port->guid = port_guid` from the reply for `IB_NODE_LOCAL_PORT_F`. For an HCA this is the GUID of the port the reply came from; for a switch every reply carries the same switch-level PortGUID. | confirmed by source; per-port HCA GUIDs on real hardware *live* | [ibnetdisc.c:533-564](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibnetdisc/ibnetdisc.c#L533-L564) |
| So all external ports of one switch show one PortGUID | Follows from the two rows above. ADR 0003's rejected alternative "port GUID alone" is correct for switches. | confirmed by source; hardware *live* | same |
| `ibnetdiscover -p` prints the port GUID of each port and of the remote port, with LID, and no node GUID | `fprintf(stdout, "%2s %5d %2d 0x%016" PRIx64 ...)` with `port->guid`; the switch LID is printed for switch ports | confirmed | [ibnetdiscover.c:714-740](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/ibnetdiscover.c#L714-L740) |
| `iblinkinfo --line` prints `port->guid` and the node name; the remote GUID is `remoteport->guid` | Port GUIDs again, no node GUID. In the default (non-line) mode the switch header prints the GUID of port 0, and a CA header prints the name only. | confirmed | [iblinkinfo.c:207-248, 262-293](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/iblinkinfo.c#L207-L293) |
| Default `ibnetdiscover` topology output prints the node GUID, and for a switch also the port 0 GUID | `switchguid=0x<node guid>(<port guid>)` for switches; `caguid=0x<node guid>` for adapters (`rtguid` for routers); CA port lines carry `[<port>](<port guid>)`, switch port lines carry `[<port>]` only | confirmed | [ibnetdiscover.c:284-290, 342-345, 443](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/ibnetdiscover.c#L284-L290) |
| `ibnetdiscover --list` prints one line per node with the node GUID, port count, device ID, vendor ID, and name | `Switch : 0x<node guid> ports N devid 0x.. vendid 0x.. "name"` | confirmed | [ibnetdiscover.c:184-212](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/ibnetdiscover.c#L184-L212) |
| Node name maps are keyed by node GUID | `remap_node_name(node_name_map, node->guid, node->nodedesc)` in all three tools | confirmed | same files |
| Duplicate node GUID is not diagnosed | `ibnd_find_node_guid()` returns the existing node for a repeated node GUID, so a second device is treated as the first ("old" node). `link_ports()` then clears the earlier remote link of both ports before setting the new one. The "Duplicate Node" and "Duplicate Port" errors fire only when the same node or port object is inserted into the hash twice, which a second device cannot cause. | confirmed by source; behavior with a real duplicate *live* | [ibnetdisc.c:495-508, 544, 633-668](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibnetdisc/ibnetdisc.c#L495-L508) |
| `ibidsverify` exists to check for duplicate LIDs and GUIDs | The man page says "or duplicate identifiers"; it is a Perl script that calls the other tools. Not examined further. | unverified for use | [ibidsverify.8.in.rst](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/man/ibidsverify.8.in.rst), [ibidsverify.pl:151](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/scripts/ibidsverify.pl#L151) |
| The tools behave the same in `v39.0` to `v66.0` for the lines above | The GUID assignment (`port->guid = ...`) and the node lookup by node GUID are present at the same places in `v39.0` and `v66.0`. `libibnetdisc/ibnetdisc.c` differs between them in speed decoding, a map-insert guard, and the MAD port open, with a whitespace-insensitive diff read in full. | confirmed | git diff `v39.0` to `v66.0` of `libibnetdisc/ibnetdisc.c` |
| Discovery sends directed-route SMPs, not PerfMgt MADs | `issue_smp(... IB_ATTR_PORT_INFO ...)`, `query_node_info`, `query_node_desc`, `query_switch_info`. One NodeDesc and SwitchInfo per new node, PortInfo for every switch port (including down ports) and for each CA port, and a NodeInfo query for every link-up switch port that it traverses. | confirmed by source; counts per fabric size *live* ([mad-budget.md](mad-budget.md)) | [ibnetdisc.c:372-452, 585-600](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibnetdisc/ibnetdisc.c#L372-L452) |
| Discovery defaults: 2 SMPs on the wire, 1000 ms timeout, 3 retries | `DEFAULT_MAX_SMP_ON_WIRE 2`, `DEFAULT_TIMEOUT 1000`, `DEFAULT_RETRIES 3` in libibnetdisc; both `ibnetdiscover` and `iblinkinfo` expose `-o` for the outstanding count | confirmed | [internal.h:56-58](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibnetdisc/internal.h#L56-L58) |
| `--cache` writes a discovery and `--load-cache` reads one back | Option exists in all three tools. Whether a cached read avoids all fabric queries, and how stale a cache may be, were not measured. | confirmed option; use *live* | option tables |

## Identity rule the research supports

1. Take node GUID, node type, port count, and display name from the default `ibnetdiscover` topology output (or `--list` for nodes only). Do not take identity from `-p` or `iblinkinfo --line`.
2. Record each switch's `switchguid=0x<node>(<port 0>)` pair. Use the port 0 GUID only to join `iblinkinfo` rows (which label a switch by that GUID) to the node record. Do not use a port GUID as a port key for switches.
3. A port key is `<node GUID>:<port number>`, as in ADR 0003. For channel adapters the port GUID printed on `[n](guid)` is an attribute, not part of the key.
4. A GUID seen with a different node type, port count, or neighbor set between two sweeps is flagged as a possible conflict. The tools give no signal of their own (see the duplicate row), so the plan's statement "Two nodes reporting one GUID are recorded as a conflict" needs a detection rule, not just a state.

## Re-link scenarios

| Scenario | What the source supports | Status |
|---|---|---|
| Replaced HCA | New node GUID, so a new node record and, per the plan, a re-link proposal. That GUIDs are fixed to the hardware (and so change on replacement) is a vendor property, not something these sources show. | unverified; *live* |
| Replaced switch | Same reasoning. The switch's port 0 GUID is separate from its node GUID in the output, and both would change; whether either survives a management-board replacement is vendor specific. | unverified; *live* |
| Moved cable | Node GUIDs are unchanged and the remote port in the link record changes. The plan's rule (same neighbor and port after a node disappears) cannot be matched on neighbor identity if the neighbor is a switch whose ports all share one PortGUID; it must match on neighbor node GUID plus port number. | confirmed by source |
| Firmware change | No GUID source in the tools changes with firmware; vendors can reprogram adapter GUIDs. | unverified; *live* |
| Virtual functions | Whether an SR-IOV virtual function appears as its own node or port, and which GUID it reports, was not examined. | unverified; *live* |

## Plan and ADR consequences

- Plan "Fabric tools" row: `ibnetdiscover -p` is not sufficient for inventory identity. Discovery uses the default topology output.
- ADR 0003 context: the sentence "the discovery tool prints a port GUID per port" is true only of `-p`, `iblinkinfo --line`, and CA ports in the default output; the default output prints no port GUID for switch ports. The record's status stays "pending hardware evidence", now narrowed to hardware behavior.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #18 | Run `ibnetdiscover`, `ibnetdiscover -p`, `ibnetdiscover -l`, `iblinkinfo --line` on a fabric with at least one switch and one dual-port HCA; compare switch port GUIDs | Whether all ports of a switch print one PortGUID in practice; whether node GUID equals the port 0 GUID on common switches |
| #18 | Replace an HCA and a switch (or use a lab pair) and record GUIDs before and after | The re-link scenarios |
| #18 | Present two devices with one node GUID (lab) and run discovery | What an operator actually sees |
| #18 | Count SMPs sent by `ibnetdiscover` on fabrics of known size (SM or switch counters) | Discovery cost per port, and whether `--cache` avoids it |

## Limits

No hardware, no tool execution. Versions between `v39.0` and `v66.0` were compared by blob ID and the two ends by diff; intermediate `libibnetdisc` changes were not read line by line.
