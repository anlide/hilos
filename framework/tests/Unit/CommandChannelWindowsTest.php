<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CommandChannelWindows;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the one property the command channel's three waits have to keep: each one
 * expires strictly inside the one outside it.
 *
 * The order used to be held by three numbers in three files agreeing by hand, and what broke
 * was never the arithmetic - it was that a number moved and nobody outside its file noticed.
 * A wait that outlives the window around it does not fail loudly; it answers with a mute
 * timeout instead of the reason the inner window exists to word, which reads as a flake for as
 * long as it takes somebody to chase it. So the guard is here, where an edit to any of the
 * three breaks a test on the spot rather than a run a week later.
 */
final class CommandChannelWindowsTest extends TestCase
{
    public function testTheAgentGivesUpBeforeTheSideThatAskedDoes(): void
    {
        $this->assertLessThan(
            CommandChannelWindows::CALLER_WAIT_SECONDS,
            CommandChannelWindows::AGENT_WAIT_SECONDS,
            'The agent holds the only window whose expiry carries a reason, so it has to expire first',
        );
    }

    public function testTheSideThatAskedGivesUpBeforeTheChannelDropsTheRequest(): void
    {
        $this->assertLessThan(
            CommandChannelWindows::CHANNEL_HELD_SECONDS,
            CommandChannelWindows::CALLER_WAIT_SECONDS,
            "The channel's own timeout is wordless, so nothing should ever be waiting when it fires",
        );
    }

    public function testTheRefusalIsGivenRoomToTravel(): void
    {
        $this->assertGreaterThan(
            0.0,
            CommandChannelWindows::REFUSAL_DELIVERY_MARGIN_SECONDS,
            'A margin of zero would have the refusal leave exactly as the caller stops listening',
        );
    }

    public function testTheAgentIsLeftSomethingToWaitWith(): void
    {
        $this->assertGreaterThan(
            0.0,
            CommandChannelWindows::AGENT_WAIT_SECONDS,
            'A margin grown past the caller window would leave the agent refusing before it looked',
        );
    }
}
