<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_fabric, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_fabric
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

use Somethingwithproof\Fabric\LinkCondition as C;
use Somethingwithproof\Fabric\LinkSpeed as S;
use Somethingwithproof\Fabric\LinkState;
use Somethingwithproof\Fabric\LinkStateEvaluator;
use Somethingwithproof\Fabric\LinkWidth as W;
use Somethingwithproof\Fabric\LogicalState as L;
use Somethingwithproof\Fabric\PhysState as P;

function fabric_link(?L $logical, ?W $aw, ?S $as, ?W $bw, ?S $bs, bool $peer = true, ?W $ew = null, ?S $es = null): LinkState {
	return new LinkState($logical, P::LinkUp, $aw, $as, $bw, $bs, $ew, $es, $peer);
}

// docs/research/link-downgrade.md, ADR 0009 for "below best, cause unknown".
it('classifies links by the recorded rule', function (LinkState $link, C $expected, ?int $degraded) {
	$condition = (new LinkStateEvaluator())->evaluate($link);

	expect($condition)->toBe($expected)
		->and($condition->degradedValue())->toBe($degraded);
})->with([
	'at best'                      => [fabric_link(L::Active, W::X4, S::Edr, W::X4, S::Edr), C::Up, 0],
	'down is down'                 => [fabric_link(L::Down, null, null, null, null, false), C::Down, 0],
	'down with stale values'       => [fabric_link(L::Down, W::X1, S::Sdr, W::X4, S::Edr), C::Down, 0],
	'initializing is unknown'      => [fabric_link(L::Initialize, W::X4, S::Edr, W::X4, S::Edr), C::Unknown, null],
	'armed is unknown'             => [fabric_link(L::Armed, W::X4, S::Edr, W::X4, S::Edr), C::Unknown, null],
	'no state is unknown'          => [fabric_link(null, W::X4, S::Edr, W::X4, S::Edr), C::Unknown, null],
	'active without peer'          => [fabric_link(L::Active, W::X4, S::Edr, null, null, false), C::Unknown, null],
	'unknown active width'         => [fabric_link(L::Active, null, S::Edr, W::X4, S::Edr), C::Unknown, null],
	'unknown best speed'           => [fabric_link(L::Active, W::X4, S::Edr, W::X4, null), C::Unknown, null],
	'narrow, no enabled values'    => [fabric_link(L::Active, W::X1, S::Edr, W::X4, S::Edr), C::BelowBest, null],
	'slow, no enabled values'      => [fabric_link(L::Active, W::X4, S::Fdr, W::X4, S::Edr), C::BelowBest, null],
	'qdr where fdr10 could run'    => [fabric_link(L::Active, W::X4, S::Qdr, W::X4, S::Fdr10), C::BelowBest, null],
	'2X below 4X by lanes'         => [fabric_link(L::Active, W::X2, S::Hdr, W::X4, S::Hdr), C::BelowBest, null],
	'limited by enabled width'     => [fabric_link(L::Active, W::X1, S::Edr, W::X4, S::Edr, true, W::X1, S::Edr), C::Limited, 0],
	'degraded despite enabled'     => [fabric_link(L::Active, W::X1, S::Edr, W::X4, S::Edr, true, W::X4, S::Edr), C::Degraded, 1],
	'speed limited, width degraded' => [fabric_link(L::Active, W::X1, S::Fdr, W::X4, S::Edr, true, W::X4, S::Fdr), C::Degraded, 1],
	'above best is not degraded'   => [fabric_link(L::Active, W::X4, S::Hdr, W::X4, S::Edr), C::Up, 0],
]);
