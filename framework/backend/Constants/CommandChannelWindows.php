<?php

declare(strict_types=1);

namespace Hilos\Constants;

use Hilos\Core\Agent\ProtectedModeOperatorTrait;
use Hilos\Core\Agent\ProtectedModeTestDriverTrait;
use Hilos\Core\CLI\Commands\CommandChannelClientTrait;
use Hilos\Socket\Client\CommandClient;

/**
 * How long each link of the command channel waits before it gives up on the one inside it.
 *
 * A command that drives state travels through three waits nested one in another, and the
 * order between them is the whole point: the innermost has to run out FIRST, because it is
 * the only one of the three that knows what went wrong. The agent watching the row can say
 * "protected mode did not reach 'verifying' (phase: active)"; every window outside it can
 * only say that nobody answered.
 *
 * **There are two chains, not one, and they share their innermost and their outermost link.**
 *
 *     production   agent ({@see ProtectedModeOperatorTrait})    -> CLI process     -> channel
 *     test stand   agent ({@see ProtectedModeTestDriverTrait})  -> e2e client      -> channel
 *
 * The middle link is a different program on each - a PHP CLI process on one
 * ({@see CommandChannelClientTrait}), a Node socket client on the other - but its ROLE is the
 * same on both: how long the side that asked is willing to wait. So it is one number with two
 * executors rather than two numbers that happen to agree, which is what the two of them had
 * stopped doing (5.0 in the CLI against 10.0 in the e2e client) by the time this class was
 * written.
 *
 * **The innermost window is COMPUTED, not chosen.** {@see self::AGENT_WAIT_SECONDS} is the
 * caller's patience less the margin a refusal needs to travel, so "inner is smaller than
 * middle" is a property of the formula and not of the next reader's attention. Raising the
 * agent's window past the caller's is not a mistake one can make here; it can only be made by
 * raising the caller's, which is exactly the decision that should be deliberate.
 *
 * **These windows do NOT scale with the load of the run, and that is deliberate.** Playwright's
 * own ceilings do: framework/frontend/scripts/timeout-scale.mjs derives a factor of 1.0 to 4.0
 * from the number of lanes and the free memory, so a test on a loaded box is given more
 * patience. The waits here stay put, because the production chain must not take its sizing from
 * a test environment variable, and a test-only multiplier on one link of a shared chain is a
 * flag in the framework. The cost is accepted knowingly: it is why the numbers are this large
 * rather than tuned to an idle box. The other route - counting worker turns instead of wall
 * clock seconds - was weighed and declined with its facts recorded in
 * hilos-ops/proposals/P-306-agent-wait-in-loop-turns.md, to be reopened if the flake returns
 * with the windows at this size.
 *
 * **The middle window has a copy in TypeScript, and it cannot be otherwise.** The e2e client
 * is Node reaching the command port over TCP - Playwright has no PHP to run, so nothing there
 * can read a PHP constant. The copy lives in each demo's `tests/e2e/helpers/protectedMode.ts`
 * as `REPLY_TIMEOUT_MS` and names this class in its comment; a change to
 * {@see self::CALLER_WAIT_SECONDS} has to be carried there by hand.
 */
final class CommandChannelWindows
{
    /**
     * @var float The outermost window: how long the daemon holds an unanswered request open
     *
     * Read by {@see CommandClient::onTick()}. Once it runs out the connection is failed with a
     * wordless "Command timed out", which is why nothing is meant to reach it.
     */
    public const float CHANNEL_HELD_SECONDS = 30.0;

    /**
     * @var float The middle window: how long the side that asked waits for a reply
     *
     * One number, two executors - the PHP CLI process and the Node e2e client. See the class
     * note for why they are not allowed to be two numbers.
     */
    public const float CALLER_WAIT_SECONDS = 15.0;

    /**
     * @var float Head start the agent's refusal is given to reach the caller before it gives up
     *
     * A refusal is not free to deliver: it goes agent -> worker -> daemon -> socket, and it is
     * worth nothing if the caller has already stopped listening. This is the distance between
     * the innermost window and the middle one, stated as what it is for.
     */
    public const float REFUSAL_DELIVERY_MARGIN_SECONDS = 2.0;

    /**
     * @var float The innermost window: how long the agent watches the row before refusing
     *
     * Computed rather than assigned, so that it cannot be raised past the window it must
     * expire inside. The only window of the three whose expiry carries a reason.
     */
    public const float AGENT_WAIT_SECONDS = self::CALLER_WAIT_SECONDS - self::REFUSAL_DELIVERY_MARGIN_SECONDS;
}
