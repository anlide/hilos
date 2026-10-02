<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/launch-order.php';

/**
 * The order a run offers its steps a lane: the groups longest first, each ranked by
 * its longest member, and inside a group the shortest step first — so a demo's
 * `check` no longer waits for its e2e to report.
 *
 * Required by path, the way `scripts/run-test-suite.php` loads it; the runner itself
 * is deliberately not loaded here, because requiring it would run a whole test suite.
 */
final class LaunchOrderTest extends TestCase
{
    /** Inside a group the e2e goes last, so the check and the unit run report before it. */
    public function testRunsTheShortStepsOfAGroupBeforeItsLongOne(): void
    {
        $manifest = [
            'chat-check' => ['group' => 'chat', 'seconds' => 11],
            'chat-php' => ['group' => 'chat', 'seconds' => 106],
            'chat-e2e' => ['group' => 'chat', 'seconds' => 849],
        ];

        $this->assertSame(
            ['chat-check', 'chat-php', 'chat-e2e'],
            launchOrder($manifest, ['chat-e2e', 'chat-php', 'chat-check']),
        );
    }

    /**
     * The groups keep the places their e2e held when every step was ranked alone: chat
     * ahead of framework, tasks behind it, a step without a group by its own duration.
     */
    public function testRanksAGroupByItsLongestMember(): void
    {
        $manifest = [
            'framework' => ['group' => null, 'seconds' => 425],
            'fe-build' => ['group' => null, 'seconds' => 35],
            'chat-check' => ['group' => 'chat', 'seconds' => 11],
            'chat-e2e' => ['group' => 'chat', 'seconds' => 849],
            'tasks-check' => ['group' => 'tasks', 'seconds' => 9],
            'tasks-e2e' => ['group' => 'tasks', 'seconds' => 108],
        ];

        $this->assertSame(
            ['chat-check', 'chat-e2e', 'framework', 'tasks-check', 'tasks-e2e', 'fe-build'],
            launchOrder($manifest, array_keys($manifest)),
        );
    }

    /** A member outside the plan does not lift its group: a check run without its e2e ranks by its own length. */
    public function testRanksOnlyTheMembersThePlanCarries(): void
    {
        $manifest = [
            'framework' => ['group' => null, 'seconds' => 425],
            'chat-check' => ['group' => 'chat', 'seconds' => 11],
            'chat-e2e' => ['group' => 'chat', 'seconds' => 849],
        ];

        $this->assertSame(['framework', 'chat-check'], launchOrder($manifest, ['chat-check', 'framework']));
    }
}
