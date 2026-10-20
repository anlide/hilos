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
use Hilos\Core\CLI\Commands\OrmTestForgetCommand;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use PHPUnit\Framework\Attributes\DataProvider;

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
        $this->assertFalse($this->manager->requiresDatabase(CliCommands::ORM_TEST_FORGET));
        $this->assertFalse($this->manager->requiresDatabase(CliCommands::DB_WAIT));
        $this->assertFalse($this->manager->requiresDatabase(CliCommands::DB_TEST_RESET));
        $this->assertFalse($this->manager->requiresDatabase(CliCommands::CLUSTER_TEST_INSPECT));
        $this->assertFalse($this->manager->requiresDatabase(CliCommands::HELP));
    }

    /**
     * @param list<string> $args Valid process target
     * @param array<string, mixed> $expected Exact target sent to the master
     */
    #[DataProvider('ormTargets')]
    public function testOrmCommandSendsOnlyTheSelectedTarget(array $args, array $expected): void
    {
        $command = new OrmForgetCliProbe();
        $command->outcome = CommandChannelResult::replied(CommandReplyDTO::ok('corr', $expected + ['workerId' => null]), 'test:1');
        ob_start();
        try {
            self::assertSame(ExitCode::SUCCESS, $command->invoke($args));
            self::assertSame($expected, $command->payload);
            self::assertSame(CliCommands::ORM_TEST_FORGET, $command->sentCommand);
            self::assertSame($expected + ['workerId' => null], json_decode(ob_get_contents(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            ob_end_clean();
        }
    }

    /** @return list<array{list<string>, array<string, mixed>}> Accepted CLI targets */
    public static function ormTargets(): array
    {
        return [
            [['daemon'], ['process' => 'daemon']],
            [['worker', '3'], ['process' => 'worker', 'workerId' => 3]],
            [['worker', '-3'], ['process' => 'worker', 'workerId' => -3]],
            [['worker', '+3'], ['process' => 'worker', 'workerId' => 3]],
        ];
    }

    /** @param list<string> $args Invalid process target */
    #[DataProvider('invalidOrmTargets')]
    public function testOrmCommandRefusesMalformedTargetsBeforeSending(array $args): void
    {
        $command = new OrmForgetCliProbe();
        self::assertSame(ExitCode::ERROR, $command->invoke($args));
        self::assertSame([], $command->payload);
        self::assertSame('Refused: Invalid ORM forget target', trim($command->stderr));
    }

    /** @return list<array{list<string>}> Targets refused before any command is sent */
    public static function invalidOrmTargets(): array
    {
        return array_map(static fn (array $args): array => [$args], [
            [], ['daemon', '1'], ['other'], ['worker'], ['worker', '0'], ['worker', '-0'],
            ['worker', '1.0'], ['worker', '3x'], ['worker', '1', 'extra'], ['worker', '999999999999999999999999'],
        ]);
    }

    /** Both refusal paths use the command-channel family's stderr wording. */
    public function testOrmCommandUsesSharedRefusalAndChannelFailurePrinters(): void
    {
        $command = new OrmForgetCliProbe();
        $command->outcome = CommandChannelResult::replied(CommandReplyDTO::error('corr', 'Transaction open'), 'test:1');
        self::assertSame(ExitCode::ERROR, $command->invoke(['daemon']));
        self::assertSame('Refused: Transaction open', trim($command->stderr));
        $command->stderr = '';
        $command->outcome = CommandChannelResult::timedOut('test:1');
        self::assertSame(ExitCode::ERROR, $command->invoke(['daemon']));
        self::assertStringContainsString('The daemon did not answer test:orm:forget within', $command->stderr);
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

/** Captures the CLI boundary without connecting or opening a database. */
final class OrmForgetCliProbe extends OrmTestForgetCommand
{
    public array $payload = [];
    public ?string $sentCommand = null;
    public string $stderr = '';
    public CommandChannelResult $outcome;

    /**
     * @param list<string> $args Target arguments
     * @return int Result of parsing and sending the test arguments
     */
    public function invoke(array $args): int
    {
        return $this->run([], $args);
    }

    /**
     * @param string $command Wire name to capture
     * @param array<string, mixed> $payload Target to capture
     * @param ?float $waitSeconds Unused wait budget
     * @return CommandChannelResult Programmed command-channel result
     */
    protected function sendCommand(string $command, array $payload, ?float $waitSeconds = null): CommandChannelResult
    {
        $this->sentCommand = $command;
        $this->payload = $payload;
        return $this->outcome;
    }

    /**
     * @param string $text Shared printer output
     * @return int Refusal exit code
     */
    protected function printToStandardError(string $text): int
    {
        $this->stderr .= $text;
        return ExitCode::ERROR;
    }
}
