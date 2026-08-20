<?php

declare(strict_types=1);

namespace Hilos\Socket\Command;

use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\Config\AgentCommandConfigKey;
use Hilos\Hilos;

/**
 * The one place that answers whether a command-channel name is test-only.
 *
 * The flag itself is declared where the command is declared, and that is deliberately two
 * places rather than one flat list: an agent-owned command carries
 * {@see AgentCommandConfigKey::TEST_ONLY} in the same AGENT_COMMANDS entry that carries its
 * route, so the two cannot drift apart, while the three commands the master answers itself
 * appear in no agent registry at all and are named by
 * {@see CommandConstants::MASTER_TEST_ONLY_COMMANDS}. This class is what makes that split
 * invisible to the asker: the socket gate ({@see TestOnlyCommandGate}) has exactly one
 * question and exactly one place to ask it.
 *
 * The project half is resolved through {@see Hilos::appClass()} rather than a bare
 * `Hilos::` read, which would bind to the base facade and answer with its empty registry
 * however the project declared itself.
 */
final class TestOnlyCommandRegistry
{
    /**
     * Returns every command name this installation treats as test-only.
     *
     * @return list<string> Command names, agent-owned first, then the master's own
     */
    public static function commands(): array
    {
        return array_values(array_unique([
            ...Hilos::appClass()::getTestOnlyCommands(),
            ...CommandConstants::MASTER_TEST_ONLY_COMMANDS,
        ]));
    }

    /**
     * Tells whether one command name is test-only.
     *
     * @param string $command Command name as it arrived on the wire
     * @return bool True when the command may only run outside a production-like environment
     */
    public static function isTestOnly(string $command): bool
    {
        return in_array($command, self::commands(), true);
    }
}
