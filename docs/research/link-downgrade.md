# Detecting width and speed downgrades

Research for [#17](https://github.com/somethingwithproof/plugin_fabric/issues/17), run 2026-11-15. Question: can the plugin reliably detect that a link runs below the best width and speed both ends support, including extended speeds, and what does it do with administratively limited links?

**Result.** The upstream rule is now read in full for both width and speed. It compares the active value with the best value in the intersection of what both ends **support**, and it never looks at what is **enabled**. So `iblinkinfo`'s `Could be` hint also fires for a link an administrator limited on purpose. More important for the plan: none of `perfquery`, `ibnetdiscover`, or `iblinkinfo` prints any enabled width or speed (zero references to the `*Enabled` fields in all three sources), so the plan's "a link whose enabled value is deliberately below supported is not degraded" cannot be implemented with the three allowlisted tools. Enabled values come from `smpquery portinfo` (one SMP per port) or from UFM, which returns `enabled_width` and `enabled_speed`. The rule below is stated so it works with what the tools print, and the owner has to choose between two phase 1 options (see Decisions). No link was downgraded in a lab: the reproduction and fixture requirements are *live*.

## Method

Read `get_max_msg()`, `get_max_width()`, and `get_max()` in rdma-core `v66.0` (`0fa90c60fbc274edb3495f30c1afd02b3f5fd38e`) `infiniband-diags/ibdiag_common.c`, the extended-speed helpers in `libibnetdisc/ibnetdisc.c`, the speed and width decoders in `libibmad/dump.c`, and the `iblinkinfo.c` and `ibnetdiscover.c` printers. Compared `v39.0`, `v48.0`, and `v66.0` ([infiniband-diags-versions.md](infiniband-diags-versions.md)). To check the reading of the two selection helpers, their exact source text was compiled in a scratch directory and run on a table of inputs; this executes upstream code on constants and touches no fabric.

## The upstream rule

| Fact | Result | Status | Source |
|---|---|---|---|
| Width | `max_width = get_max_width(supported_local & supported_remote)`; the message `Could be <width>` is produced when `(max_width & active_local) == 0`. Both operands are the `LinkWidthSupported` fields of the two ends. | confirmed | [ibdiag_common.c:819-835](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/ibdiag_common.c#L819-L835) |
| Width ordering | `get_max_width` prefers 12X, then 8X, then 4X, then 2X, else 1X, over the encoded bits (1=1X, 2=4X, 4=8X, 8=12X, 16=2X). The encoded values are not lane counts, so "bigger bit" is wrong for 2X. Run on constants: an intersection of 1X|2X (0x11) selects 2X; 1X|4X (0x03) selects 4X; 4X|2X (0x12) selects 4X; an empty intersection selects 1X. | confirmed (source read and run on constants) | [ibdiag_common.c:778-791](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/ibdiag_common.c#L778-L791) |
| Speed, extended path | Used only when both ends' capability mask (switch: port 0's mask) has `IB_PORT_CAP_HAS_EXT_SPEEDS`. `espeed`/`e2speed` are the aggregated *supported* extended-speed bits of each end; if either is zero the code falls back to the FDR10 and legacy checks. Otherwise `max_speed = get_max(espeed & e2speed)` (highest set bit) and `Could be <speed>` is printed when `(max_speed & active_ext_speed) == 0`. | confirmed | [ibdiag_common.c:876-893](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/ibdiag_common.c#L876-L893) |
| Speed, FDR10 | If both ends list FDR10 in the Mellanox extended port info, the active FDR10 bit is checked; a QDR link there yields `Could be FDR10 (Found link at QDR but expected speed is FDR10)`. | confirmed | [ibdiag_common.c:855-862, 894-904](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/ibdiag_common.c#L855-L904) |
| Speed, legacy path | `max_speed = get_max(supported_local & supported_remote)` over SDR 1, DDR 2, QDR 4; `Could be` when `(max_speed & active) == 0`. | confirmed | [ibdiag_common.c:863-875](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/ibdiag_common.c#L863-L875) |
| Extended speed encoding | Bit 1 = FDR (14.0625 Gbps per lane), 2 = EDR (25.78125), 4 = HDR (53.125), 8 = NDR (106.25), bit 16 unused, 32 = XDR (212.5, taken from the second field and shifted left by 5). `get_max` returns the highest set bit, so numeric order follows speed. | confirmed | [ibnetdisc.c:1040-1100](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibnetdisc/ibnetdisc.c#L1040-L1100), [ibnetdiscover.c:85-123](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/ibnetdiscover.c#L85-L123) |
| Extended speed value 30 in an *enabled* field is treated as zero | `if (espeed == 30) espeed = 0` for `IB_PORT_LINK_SPEED_EXT_ENABLED_F` only | confirmed; meaning of 30 not documented in the source | [ibnetdisc.c:1046](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibnetdisc/ibnetdisc.c#L1046) |
| Printed forms differ by tool | `iblinkinfo` prints per-lane rates (`14.0625 Gbps`, `53.125 Gbps`, `10.0 Gbps (FDR10)`) and a width such as `4X`; `ibnetdiscover -p` prints names (`SDR`, `DDR`, `QDR`, `FDR10`, `FDR`, `EDR`, `HDR`, `NDR`, `XDR`) and a width. A parser has to accept both if both tools are used. | confirmed | [iblinkinfo.c:112-150](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/iblinkinfo.c#L112-L150), [ibnetdiscover.c:714-740](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/ibnetdiscover.c#L714-L740) |
| `iblinkinfo` blanks width and speed for a down port | The printer follows C14-24.2.1 (a down port may return invalid values) and prints only state and physical state for `istate == IB_LINK_DOWN`. | confirmed | [iblinkinfo.c:160-183](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/iblinkinfo.c#L160-L183) |
| No tool in the plan's allowlist prints enabled width or speed | `ENABLED` appears 0 times in `iblinkinfo.c`, `ibnetdiscover.c`, and `perfquery.c`. `smpquery portinfo` dumps every PortInfo field including `LinkWidthEnabled`, `LinkSpeedEnabled`, and `LinkSpeedExtEnabled`. | confirmed | [fields.c:129-139, 531](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibmad/fields.c#L129-L139), [smpquery.c:59-66](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/smpquery.c#L59-L66) |
| XDR is missing from `v39.0`, `v44.0`, `v48.0` | The `LinkSpeedExt*2` fields do not exist before `v50.0`. A downgrade check against XDR hardware needs `v50.0` or later tools. | confirmed absent; effect on hardware *live* | blob comparison in the versions file |
| UFM returns the same data as strings and lists | `active_width` `"4x"`, `enabled_width` `["1x","2x","4x"]`, `supported_width`, `active_speed` `"EDR"`, `enabled_speed` list, `supported_speed` list; a down port has `null` and empty lists. | confirmed from documentation examples; no formal field table | [Ports REST API](https://networking-docs.nvidia.com/ufmenterpriserestapi/6.26.1/ports-rest-api) |

## The rule for the plugin

A port is evaluated only when it is in logical state Active and has a remote port in the same sweep.

1. `width_best` is the best width in `supported_local ∩ supported_remote`, ordered by lane count (1X < 2X < 4X < 8X < 12X), not by encoded bit.
2. `speed_best` is the best speed in `supported_local ∩ supported_remote`, ordered by rate (SDR, DDR, QDR, FDR10, FDR, EDR, HDR, NDR, XDR), using the extended path when both ends advertise extended speeds.
3. The port is **below best** when active width is below `width_best` or active speed is below `speed_best`.
4. The port is **degraded** when it is below best and no enabled value at either end explains it (enabled width or speed at some end is the same as or above the active value is the diagnostic case). If enabled values are unknown, the port is reported as **below best, cause unknown**, not as degraded.
5. A port that is not Active is down, never degraded. A port with no remote is unknown for this rule.

Cases the fixtures must cover, and what this research can say about each without a lab:

| Case | Expected result by the rule | Status |
|---|---|---|
| 4X active, 4X best | healthy | rule derived from source |
| 1X or 2X active, 4X best, enabled includes 4X | degraded | rule derived; real output *live* |
| Speed-only (for example HDR active, NDR best) | degraded | rule derived; real output *live* |
| Active 1X, enabled limited to 1X on one end | below best; limited (needs enabled data) | cannot be decided from the three tools |
| One end lacks extended speeds | the extended branch falls back to the legacy and FDR10 checks; result depends on what legacy speed the active link reports | *live* |
| Down port | down | derived |
| XDR link on `v48.0` or older tools | unknown | *live* |

## Decisions for the owner

1. **Where enabled values come from in phase 1.** Option A: add `smpquery` to the allowlist (one SMP per port per sweep, opens the same management device, and `portinfo` is a read). Option B: phase 1 reports "below best", marks the cause unknown, and phase 2 (UFM) supplies enabled values. The plan currently assumes enabled values exist and does not say how.
2. **Which tool is the degraded source.** `iblinkinfo --line` already encodes the supported-intersection rule and prints `Could be` text, which a parser can use directly, at the cost of depending on that text. The alternative is to compute the rule from raw PortInfo fields, which needs `smpquery` anyway.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #17 | Force a 4X link to 1X and 2X (enabled-width limit or a damaged cable) and capture `iblinkinfo --line`, `ibnetdiscover -p`, and UFM `GET /ufmRest/resources/ports` | Fixtures for 4X to 1X and 4X to 2X; whether `Could be` appears; the UFM string values |
| #17 | Force a speed-only downgrade on an HDR or NDR link | The speed fixture; behavior of the extended branch |
| #17 | Limit enabled width on one end and compare `iblinkinfo` with `smpquery portinfo` | Whether `Could be` fires for an administrative limit (the source says yes) |
| #17 | Run the three tools from `v48.0` against an XDR port | What older tools print |

## Limits

Source reading plus a compiled copy of two upstream helper functions. No link was observed. The meaning of the extended speed value 30 and the behavior of FDR10-capable hardware were not examined beyond the source branches.
