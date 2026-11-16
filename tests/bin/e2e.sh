#!/bin/sh
# SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
# SPDX-License-Identifier: GPL-2.0-or-later
#
# End to end with Cacti's poller, Thold, and Maint (tests/bin/e2e.php).
# Run from the Cacti directory, with Thold and Maint unpacked, unchanged,
# in plugins/thold and plugins/maint, and the Fabric plugin enabled.
# Disposable databases only.
#
#   sh plugins/fabric/tests/bin/e2e.sh <root-owned tool dir> <state dir>
#
# The plugin runs tools only from a root-owned directory closed to group
# and others, so the fake tools are installed there with install(1), as
# root or through sudo when not root.
set -u
TOOLS=$1
STATE=$2
export FABRIC_TEST_DATABASE=disposable FABRIC_E2E_TOOLS="$TOOLS"
fail=0
as_root=""
[ "$(id -u)" -eq 0 ] || as_root="sudo"

php cli/plugin_manage.php --plugin=thold --plugin=maint --install --enable || exit 1

phase() {
	php plugins/fabric/tests/bin/e2e.php "$1" "$STATE" || fail=1
}

poll() {
	# Rates need at least a second between samples.
	sleep 2
	php poller.php --poller=1 --force > /dev/null 2>&1 || { echo "FAIL the poller exited non-zero"; fail=1; }
	# Thold checks thresholds in poller_thold.php, which its poller_bottom
	# hook starts in the background. Run it in the foreground as well; a
	# run refused because the background one holds Thold's process lock
	# exits at once, so wait, bounded, until no Thold worker is registered.
	php plugins/thold/poller_thold.php --force > /dev/null 2>&1 || { echo "FAIL the Thold worker exited non-zero"; fail=1; }
	phase thold_idle
}

phase tools
# A tool that is not installed could leave an older executable in place,
# which the sweep would then run: stop before setup.
$as_root install -d -o root -g root -m 0755 "$TOOLS" || { echo "FAIL could not create $TOOLS"; exit 1; }
for tool in ibnetdiscover iblinkinfo perfquery; do
	$as_root install -o root -g root -m 0755 "$STATE/tools/$tool" "$TOOLS/$tool" || { echo "FAIL could not install the fake $tool"; exit 1; }
done

phase setup
poll
phase check_setup

for step in degrade restore burst steady reset maint stale; do
	phase "$step"
	poll
	phase "check_$step"
done

phase graph

# The discontinuity is logged as a warning on purpose; anything else at
# warning level or above is a failure. Cacti 1.2.31's poller leaves some of
# these data sources' rows in poller_output each cycle, warns about it, and
# tries to email the primary admin; the 1.2.x branch rewrote that deletion
# and does not. On 1.2.31 only, those warnings are printed as notes, not
# failures, and the rows are listed. The Thold and Maint checks above still
# run in full.
known='counters went down'
if [ "$(cat include/cacti_version)" = '1.2.31' ]; then
	known="$known|Poller Output Table not Empty|Data Sources not returning all data|is impacted by lack of complete information|Primary Admin account notifications disabled"
	if grep -E "Poller Output Table not Empty|Data Sources not returning all data" log/cacti.log > /dev/null; then
		echo "NOTE Cacti 1.2.31 left Fabric rows in poller_output:"
		phase leftover
	fi
fi
# Cacti 1.2.31 checks RRD file ownership right after an asynchronous create
# when the poller runs as root, as the local replay does; CI does not.
if [ "$(id -u)" = 0 ] && [ "$(cat include/cacti_version)" = '1.2.31' ]; then
	known="$known|does not exist for ownership assignment"
fi
if grep -E "FATAL:|ERROR:|WARNING:" log/cacti.log | grep -Ev "$known"; then
	echo "FAIL the Cacti log has errors"
	fail=1
	phase leftover
fi

exit $fail
