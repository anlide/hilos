<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle;

use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Constants\CliCommands;

/**
 * ThrottleCommandConstants - the wire vocabulary of the throttle command channel (HIL-420).
 *
 * The CLI side reads the keys the agent side writes, so both name them from here and can
 * never drift apart. Only the test-only reset uses it today
 * ({@see CliCommands::THROTTLE_TEST_RESET}, handled by {@see AuthThrottleAgent}), and it
 * carries no request fields: a reset takes no arguments, it empties everything.
 *
 * The reply counts the two halves of the state separately because they are two stores - the
 * counters in the agent's runtime collection, the blocks in the database - and a test
 * asserting it starts from a clean slate is entitled to see what was holding it.
 */
final class ThrottleCommandConstants
{
    /** @var string Reply key: number of runtime counters the reset dropped */
    public const string FIELD_COUNTERS_CLEARED = 'countersCleared';

    /** @var string Reply key: number of stored blocks the reset deleted */
    public const string FIELD_BLOCKS_CLEARED = 'blocksCleared';
}
