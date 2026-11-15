# PortXmitWait semantics and units

Research for [#16](https://github.com/somethingwithproof/plugin_fabric/issues/16), run 2026-11-15. Question: what does PortXmitWait count in time, how does it relate to link speed, and what normalization makes it comparable across ports?

**Result.** The definition is settled by several sources that quote the same sentence; the tick length is not. No public source gives a duration in nanoseconds for HDR, NDR, or XDR links, and the two NVIDIA statements found disagree on whether one reported count is one byte time or eight. The plugin should therefore keep the plan's choice: show PortXmitWait as ticks per second, add a ratio only where it needs no tick length (waits per transmitted packet, the form UFM uses), and never convert to seconds or to a fraction of link time. A high value shows the port had data and could not send for lack of credits or arbitration. It does not show a fault, a bad cable, or a slow receiver. The IBTA specification text itself was not reachable (member-gated), so every definition below is a secondary quote.

## Method

Public NVIDIA documentation (ibdiagnet manual, UFM guides), the Linux kernel ABI document at v6.12, the rdma-core `perfquery` man page, and one NVIDIA forum thread. Quotes were checked against fetched page text. No counter was read from hardware.

## Definition and tick

| Fact | Result | Status | Source |
|---|---|---|---|
| Definition | "The number of ticks during which the port selected by PortSelect had data to transmit but no data was sent during the entire tick either because of insufficient credits or because of lack of arbitration." | confirmed in secondary sources; the specification text itself unverified | [OpenFabrics list, 2014, quoting a vendor guide](https://lists.openfabrics.org/pipermail/users/2014-March/000346.html); same wording in the [UFM REST API guide, Monitoring REST API](https://networking-docs.nvidia.com/ufmenterpriserestapi/6.26.1/monitoring-rest-api) and in [sysfs-class-infiniband](https://github.com/torvalds/linux/blob/v6.12/Documentation/ABI/stable/sysfs-class-infiniband) |
| IBTA Volume 1 text and tick definition | Not reached | unverified | specification is gated |
| Tick defined as a multiple of the symbol time | "A tick is defined as a multiple of the time required to transfer one byte on an IBA lane, which is also known as the symbol time. For instance, in case of links operating at IBA SDR ... the symbol time is 4 nsec regardless of the link width." The multiplier is the PortSamplesControl `Tick` field, 1x to 256x. | confirmed in the ibdiagnet v2.13 manual only | [ibdiagnet user manual v2.13.0 (PDF), "Congestion Assessment on InfiniBand Links"](https://docs.nvidia.com/ibdiagnet-infiniband-fabric-diagnostic-tool-user-manual-v2-13-0.pdf) |
| Per-ASIC increments | "Quantum, Quantum-2, Quantum-3: increments xmit_wait every 8 bytes regardless of the port width; reports tick == 7; lost bytes per port = Counter*(tick+1)". "Switch-IB, Switch-IB 2, Switch-X: every 32 bytes per lane; tick == 31; lost bytes = Counter*(tick+1)*#lanes". The page's Gb/s formula did not extract. This page is absent from the 2.19 to 2.27 web manuals. | confirmed in that PDF; no later source | same |
| How to read the tick on a device | The manual says to run `perfquery -c <lid> <port>`, which prints the `Tick` field of PortSamplesControl. The option `--smplctl` exists in all versions checked. | confirmed (manual); output *live* | same; [perfquery.c:852](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L852) |
| NVIDIA forum reply on ConnectX-6 | "Each tick (as explained in the IB spec) is a loss of a single Byte (8 bits). The XmitWait HW counter counts each tick as 8 Bytes (64 bits) and the FW multiplies it by 8 to align to spec definition." The reply also says there is no official documentation of the tick duration. | confirmed as a forum statement; informal, and in tension with the table above | [NVIDIA developer forum, 2025-09-17](https://forums.developer.nvidia.com/t/how-to-determine-tick-duration-for-port-xmit-wait-on-connectx-6/342542) |
| A duration in nanoseconds for HDR, NDR, XDR | Not found in any source | NOT FOUND | |
| "Understanding mlx5 Linux Counters" (NVIDIA support article) | Page returned a JavaScript stub; content not reached | unverified | |
| Basic PortXmitWait is 32 bits and zero when unsupported; 64-bit form needs the additional-extended capability | See [counters.md](counters.md) and [infiniband-diags-versions.md](infiniband-diags-versions.md) | confirmed | [perfquery.c:412-418](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L412-L418) |

## How UFM normalizes it

| UFM attribute | Definition | Unit stated | Source |
|---|---|---|---|
| `Infiniband_XmitWait` | the definition above | none | UFM REST API guide, Monitoring REST API |
| `Infiniband_NormalizedXW` | "Congestion in relation to packets transmitted over the link: XmitWait incremental / XmitPackets incremental. This event is calculated only for the port directly connected to receiving hosts." | none; a ratio of two counts | same |
| `Infiniband_Normalized_CBW` | "Amount of bandwidth that was suppressed due to congestion (XmitWait incremental/Time) * Link Capacity" | none; treats ticks per time as a fraction | same |
| `Infiniband_CBW` | "Congestion bandwidth rate, measure the rate of congestion measured by XmitWait counter" | none | same |

UFM states no tick length and no tick-to-time conversion, even for its normalized attributes. That the UFM formula multiplies XmitWait per time by link capacity implies UFM treats one tick as one byte time at the link's lane rate; the guide does not say so, so this is inference.

## Decisions the evidence supports

1. **Ticks per second only, plus one ratio.** Graph the counter as a rate, as the plan says. Optionally graph `wait ticks per transmitted packet` (the UFM form), which needs no tick length. Do not show seconds or percent of time.
2. **What a high value means.** The port had data queued and did not send for lack of credits or arbitration, in whatever tick the device defines. It points at downstream backpressure or contention, not at a fault. A low value does not prove a healthy link, because the counter is zero in basic output when the device does not support it.
3. **Unsupported is unknown.** Already in the plan; this research confirms the tool zeroes it.
4. **No default thresholds.** Already in the plan; a threshold would need the tick length and the hardware generation.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #16 | `perfquery -c <lid> <port>` on each model; read `Tick` | The device's tick multiplier |
| #16 | Read PortXmitWait and PortXmitData on a link running a known load (and an idle link) and fit bytes per tick | Whether one count is one byte time or eight, and the per-speed duration |
| #16 | Run a congested flow in a lab and compare to the idle link | The behavior of a high value |
| #16 | Check whether the 64-bit PortXmitWait is available on each model | The 32-bit wrap interval. If a tick were the 4 ns SDR symbol time, 2^32 ticks is about 17 seconds; whether any current device counts that fast is unknown, and the answer limits the sampling interval |

## Limits

No hardware. The specification text is gated. The two NVIDIA statements on tick size conflict and were not reconciled.
