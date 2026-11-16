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
# Every stage has its own bound and prints its elapsed time, so a stall names
# the stage and shows the simulator and OpenSM logs: package install
# (APT_LIMIT each step), the simulator start, OpenSM reaching
# MASTER (OSM_LIMIT), the local port going Active (PORT_LIMIT), and each tool
# (TOOL_LIMIT; a tool that times out stops the run). The worst case stays
# under the job's docker wait. The simulator and OpenSM also run under a
# watchdog: they are stopped with SIGKILL, which timeout cannot pass on to its
# child. tests/bin/check-ibsim-fixtures.php then reads the output through the
# plugin's own parsers.
set -eu
export DEBIAN_FRONTEND=noninteractive
REPO=$1
OUT=$2
TOPO_DIR=$REPO/tests/ibsim/topo

# Exact archive versions, so a fixture change is a deliberate bump. Ubuntu
# removes superseded security builds from the archive; bump these together
# with the image digest when `apt-get install` reports a missing version.
APT_LIMIT=150
OSM_LIMIT=60
PORT_LIMIT=30
TOOL_LIMIT=20
DOG_LIMIT=$((3 + OSM_LIMIT + PORT_LIMIT + 7 + 13 * (TOOL_LIMIT + 2) + 30))

T0=$(date +%s)
log() { echo "[$(( $(date +%s) - T0 ))s] $*"; }

# die <message> [log file...]: stop with the stage named and the logs shown.
die() {
  msg=$1; shift
  echo "FAIL $msg after $(( $(date +%s) - T0 ))s" >&2
  for f in "$@"; do
    [ -f "$f" ] || continue
    echo "--- last lines of $f" >&2
    tail -n 25 "$f" >&2
  done
  exit 1
}

# wait_for <seconds> <command...>: poll once a second until the command
# succeeds; non-zero when the limit passes first.
wait_for() {
  end=$(( $(date +%s) + $1 )); shift
  until "$@"; do
    [ "$(date +%s)" -lt "$end" ] || return 1
    sleep 1
  done
}

log "apt-get update"
timeout -k 5 "$APT_LIMIT" apt-get update -qq >/tmp/apt.log 2>&1 ||
  die "apt-get update did not finish within ${APT_LIMIT}s" /tmp/apt.log
log "apt-get install"
timeout -k 5 "$APT_LIMIT" apt-get install -y -qq --no-install-recommends \
  ibsim-utils=0.10-2build1 \
  infiniband-diags=50.0-2ubuntu0.2 \
  libibumad3=50.0-2ubuntu0.2 \
  opensm=3.3.23-2 >/tmp/apt.log 2>&1 ||
  die "apt-get install of the pinned packages failed or took over ${APT_LIMIT}s" /tmp/apt.log

SO=$(find /usr/lib -name libumad2sim.so | head -n 1)
[ -n "$SO" ] || die "libumad2sim.so not found"
log "packages installed; shim $SO"

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
  # Use ibsim's loopback UDP transport. On hosted runners the first write
  # to its abstract Unix datagram socket can stall before OpenSM attaches.
  export IBSIM_SERVER_NAME=127.0.0.1
  log "$sc: starting ibsim"
  ibsim -r -s "$TOPO_DIR/$sc.net" --no-console >"$d/ibsim.log" 2>&1 &
  sim_pid=$!
  (sleep "$DOG_LIMIT"; kill -9 "$sim_pid" 2>/dev/null) &
  sim_dog=$!
  # ibsim prints no ready line; give it a moment, then require it to still
  # be running. OpenSM reaching MASTER below proves it answers.
  sleep 3
  kill -0 "$sim_pid" 2>/dev/null || die "$sc: ibsim exited at start" "$d/ibsim.log"

  log "$sc: starting OpenSM under the shim"
  OSM_TMP_DIR=$d/osm OSM_CACHE_DIR=$d/osm LD_PRELOAD=$SO \
    opensm -V -f "$d/osm.log" >"$d/opensm.out" 2>&1 &
  osm_pid=$!
  (sleep "$DOG_LIMIT"; kill -9 "$osm_pid" 2>/dev/null) &
  osm_dog=$!
  stop() { kill -9 "$osm_pid" "$sim_pid" "$osm_dog" "$sim_dog" 2>/dev/null || true; }

  osm_master() {
    kill -0 "$osm_pid" 2>/dev/null || return 2
    grep -q 'Entering MASTER state' "$d/opensm.out" "$d/osm.log" 2>/dev/null
  }
  wait_for "$OSM_LIMIT" osm_master || {
    kill -0 "$osm_pid" 2>/dev/null && why="did not reach MASTER within ${OSM_LIMIT}s" || why="exited before reaching MASTER"
    stop
    die "$sc: OpenSM $why" "$d/opensm.out" "$d/osm.log" "$d/ibsim.log"
  }
  log "$sc: OpenSM is MASTER"

  export LD_PRELOAD=$SO
  port_active() { timeout -k 2 5 ibstat 2>/dev/null | grep -q 'State: Active'; }
  wait_for "$PORT_LIMIT" port_active || {
    stop
    die "$sc: the local port did not go Active within ${PORT_LIMIT}s of MASTER" "$d/osm.log" "$d/ibsim.log"
  }
  log "$sc: local port Active"

  tool() { # tool <name> <command...>  (stdout, stderr, command and rc kept)
    n=$1; shift
    echo "$*" >"$d/$n.cmd"
    rc=0
    timeout -k 2 "$TOOL_LIMIT" "$@" >"$d/$n.out" 2>"$d/$n.err" || rc=$?
    echo "rc=$rc" >>"$d/$n.cmd"
    if [ "$rc" -eq 124 ] || [ "$rc" -eq 137 ]; then
      stop
      die "$sc: '$*' did not finish within ${TOOL_LIMIT}s" "$d/$n.err" "$d/osm.log" "$d/ibsim.log"
    fi
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
  stop
  sleep 1
  unset LD_PRELOAD
  log "$sc: tools done"

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
