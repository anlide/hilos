<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\ClusterTestInspectCommand;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Core\CLI\Commands\CommandInterface;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Commands\DbTestResetCommand;
use Hilos\Core\CLI\Commands\DbWaitCommand;
use Hilos\Core\CLI\Commands\HelpCommand;
use PHPUnit\Framework\TestCase;

/**
 * Pins the gate the CLI bootstrap reads before it connects: which command names answer "no
 * database needed", and that the answer comes from the command's own
 * {@see DatabaseFreeCommand} marker rather than from a name list the entrypoint keeps.
 *
 * The whole suite runs without a database, which is part of what it checks: constructing a
 * manager constructs every registered command, so a command constructor that reached for a
 * connection would fail here — instead of failing inside a `db:wait` on the machine whose
 * MySQL is down.
 */
final class CliManagerDatabaseGateTest extends TestCase
{
    /** @var string Project command stub that declares itself database-free */
    public const string PROJECT_FREE_COMMAND = 'test:project:database-free';

    /** @var string Project command stub that declares nothing and so keeps the connect */
    public const string PROJECT_PLAIN_COMMAND = 'test:project:plain';

    private CliManager $manager;

    protected function setUp(): void
    {
        $this->manager = new CliManager([]);
    }

    public function testMarkedCommandsSkipTheBootstrapConnect(): void
    {
        $this->assertFalse($this->manager->requiresDatabase(CliCommands::DB_WAIT));
        $this->assertFalse($this->manager->requiresDatabase(CliCommands::DB_TEST_RESET));
        $this->assertFalse($this->manager->requiresDatabase(CliCommands::CLUSTER_TEST_INSPECT));
        $this->assertFalse($this->manager->requiresDatabase(CliCommands::HELP));
    }

    public function testMissingCommandNameResolvesToHelp(): void
    {
        $this->assertFalse($this->manager->requiresDatabase(null));
    }

    public function testOrdinaryCommandKeepsTheFullBootstrap(): void
    {
        $this->assertTrue($this->manager->requiresDatabase(CliCommands::DAEMON_STATUS));
    }

    public function testUnregisteredNameSkipsTheConnect(): void
    {
        $this->assertFalse($this->manager->requiresDatabase('no:such:command'));
    }

    public function testProjectCommandDeclaresTheContractItself(): void
    {
        $manager = self::managerWithProjectCommands();

        $this->assertFalse($manager->requiresDatabase(self::PROJECT_FREE_COMMAND));
        $this->assertTrue($manager->requiresDatabase(self::PROJECT_PLAIN_COMMAND));
    }

    public function testMarkedCommandsCarryTheMarkerInterface(): void
    {
        $this->assertInstanceOf(DatabaseFreeCommand::class, new DbWaitCommand());
        $this->assertInstanceOf(DatabaseFreeCommand::class, new DbTestResetCommand());
        $this->assertInstanceOf(DatabaseFreeCommand::class, new ClusterTestInspectCommand());
        $this->assertInstanceOf(DatabaseFreeCommand::class, new HelpCommand());
    }

    /**
     * Builds a manager carrying two project command stubs — one marked, one plain — the way
     * a project subclass registers its own. Pins that the marker replaces the removed
     * $commandsWithoutDb bootstrap parameter like for like: a project can still declare a
     * database-free command, now from the command instead of from cli.php.
     *
     * @return CliManager Manager whose registry holds both stubs
     */
    private static function managerWithProjectCommands(): CliManager
    {
        return new class ([]) extends CliManager {
            protected function registerProjectCommands(): void
            {
                $this->addCommand(new class implements CommandInterface, DatabaseFreeCommand {
                    public function execute(array $options, array $args): int
                    {
                        return ExitCode::SUCCESS;
                    }

                    public function execution(): CommandExecution
                    {
                        return CommandExecution::daemon();
                    }

                    public function getName(): string
                    {
                        return CliManagerDatabaseGateTest::PROJECT_FREE_COMMAND;
                    }

                    public function getDescription(): string
                    {
                        return 'Project command stub that needs no database';
                    }

                    public function getHelp(): string
                    {
                        return 'Project command stub that needs no database';
                    }
                });

                $this->addCommand(new class implements CommandInterface {
                    public function execute(array $options, array $args): int
                    {
                        return ExitCode::SUCCESS;
                    }

                    public function execution(): CommandExecution
                    {
                        return CommandExecution::daemon();
                    }

                    public function getName(): string
                    {
                        return CliManagerDatabaseGateTest::PROJECT_PLAIN_COMMAND;
                    }

                    public function getDescription(): string
                    {
                        return 'Project command stub that keeps the full bootstrap';
                    }

                    public function getHelp(): string
                    {
                        return 'Project command stub that keeps the full bootstrap';
                    }
                });
            }
        };
    }
}
