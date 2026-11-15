#!/bin/sh
#
# SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
# SPDX-License-Identifier: GPL-2.0-or-later
#
# This file is part of plugin_fabric, a plugin for Cacti.
# https://github.com/somethingwithproof/plugin_fabric
#
# This program is free software; you can redistribute it and/or modify it
# under the terms of the GNU General Public License as published by the Free
# Software Foundation; either version 2 of the License, or (at your option)
# any later version.
#
# This program is distributed in the hope that it will be useful, but WITHOUT
# ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
# FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
# more details.
#
# Feeds synthetic counter series (not recorded from hardware) into an RRDtool
# COUNTER data source with and without a "max" and prints the stored rate at
# the sample after a reset. It answers one question for research issue #15:
# does a "max" turn a counter reset into an unknown value, or into a spike?
#
# Needs only rrdtool on PATH. Writes under a temporary directory and removes
# nothing outside it. Usage: sh tests/rrd-counter/run.sh

set -eu

command -v rrdtool >/dev/null 2>&1 || { echo "rrdtool not found on PATH" >&2; exit 2; }

work=$(mktemp -d "${TMPDIR:-/tmp}/fabric-rrd.XXXXXX")
# The directory is this script's own scratch space; use trash when it exists.
trap 'if command -v trash >/dev/null 2>&1; then trash "$work"; else rm -rf "$work"; fi' EXIT

STEP=60
# START is a multiple of STEP so updates land on step boundaries; unaligned
# timestamps make RRDtool spread one sample over several rows.
START=1700000040

# run_case NAME MAX "v0 v1 v2 ..."
# Creates one RRD with a COUNTER data source (heartbeat 120, min 0, max MAX),
# updates it with one value per step, and prints the rate stored for each step.
run_case() {
	name=$1
	max=$2
	series=$3
	rrd="$work/$name.rrd"

	rrdtool create "$rrd" --start $((START - STEP)) --step $STEP \
		"DS:v:COUNTER:120:0:$max" "RRA:AVERAGE:0.5:1:100" >/dev/null

	i=0
	for v in $series; do
		i=$((i + 1))
		rrdtool update "$rrd" "$((START - STEP + i * STEP)):$v"
	done

	printf '%-34s max=%-12s' "$name" "$max"
	rrdtool fetch "$rrd" AVERAGE --start "$START" --end $((START + i * STEP)) \
		| awk 'NR > 2 && $1 != "" { r = $2; if (r == "-nan" || r == "nan") r = "U"; printf " %s", r }'
	printf '\n'
}

# Rates are per second; a value of U is stored as unknown.
echo "step=${STEP}s, one sample per step; each row lists the stored rate per step"
echo

# 1. A 32-bit counter (PortXmitWait) reset from a high value to zero.
s1="1000000000 1000060000 1000120000 3000000000 0 60000 120000 180000"
run_case wait32_reset_from_3e9 U "$s1"
run_case wait32_reset_from_3e9 1000000 "$s1"

# 2. An 8-bit counter (LinkDownedCounter) that caps at 255, then is reset.
s2="250 252 255 255 255 0 1 2"
run_case linkdowned8_cap_then_reset U "$s2"
run_case linkdowned8_cap_then_reset 10 "$s2"

# 3. A 64-bit data counter reset while the value is above 2^32.
s3="9000000000000 9000001000000 9000002000000 9000003000000 0 1000000 2000000 3000000"
run_case data64_reset_above_2e32 U "$s3"
run_case data64_reset_above_2e32 30000000000 "$s3"

# 4. A 64-bit data counter reset while the value is below 2^32 (an idle port
#    or a port that was reset shortly before). The wrap guess is 32 bits.
s4="3000000000 3000001000 3000002000 3000003000 0 1000 2000 3000"
run_case data64_reset_below_2e32 U "$s4"
run_case data64_reset_below_2e32 30000000000 "$s4"

# 5. A capped counter: the value stops changing, so the stored rate is 0.
s5="4294967290 4294967293 4294967295 4294967295 4294967295 4294967295"
run_case wait32_saturated U "$s5"
run_case wait32_saturated 1000000 "$s5"
