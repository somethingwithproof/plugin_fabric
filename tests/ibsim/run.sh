#!/bin/sh
# SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
# SPDX-License-Identifier: GPL-2.0-or-later
#
# Runs inside the pinned Ubuntu container (see the ibsim-fabric job). Starts the
# linux-rdma ibsim fabric simulator and OpenSM under the libumad2sim LD_PRELOAD
# shim, then runs the real ibnetdiscover, iblinkinfo and perfquery against the
# simulated fabric and writes their raw output to $2.
#
#   sh run.sh <repo dir> <output dir>
#
# Needs root only for apt-get; no extra Linux capabilities, no /dev/infiniband.
# Every tool runs under timeout, and the simulator and OpenSM under a
# watchdog that kills them after 300 s, so a hung process fails the job
# instead of holding the runner. (timeout cannot wrap those two: they are
# stopped with SIGKILL, which timeout cannot pass on to its child.) tests/bin/check-ibsim-fixtures.php
# then reads the output through the plugin's own parsers.
set -eu
export DEBIAN_FRONTEND=noninteractive
REPO=$1
OUT=$2
TOPO_DIR=$REPO/tests/ibsim/topo

# Exact archive versions, so a fixture change is a deliberate bump. Ubuntu
# removes superseded security builds from the archive; bump these together
# with the image digest when `apt-get install` reports a missing version.
apt-get update -qq
apt-get install -y -qq --no-install-recommends \
  ibsim-utils=0.10-2build1 \
  infiniband-diags=50.0-2ubuntu0.2 \
  libibumad3=50.0-2ubuntu0.2 \
  opensm=3.3.23-2 >/dev/null

SO=$(find /usr/lib -name libumad2sim.so | head -n 1)
[ -n "$SO" ] || { echo "libumad2sim.so not found" >&2; exit 1; }

fail=0
check() { # check <description> <command...>
  desc=$1; shift
  if "$@"; then echo "ok   $desc"; else echo "FAIL $desc"; fail=1; fi
}

run_scenario() {
  sc=$1
  d=$OUT/$sc
  rm -rf "$d"; mkdir -p "$d/osm"
  # Attach at node01, as the plugin's poller attaches at a compute node.
  export SIM_HOST=node01
  ibsim -s "$TOPO_DIR/$sc.net" --no-console >"$d/ibsim.log" 2>&1 &
  sim_pid=$!
  (sleep 300; kill -9 "$sim_pid" 2>/dev/null) &
  sim_dog=$!
  sleep 3
  kill -0 "$sim_pid" 2>/dev/null || { echo "ibsim exited; see $d/ibsim.log" >&2; exit 1; }
  OSM_TMP_DIR=$d/osm OSM_CACHE_DIR=$d/osm LD_PRELOAD=$SO \
    opensm -f "$d/osm.log" >"$d/opensm.out" 2>&1 &
  osm_pid=$!
  (sleep 300; kill -9 "$osm_pid" 2>/dev/null) &
  osm_dog=$!

  export LD_PRELOAD=$SO
  # Wait until OpenSM has swept the fabric and the local port is Active.
  i=0
  until timeout 10 ibstat 2>/dev/null | grep -q 'State: Active'; do
    i=$((i + 1))
    [ "$i" -le 60 ] || { echo "SM never brought the port Active" >&2; exit 1; }
    sleep 1
  done

  tool() { # tool <name> <command...>  (stdout, stderr, command and rc kept)
    n=$1; shift
    echo "$*" >"$d/$n.cmd"
    rc=0
    timeout 60 "$@" >"$d/$n.out" 2>"$d/$n.err" || rc=$?
    echo "rc=$rc" >>"$d/$n.cmd"
    return 0
  }
  tool ibstat ibstat
  tool ibnetdiscover ibnetdiscover
  tool ibnetdiscover-p ibnetdiscover -p
  tool ibnetdiscover-l ibnetdiscover -l
  tool iblinkinfo iblinkinfo
  tool iblinkinfo-line iblinkinfo --line
  for t in ibnetdiscover iblinkinfo perfquery; do tool "$t-version" "$t" -V; done

  # LIDs come from the SM and change between runs: take them from the topology.
  ca_lid=$(awk '$1 == "CA" && /node04/ {print $2}' "$d/ibnetdiscover-p.out")
  sw_lid=$(awk '$1 == "SW" && /leaf1/ {print $2; exit}' "$d/ibnetdiscover-p.out")
  tool perfquery-ca perfquery "$ca_lid" 1
  tool perfquery-ca-x perfquery -x "$ca_lid" 1
  tool perfquery-sw perfquery "$sw_lid" 1
  tool perfquery-sw-list perfquery "$sw_lid" 1-3

  # OpenSM does not exit on SIGTERM under the shim (it logs "exiting" and
  # lingers), so a `wait` here hangs. SIGKILL both; nothing is left to flush.
  kill -9 "$osm_pid" "$sim_pid" "$osm_dog" "$sim_dog" 2>/dev/null || true
  sleep 1
  unset LD_PRELOAD

  # Smoke assertions on the raw output, so the job proves the simulation is
  # real before any plugin parser is involved. Counts are structural (node
  # and link counts), not LIDs or line order, which vary between runs.
  check "$sc: every tool exited 0" \
    sh -c "! grep -L '^rc=0' $d/*.cmd | grep -q ."
  check "$sc: 8 nodes in --list" test "$(wc -l <"$d/ibnetdiscover-l.out")" -eq 8
  check "$sc: 4 switchguid lines" test "$(grep -c '^switchguid=' "$d/ibnetdiscover.out")" -eq 4
  check "$sc: 4 caguid lines" test "$(grep -c '^caguid=' "$d/ibnetdiscover.out")" -eq 4
  check "$sc: discovery initiated from node01" \
    grep -q '^# Initiated from node 0002c90300bb0000' "$d/ibnetdiscover.out"
  check "$sc: iblinkinfo --line has 38 port rows" test "$(wc -l <"$d/iblinkinfo-line.out")" -eq 38
  check "$sc: perfquery returned PortXmitData" grep -q '^PortXmitData:' "$d/perfquery-ca.out"
  check "$sc: perfquery -x returned 64-bit PortXmitData" grep -q '^PortXmitData:' "$d/perfquery-ca-x.out"
  check "$sc: perfquery port list returned 3 ports" \
    test "$(grep -c '^# Port counters' "$d/perfquery-sw-list.out")" -eq 3
}

run_scenario healthy
run_scenario degraded

# Scenario-specific: the 4X to 1X link on node03 must be visible, and only there.
check "healthy: no 1X link" test "$(grep -c ' 1X ' "$OUT/healthy/iblinkinfo-line.out")" -eq 0
check "degraded: node03 link is 1X in both directions" \
  test "$(grep -c ' 1X ' "$OUT/degraded/iblinkinfo-line.out")" -eq 2

# A switch's ports all print one PortGUID in iblinkinfo --line.
check "switch ports share one PortGUID" \
  test "$(grep -E '^0x[0-9a-f]+ " +leaf1 MQM8700"' "$OUT/healthy/iblinkinfo-line.out" | awk '{print $1}' | sort -u | wc -l)" -eq 1

exit "$fail"
