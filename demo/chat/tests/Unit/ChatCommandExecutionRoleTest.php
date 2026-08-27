<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\CLI\ChatCliManager;
use Demo\Chat\Constants\ChatCliCommands;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Core\CLI\Commands\CommandExecutionSite;
use PHPUnit\Framework\TestCase;

/**
 * The project half of the command-execution guard: chat registers its own commands, so chat is
 * where they are held to the rule. The framework suite proves the guard reaches a project
 * registry at all; this one runs it over the registry chat actually ships.
 *
 * Chat's local commands are a KNOWN, temporary departure - HIL-729 moves all fifteen files into
 * the framework - so their reasons are required to name that ticket. Without the check the
 * exception would outlive the plan silently, which is the one failure mode a written-down reason
 * is supposed to prevent.
 */
final class ChatCommandExecutionRoleTest extends TestCase
{
    /** @var string Ticket that ends the temporary exceptions and so must be named by them */
    private const string EXCEPTION_ADDRESSEE = 'HIL-729';

    public function testEveryChatCommandDeclaresItsExecution(): void
    {
        $executions = self::projectExecutions();

        $this->assertNotSame([], $executions);
        foreach ($executions as $name => $execution) {
            $this->assertInstanceOf(CommandExecution::class, $execution, "{$name} declares no execution");
        }
    }

    public function testNoDepartureFromTheRuleGoesUnexplained(): void
    {
        foreach (self::projectExecutions() as $name => $execution) {
            if ($execution->site === CommandExecutionSite::DAEMON) {
                continue;
            }

            $this->assertNotNull($execution->reason, "{$name} departs from the daemon rule without a reason");
            $this->assertNotSame('', trim($execution->reason), "{$name} departs from the daemon rule with an empty reason");
        }
    }

    public function testEveryTemporaryLocalWriteNamesTheTicketThatEndsIt(): void
    {
        $temporary = self::namesAt(CommandExecutionSite::CLI_OFFLINE_WRITE);
        foreach ($temporary as $name) {
            $this->assertStringContainsString(
                self::EXCEPTION_ADDRESSEE,
                (string) self::projectExecutions()[$name]->reason,
                "{$name} is a temporary exception whose reason names no addressee",
            );
        }

        // Emptiness here would pass every assertion above while checking nothing: the day chat
        // has no local writes left is the day this test should be deleted, not the day it goes
        // quietly green.
        $this->assertNotSame([], $temporary);
    }

    public function testTheBackupChildrenAreDeclaredAsSpawnedByTheDaemon(): void
    {
        // The backup agent spawns both itself, and a restore legitimately writes to the database
        // while the daemon is up, under protected mode. Declaring them anything else would put a
        // gate in front of a process that has no operator to read it.
        $this->assertSame(
            [ChatCliCommands::BACKUP_RUN, ChatCliCommands::BACKUP_RESTORE_RUN],
            self::namesAt(CommandExecutionSite::DAEMON_SPAWNED),
        );
    }

    /**
     * The declarations of the commands CHAT registers, without the framework ones it inherits.
     *
     * {@see ChatCliManager} extends {@see CliManager}, so its map carries the whole framework
     * registry as well; holding a framework command to chat's temporary-exception rule would fail
     * on db:migration:up, which is neither chat's nor temporary.
     *
     * @return array<string, CommandExecution> Declaration per command chat registers itself
     */
    private static function projectExecutions(): array
    {
        return array_diff_key(new ChatCliManager([])->executions(), new CliManager([])->executions());
    }

    /**
     * Names the chat-registered commands declaring one site, in registration order.
     *
     * @param CommandExecutionSite $site Site to collect
     * @return list<string> Command names declaring that site
     */
    private static function namesAt(CommandExecutionSite $site): array
    {
        $names = [];
        foreach (self::projectExecutions() as $name => $execution) {
            if ($execution->site === $site) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
