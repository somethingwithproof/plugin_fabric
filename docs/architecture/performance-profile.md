<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
SPDX-License-Identifier: GPL-2.0-or-later
-->

# Performance Profile and Algorithmic Analysis

## Scope and Measurement Environment

All timings below were measured with PHP 8.4.25 (`Darwin arm64`, `memory_limit=1G`) using `hrtime(true)` and `memory_get_peak_usage(true)` across 3 iterations (median reported; 1 iteration for the unoptimized churn baseline). Benchmarks exercise synthetic InfiniBand and RoCEv2 topologies at 10,000 to 50,000 ports.

## Algorithmic Audit Findings and Fixes

| Component | Before | After | Root Cause and Fix |
| --- | --- | --- | --- |
| `RailCongestionAnalyzer::evictOldest()` | $O(N \cdot K)$ | $O(N)$ amortized | Capacity eviction scanned all $K = 4096$ flap entries linearly on every insert past capacity. Moving monotonically updated keys to the array tail makes `array_key_first()` an $O(1)$ eviction; out-of-order timestamps trigger a single stable `uasort` on the next eviction to restore order. |
| `CounterDelta::between()` + `PerfqueryParser::width()` + `Decimal` | $O(P \cdot C)$ with heavy allocs | $O(P \cdot C)$ constant-factor reduced | `PerfqueryParser::width()` rebuilt `EXTENDED + EXTENDED_CAST + EXTENDED_ERRORS` on every counter (22x per port), `Decimal::valid()` invoked PCRE twice per counter, and `Decimal::difference()` allocated two split arrays per counter. Replaced with cached `PerfqueryParser::caps()`, `ctype_digit` validation, and a 64-bit integer fast path for values up to 15 digits. |
| `PerfqueryParser::parse()` / `block()` | $O(L)$ with 4x layout rebuilds | $O(L)$ single-dispatch | Each port block rebuilt 4 layout unions and called `array_keys()` 4 times without early exit, and ran PCRE on every line. Replaced with field-count dispatch against cached layout key lists, `explode("\n")`, and `ctype_*` field validation. |
| `IbnetdiscoverParser::checkLinks()` | $O(P \cdot D)$ | $O(P + D)$ | Duplicate GUID filtering ran `in_array()` linearly per port end. Replaced with an `array_flip()` hash lookup (`$dupSet`), plus `ctype_xdigit` parsing in `Guid::tryFromString()` and `explode("\n")` line splitting. |
| `CounterSweep::group()` | $O(P^2)$ batch loop | $O(P \log P)$ | Shrinking `$pending` with `array_values(array_diff($pending, $tried))` rescanned remaining ports each batch, and `in_array()` checked each returned port linearly. Replaced with an integer offset cursor (since each batch is a prefix of `$pending`), `array_flip($batch)` membership checks, and pre-extracted `attempted_at` timestamps before sorting. |
| `LinkStateRepository::import()`, `InventoryRepository::import()`, `UfmSweep::sweepCounters()` | $N + 1$ SQL queries | $O(1)$ SQL queries | Per-node `NodeRepository::ports($nodeId)` and `SampleRepository::keyed($nodeId)` calls issued one SQL query per node in a fabric. Added `NodeRepository::portsForFabric()` and `SampleRepository::keyedForFabric()` batch JOIN queries; replaced `in_array()` duplicate checks in `InventoryRepository::import()` with a hash set. |
| `UfmParser::parsePorts()` | $O(P)$ with redundant parsing | $O(P)$ streamlined | Eliminated duplicate `self::str()` calls per port entry and regex overhead in `Guid::tryFromString()`. |

## Measured Benchmark Results (Before vs. After)

| Workload | Before Median (ms) | After Median (ms) | Speedup | Peak RSS Before (MiB) | Peak RSS After (MiB) |
| --- | ---: | ---: | ---: | ---: | ---: |
| `RailCongestionAnalyzer` (20,000 ports, flap churn, cap 4,096) | 1,485.10 | 40.97 | 36.2x | 132.00 | 140.00 |
| `CounterDelta::between` (50,000 extended ports, 1.1M counters) | 978.08 | 600.59 | 1.63x | 94.00 | 100.00 |
| `UfmParser::parsePorts` (20,000 ports) | 120.31 | 100.01 | 1.20x | 118.00 | 126.00 |
| `IbnetdiscoverParser::parse` (10,000 ports, 64 duplicate switches) | 56.32 | 49.03 | 1.15x | 22.00 | 22.00 |
| `IbnetdiscoverParser::parse` (10,000 ports, 0 duplicates) | 55.89 | 49.86 | 1.12x | 22.00 | 22.00 |
| `PerfqueryParser::parse` (10,000 extended ports, 260k lines) | 169.90 | 159.49 | 1.07x | 102.02 | 108.02 |
| `RailCongestionAnalyzer` (20,000 ports, steady state) | 20.42 | 22.15 | 0.92x | 132.00 | 140.00 |

Note: Steady-state `RailCongestionAnalyzer` adds per-port RoCEv2 PFC pause duration and PFC storm evaluation (+1.73 ms across 20,000 ports, ~0.09 us/port) while remaining bounded at 22 ms.

## Complexity of New Telemetry Modules

- `OpticalDomParser::parseMlxlinkJson()` and `parseUfmCableInfo()`: $O(P \cdot L)$ where $P$ is transceiver count and $L$ is lane count (typically 4 or 8), with fixed-size alert sets and bounded 64-byte ASCII display strings.
- `FecTelemetry::fromCountersDelta()` and `parseTelemetry()`: $O(1)$ per port using `Decimal` arithmetic for 64-bit unsigned codeword counters.
- `RoceCongestionSample::fromCounterDelta()`: $O(1)$ per port (at most 8 IEEE 802.1Qbb PFC priority classes) and integrates directly into `RailCongestionAnalyzer::analyze()` without additional passes.
