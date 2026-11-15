# Privileges, umad access, and least-privilege execution

Research for [#19](https://github.com/somethingwithproof/plugin_fabric/issues/19), run 2026-11-15. Question: what does the poller user need to run `perfquery`, `ibnetdiscover`, and `iblinkinfo`, how is it granted without root or `sudo`, and what could a compromised poller user do with it?

**Result.** The tools open `/dev/infiniband/umad<N>` read-write and need nothing else from the kernel. The kernel gives that node no mode of its own, so devtmpfs creates it `root`, mode `0600`, and neither rdma-core's udev rule nor systemd's default rules change that. Access is therefore a local policy choice: one udev rule that sets a dedicated group and mode `0660`, plus membership of the poller user. That grant is broad. The kernel's user MAD driver has no capability check on opening the device, registering an agent, or sending, so a process with the device can send any MAD, not just the Get requests the tools send. The same package ships `ibportstate`, whose documentation says it can disable, enable, and reset a switch port and set link speed, width, LID, and M_Key with the same access. A fabric protected by M_Key is the main control, and when it is read-protected the tools themselves need the key. Recommendation: document the group grant only and do not document `sudo`. Group names and the real behavior on each distribution, in containers, and under systemd sandboxing are *live*: no host with an InfiniBand device was available.

## Method

Read rdma-core `v66.0` (`0fa90c60fbc274edb3495f30c1afd02b3f5fd38e`): `libibumad/umad.c`, `libibmad`, `kernel-boot/rdma-umad.rules`, `infiniband-diags/ibdiag_common.c`, the man pages, and `buildlib/config.h.in`. Read Linux v6.12 `drivers/infiniband/core/user_mad.c` and `drivers/base/devtmpfs.c`, and systemd's `rules.d/50-udev-default.rules.in` at `main` (`6bd4689f43c9f24e98efe63e743a67f8fba1ee1b`). No tool was run.

## Findings

| Fact | Result | Status | Source |
|---|---|---|---|
| Device nodes the tools open | `/dev/infiniband/umad<N>`, one per port, opened with `O_RDWR` and `O_NONBLOCK` (`RDMA_CDEV_DIR` is `/dev/infiniband`). The library also reads the UMAD ABI version and port mapping from sysfs, which is world readable. `issm<N>` nodes (SM registration) are not opened by the diagnostic tools. | confirmed | [umad.c:725-770](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibumad/umad.c#L725-L770), [config.h.in:16](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/buildlib/config.h.in#L16) |
| Kernel mode for the node | `umad_devnode()` returns the name only and sets no mode. | confirmed | [user_mad.c:1239-1242](https://github.com/torvalds/linux/blob/v6.12/drivers/infiniband/core/user_mad.c#L1239-L1242) |
| Resulting default | devtmpfs: "owned by root and have a default mode of 0600"; `if (req.mode == 0) req.mode = 0600;` | confirmed | [devtmpfs.c:12, 129-130](https://github.com/torvalds/linux/blob/v6.12/drivers/base/devtmpfs.c#L129-L130) |
| rdma-core's own rule changes no ownership | `SUBSYSTEM=="infiniband_mad", KERNEL=="*umad*", TAG+="systemd", ENV{SYSTEMD_ALIAS}=...`. No `MODE` or `GROUP` appears in any `kernel-boot` rule. | confirmed | [rdma-umad.rules](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/kernel-boot/rdma-umad.rules) |
| systemd's default rules mention neither | the default rules file contains no `infiniband` or `umad` entry | confirmed at the pinned commit | [50-udev-default.rules.in](https://github.com/systemd/systemd/blob/6bd4689f43c9f24e98efe63e743a67f8fba1ee1b/rules.d/50-udev-default.rules.in) |
| Actual mode and group on each distribution | Distribution packages or site rules may differ from the upstream default | *live* | |
| Kernel access control on the device | The driver file has no `capable()` or `ns_capable()` call; `ib_umad_open`, agent registration, and `write` check nothing but device state. Registration accepts any management class and method mask the caller names. | confirmed by reading (no match for capability checks in the 1,516-line file) | [user_mad.c](https://github.com/torvalds/linux/blob/v6.12/drivers/infiniband/core/user_mad.c) |
| What the diagnostic tools send | `perfquery` opens SMI, SA, and PerfMgt classes; its operations are Get, plus Set for `-r` and `-R`. `ibnetdiscover` and `iblinkinfo` send directed-route SMP Gets. | confirmed | [perfquery.c:900-930](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/perfquery.c#L900-L930), [libibnetdisc/ibnetdisc.c](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/libibnetdisc/ibnetdisc.c) |
| What the same access allows | `ibportstate` "allows ... a switch port to be disabled, enabled, or reset", HCA port state to change locally, and "the link speed/width enabled on any IB port to be adjusted"; its ops include `mkey`, `mkeylease`, `mkeyprot`, `lid`, `smlid`. `smpdump` sends raw SMPs. Both ship in the same package that provides the plugin's three tools. | confirmed (man pages) | [ibportstate.8.in.rst](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/man/ibportstate.8.in.rst) |
| Whether the fabric accepts a Set from an arbitrary node | Depends on M_Key protection at the target and on subnet configuration. The tools accept `-y <key>`; `-y x` prompts; the config file may also set `m_key`. | partly confirmed (option and config key); fabric behavior *live* | [ibdiag_common.c:162-163, 283-290, 319](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/ibdiag_common.c#L162-L163) |
| A secret on the command line | `-y <key>` would put an M_Key in `ps` output. The plan's rule that credentials never appear on a command line therefore needs the config-file route for M_Key, if a fabric needs one. | inference | |
| Config file path | `/etc/infiniband-diags/ibdiag.conf` ships in the `infiniband-diags` Debian package; the man pages say the global config sets common options for all tools. Every tool accepts `-z <config>` to use another file ([ibdiag_common.c:310](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/infiniband-diags/ibdiag_common.c#L310)). | confirmed (file in package list) | [debian/infiniband-diags.install](https://github.com/linux-rdma/rdma-core/blob/0fa90c60fbc274edb3495f30c1afd02b3f5fd38e/debian/infiniband-diags.install) |
| The tools need a local IB port | `umad` devices exist only for ports with the MAD capability; a host with no IB device cannot run them | confirmed | [user_mad.c:1162](https://github.com/torvalds/linux/blob/v6.12/drivers/infiniband/core/user_mad.c#L1162) |
| The tools read environment | no `getenv` in `ibdiag_common.c`; options come from the command line and the config file | confirmed for that file only | grep of `ibdiag_common.c` |
| Prior art | `infiniband_exporter` documents either a readable UMAD device or `sudo` for exactly two programs | confirmed (README, cited in the plan) | [README](https://github.com/treydock/infiniband_exporter#readme) |

## Least-privilege grant

The same udev rule works on any systemd distribution, because upstream sets no mode and the subsystem name is fixed by the driver (`infiniband_mad`, [user_mad.c](https://github.com/torvalds/linux/blob/v6.12/drivers/infiniband/core/user_mad.c)). Proposed, untested:

```
# /etc/udev/rules.d/60-cacti-ib-umad.rules
SUBSYSTEM=="infiniband_mad", KERNEL=="umad*", GROUP="cacti-ib", MODE="0660"
```

then create the group, add the poller user, reload the rules, and trigger the subsystem. `issm*` nodes are not matched and stay root only. Per-distribution steps differ only in the group-creation command and the poller account name, neither of which was verified here. **Per-distribution verification of the steps is *live*.**

Residual risk, in plain terms: the poller account can send any management datagram on that port. If the fabric does not protect its ports with an M_Key, that includes disabling ports and changing link and LID settings. Mitigations the plugin can document: dedicated group and host; M_Key protection on the fabric, which does not stop a poller that holds the key (the key sits in the config file the poller reads, so a compromised poller can use it for protected Sets); no other use of the poller account; running the sweep from a dedicated account on a remote data collector so only that host holds the grant.

## Sudo

Using `sudo` limited to exact command lines is the fallback the prior art describes. It is worse for three reasons that follow from the sources: it runs a C parser of fabric-supplied text (node descriptions) as root, root can also run the other tools in the package, and a root-run tool writes any `--cache` or output path it is given. The group grant above exposes the device but not the rest of the system. Recommendation for the owner: the plugin does not document `sudo`. This is a decision for the owner.

## Containers and systemd sandboxes

Not tested. Documented knobs: systemd's `DevicePolicy=` and `DeviceAllow=` ("only allow types of access that are explicitly specified") and `PrivateDevices=` ([systemd.resource-control](https://www.freedesktop.org/software/systemd/man/latest/systemd.resource-control.html), [systemd.exec](https://www.freedesktop.org/software/systemd/man/latest/systemd.exec.html)). Whether the tools work with only `/dev/infiniband/umad<N>` passed through, and whether `PrivateDevices=yes` hides it, is *live*. Cacti's poller normally runs from cron or a timer rather than as a service, so these settings apply only if an operator wraps the poller.

## Input to the command execution requirements

- The command runner resolves tools from a configured absolute directory; upstream packages install to `/usr/sbin` (Debian list), not `/usr/bin`.
- Never pass `-y`; document the config-file route if a fabric needs an M_Key.
- Pin `-C` and `-P` so the sweep always uses the same adapter and port.
- Do not allowlist `ibportstate`, `smpdump`, `ibqueryerrors`, or any tool in the package other than the three, and add `smpquery` only after the owner decision in [link-downgrade.md](link-downgrade.md).

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #19 | `stat /dev/infiniband/umad*` and the udev rules present on Debian, Ubuntu, a RHEL family system, and SLES with rdma-core installed | Real default ownership per distribution |
| #19 | Apply the rule above, run the three tools as a member and as a non-member | The grant works; non-members are refused |
| #19 | With OpenSM or UFM's SM running on the same host and port, run the tools | Whether the grant disturbs other users of the HCA |
| #19 | On a fabric with M_Key protection and read-protection levels, run the tools without and with a key | Whether the tools work, and which `ibdiag.conf` setting is needed |
| #19 | Run inside a container with only the umad node passed through, and under `DevicePolicy=closed` | Whether sandboxing is practical |

## Limits

No InfiniBand host. The conclusion that the kernel checks no capability rests on a text search of one file and reading its open, register, and write paths; it was not tested by running as an unprivileged user.
