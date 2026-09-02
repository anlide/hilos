<?php

declare(strict_types=1);

namespace Hilos\Socket\Command;

use Hilos\Constants\CommandConstants;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Environment\NonProductionGate;

/**
 * The one place that answers whether a command-channel name is test-only.
 *
 * The answer is the name itself: a command is test-only when it is named with the
 * {@see CommandConstants::TEST_ONLY_PREFIX} prefix, and that is the whole declaration
 * (HIL-742). It used to be three declarations - a flag in the owning agent's
 * AGENT_COMMANDS entry, a list of the names the master answers itself, and the prefix -
 * with validation sewing them together; two commands still slipped through unprefixed,
 * because the roles nobody's stitch covered were exactly the ones nobody looked at.
 *
 * The prefix was kept and the other two dropped for the reason both holes were found the
 * hard way: a flag is invisible to whoever reads a command name in a log line, a compose
 * file, or a review diff, and a name is not.
 *
 * The class stays even though its body is now one call, because the question has to have
 * exactly one door: the socket gate ({@see NonProductionGate}) asks here, and nobody
 * spreads str_starts_with across the guards. Two other places answer a related question
 * about themselves rather than about a name - {@see TestOnlyCommand} refuses in the CLI
 * process, and the channel family's latch refuses a wire name the registry never sees.
 */
final class TestOnlyCommandRegistry
{
    /**
     * Tells whether one command name is test-only.
     *
     * @param string $command Command name as it arrived on the wire
     * @return bool True when the command may only run outside a production-like environment
     */
    public static function isTestOnly(string $command): bool
    {
        return str_starts_with($command, CommandConstants::TEST_ONLY_PREFIX);
    }
}
