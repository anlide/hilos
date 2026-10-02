<?php

declare(strict_types=1);

/**
 * The order in which the steps of a run are offered a free lane.
 *
 * Steps sharing a group run one after another whatever their order, since they share
 * a stand, so the order inside a group never moves the end of the run. It decides
 * one thing: when each verdict arrives. Inside a group the shortest step goes first,
 * so a demo's `check` reports minutes into the run instead of after the e2e it used
 * to wait behind (2026-10-02, the owner's decision: in run 0754 chat-check started
 * at 17m47s, after chat-e2e, and was the last step of the run).
 *
 * Among themselves the groups go longest first, each ranked by its longest member:
 * the place that member held when every step was ranked by its own duration alone,
 * so across the groups the run keeps the shape it had. A step without a group is
 * ranked by its own duration. It stays a narrow hint: nothing here looks at the work
 * waiting behind a step, so `fe-install` costs a second, holds up the entire frontend
 * chain, and still sorts near the end. That was measured and left alone deliberately
 * — the head of `scripts/test-suite.php` says what a full run is actually bound by,
 * and why neither the order nor the lanes are the lever (HIL-854, HIL-1227).
 *
 * This file only declares functions and executes nothing, so that the runner and
 * `framework/tests/Unit/LaunchOrderTest.php` can both require it.
 */

/**
 * Plan ids in launch order: the groups longest first, each ranked by its longest
 * member, and inside a group the shortest step first.
 *
 * @param array<string, array{group: string|null, seconds: int}> $manifest Steps by id.
 * @param array<int, string> $plan Step ids to run.
 * @return array<int, string>
 */
function launchOrder(array $manifest, array $plan): array
{
    $groupRank = [];
    foreach ($plan as $id) {
        $group = $manifest[$id]['group'];
        if ($group !== null) {
            $groupRank[$group] = max($groupRank[$group] ?? 0, $manifest[$id]['seconds']);
        }
    }
    $rank = static fn(string $id): int => $manifest[$id]['group'] === null
        ? $manifest[$id]['seconds']
        : $groupRank[$manifest[$id]['group']];
    usort(
        $plan,
        static fn(string $a, string $b): int => [$rank($b), $manifest[$a]['seconds']] <=> [$rank($a), $manifest[$b]['seconds']],
    );

    return $plan;
}
