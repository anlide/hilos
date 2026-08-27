<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AgentIdleTracker;
use PHPUnit\Framework\TestCase;

/**
 * What holds an instance agent alive, and what lets it go.
 *
 * The tracker is the whole of the idle verdict: the worker reads a window from the registry and
 * asks these four questions of it. Each test below is one of the ways an agent is needed, and the
 * subscriber cases are the load-bearing ones — an open tab is the only claim on the agent that no
 * frame renews, so an agent stopped under one would silently drop the push guarantee the tab is
 * waiting on.
 */
final class AgentIdleTrackerTest extends TestCase
{
    private const string AGENT_ID = 'unit_idle_agent:7';

    private const int WINDOW_SEC = 240;

    public function testAnAgentSilentPastItsWindowWithNoSubscriberIsIdle(): void
    {
        $tracker = new AgentIdleTracker();
        $tracker->noteStarted(self::AGENT_ID, 1000.0);

        $this->assertTrue($tracker->isIdle(self::AGENT_ID, self::WINDOW_SEC, 1241.0));
    }

    public function testAnAgentInsideItsWindowIsNotIdle(): void
    {
        $tracker = new AgentIdleTracker();
        $tracker->noteStarted(self::AGENT_ID, 1000.0);

        $this->assertFalse($tracker->isIdle(self::AGENT_ID, self::WINDOW_SEC, 1239.0));
    }

    public function testAnAddressedFrameRestartsTheWindow(): void
    {
        $tracker = new AgentIdleTracker();
        $tracker->noteStarted(self::AGENT_ID, 1000.0);
        $tracker->noteAddressed(self::AGENT_ID, 1200.0);

        $this->assertFalse($tracker->isIdle(self::AGENT_ID, self::WINDOW_SEC, 1241.0));
        $this->assertTrue($tracker->isIdle(self::AGENT_ID, self::WINDOW_SEC, 1441.0));
    }

    public function testALiveSubscriberKeepsTheAgentOutOfIdleForever(): void
    {
        $tracker = new AgentIdleTracker();
        $tracker->noteStarted(self::AGENT_ID, 1000.0);
        $tracker->noteSubscriber(self::AGENT_ID, 'accept-1', 1010.0);

        $this->assertFalse($tracker->isIdle(self::AGENT_ID, self::WINDOW_SEC, 99000.0));
    }

    public function testDroppingTheLastSubscriberRestartsTheWindowRatherThanExpiringIt(): void
    {
        $tracker = new AgentIdleTracker();
        $tracker->noteStarted(self::AGENT_ID, 1000.0);
        $tracker->noteSubscriber(self::AGENT_ID, 'accept-1', 1010.0);
        $tracker->dropSubscriber(self::AGENT_ID, 'accept-1', 5000.0);

        // The hour-old tab has just closed: counting from the last frame would stop the agent in
        // this same second, which is when it is most likely to still be finishing something.
        $this->assertFalse($tracker->isIdle(self::AGENT_ID, self::WINDOW_SEC, 5001.0));
        $this->assertTrue($tracker->isIdle(self::AGENT_ID, self::WINDOW_SEC, 5241.0));
    }

    public function testOneRemainingSubscriberStillHoldsTheAgent(): void
    {
        $tracker = new AgentIdleTracker();
        $tracker->noteStarted(self::AGENT_ID, 1000.0);
        $tracker->noteSubscriber(self::AGENT_ID, 'accept-1', 1010.0);
        $tracker->noteSubscriber(self::AGENT_ID, 'accept-2', 1020.0);
        $tracker->dropSubscriber(self::AGENT_ID, 'accept-1', 1030.0);

        $this->assertFalse($tracker->isIdle(self::AGENT_ID, self::WINDOW_SEC, 9000.0));
    }

    /**
     * The one claim that would never be released. A subscribe payload may carry a blank accept
     * key — it is read with a require-string, which refuses a non-string and not an empty one —
     * and the two drop paths both ignore a blank key, so a subscriber recorded under it could
     * never be removed by anything and the agent would live for the life of the worker.
     */
    public function testASubscriberWithNoConnectionToNameIsNotRecorded(): void
    {
        $tracker = new AgentIdleTracker();
        $tracker->noteStarted(self::AGENT_ID, 1000.0);
        $tracker->noteSubscriber(self::AGENT_ID, '', 1010.0);

        // The frame still counted as addressing the agent, so the window runs from it.
        $this->assertFalse($tracker->isIdle(self::AGENT_ID, self::WINDOW_SEC, 1249.0));
        $this->assertTrue($tracker->isIdle(self::AGENT_ID, self::WINDOW_SEC, 1251.0));
    }

    public function testAnAgentTheTrackerNeverHeardOfIsNotIdle(): void
    {
        $tracker = new AgentIdleTracker();

        $this->assertFalse($tracker->isIdle(self::AGENT_ID, self::WINDOW_SEC, 99000.0));
    }

    public function testForgettingAnAgentDropsItsSubscribersToo(): void
    {
        $tracker = new AgentIdleTracker();
        $tracker->noteStarted(self::AGENT_ID, 1000.0);
        $tracker->noteSubscriber(self::AGENT_ID, 'accept-1', 1010.0);
        $tracker->forget(self::AGENT_ID);
        $tracker->noteStarted(self::AGENT_ID, 2000.0);

        // The restarted agent inherits nothing: the subscriber of its previous life would
        // otherwise hold the new one alive against a socket that is long gone.
        $this->assertTrue($tracker->isIdle(self::AGENT_ID, self::WINDOW_SEC, 2241.0));
    }

    public function testTrackedAgentsDoNotShareAWindow(): void
    {
        $tracker = new AgentIdleTracker();
        $tracker->noteStarted(self::AGENT_ID, 1000.0);
        $tracker->noteStarted('unit_idle_agent:8', 1000.0);
        $tracker->noteAddressed('unit_idle_agent:8', 1200.0);

        $this->assertTrue($tracker->isIdle(self::AGENT_ID, self::WINDOW_SEC, 1241.0));
        $this->assertFalse($tracker->isIdle('unit_idle_agent:8', self::WINDOW_SEC, 1241.0));
    }
}
