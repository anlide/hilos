<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Core\CLI\Commands\CommandExecutionSite;
use Hilos\Core\CLI\Commands\CommandInterface;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Commands\StandTestResetCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\TruthSource\RtTruthSourceRegistry;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the composite test stand reset command and its sequence invariants.
 */
final class StandTestResetCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousAppEnv = getenv('APP_ENV');
        $this->previousEnv = Hilos::$env;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        TruthSourceRegistry::unregisterAgent(TestOnlyCommand::TRUTH_SOURCE_ID);
        RtTruthSourceRegistry::unregisterAgent(TestOnlyCommand::TRUTH_SOURCE_ID);
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(TestOnlyCommand::TRUTH_SOURCE_ID));

        Hilos::$env = $this->previousEnv;
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
    }

    public function testCommandContract(): void
    {
        $command = new StandTestResetCommand([]);
        self::assertInstanceOf(TestOnlyCommand::class, $command);
        self::assertInstanceOf(DatabaseFreeCommand::class, $command);
    }

    public function testStepsAreCalledInStepsOrder(): void
    {
        $journal = [];
        $commands = [];
        foreach (StandTestResetCommand::STEPS as $stepName) {
            $commands[$stepName] = new class($stepName, $journal) implements CommandInterface {
                /**
                 * @param list<string> $journal
                 */
                public function __construct(
                    private string $name,
                    private array &$journal,
                ) {
                }

                public function getName(): string
                {
                    return $this->name;
                }

                public function execution(): CommandExecution
                {
                    return CommandExecution::cliOfflineWrite('test stub');
                }

                public function getDescription(): string
                {
                    return 'test stub';
                }

                public function getHelp(): string
                {
                    return 'test stub';
                }

                public function execute(array $options, array $args): int
                {
                    $this->journal[] = $this->name;

                    return ExitCode::SUCCESS;
                }
            };
        }

        $command = new StandTestResetCommand($commands);
        ob_start();
        try {
            $code = $command->execute([], []);
        } finally {
            $output = (string) ob_get_clean();
        }

        self::assertSame(ExitCode::SUCCESS, $code);
        self::assertSame(StandTestResetCommand::STEPS, $journal);
        self::assertStringContainsString('Test stand reset complete (4 step(s)).', $output);
    }

    public function testStepReturningErrorStopsSubsequentStepsAndReturnsCode(): void
    {
        $journal = [];
        $commands = [];
        foreach (StandTestResetCommand::STEPS as $index => $stepName) {
            $failing = ($index === 1);
            $commands[$stepName] = new class($stepName, $journal, $failing) implements CommandInterface {
                /**
                 * @param list<string> $journal
                 */
                public function __construct(
                    private string $name,
                    private array &$journal,
                    private bool $failing,
                ) {
                }

                public function getName(): string
                {
                    return $this->name;
                }

                public function execution(): CommandExecution
                {
                    return CommandExecution::cliOfflineWrite('test stub');
                }

                public function getDescription(): string
                {
                    return 'test stub';
                }

                public function getHelp(): string
                {
                    return 'test stub';
                }

                public function execute(array $options, array $args): int
                {
                    $this->journal[] = $this->name;

                    return $this->failing ? ExitCode::ERROR : ExitCode::SUCCESS;
                }
            };
        }

        $command = new StandTestResetCommand($commands);
        ob_start();
        try {
            $code = $command->execute([], []);
        } finally {
            $output = (string) ob_get_clean();
        }

        self::assertSame(ExitCode::ERROR, $code);
        self::assertSame([StandTestResetCommand::STEPS[0], StandTestResetCommand::STEPS[1]], $journal);
        self::assertStringContainsString(
            'Test stand reset stopped at ' . StandTestResetCommand::STEPS[1] . ' with code 1.',
            $output,
        );
    }

    public function testMissingStepInRegistryReturnsConfigErrorAndRunsNoSteps(): void
    {
        $journal = [];
        $commands = [
            StandTestResetCommand::STEPS[0] => new class(StandTestResetCommand::STEPS[0], $journal) implements CommandInterface {
                /**
                 * @param list<string> $journal
                 */
                public function __construct(
                    private string $name,
                    private array &$journal,
                ) {
                }

                public function getName(): string
                {
                    return $this->name;
                }

                public function execution(): CommandExecution
                {
                    return CommandExecution::cliOfflineWrite('test stub');
                }

                public function getDescription(): string
                {
                    return 'test stub';
                }

                public function getHelp(): string
                {
                    return 'test stub';
                }

                public function execute(array $options, array $args): int
                {
                    $this->journal[] = $this->name;

                    return ExitCode::SUCCESS;
                }
            },
        ];

        $command = new StandTestResetCommand($commands);
        ob_start();
        try {
            $code = $command->execute([], []);
        } finally {
            $output = (string) ob_get_clean();
        }

        self::assertSame(ExitCode::CONFIG_ERROR, $code);
        self::assertSame([], $journal);
        self::assertStringContainsString(
            'missing command(s) in registry: ' . StandTestResetCommand::STEPS[1],
            $output,
        );
    }

    public function testRefusesOnProductionBeforeSteps(): void
    {
        putenv('APP_ENV=prod');
        Hilos::$env = new EnvAccessor();

        $commands = [];
        foreach (StandTestResetCommand::STEPS as $stepName) {
            $commands[$stepName] = new class implements CommandInterface {
                public function getName(): string
                {
                    return 'test';
                }

                public function execution(): CommandExecution
                {
                    return CommandExecution::cliOfflineWrite('test stub');
                }

                public function getDescription(): string
                {
                    return 'test stub';
                }

                public function getHelp(): string
                {
                    return 'test stub';
                }

                public function execute(array $options, array $args): int
                {
                    throw new LogicException('Step should not be called on production');
                }
            };
        }

        $command = new StandTestResetCommand($commands);
        $this->expectException(TestOnlyCommandOnProductionException::class);
        $this->expectExceptionMessage(CliCommands::STAND_TEST_RESET);
        $command->execute([], []);
    }

    public function testAfterRunNoTestCliClaimsRemainInTruthSourceRegistry(): void
    {
        $commands = [];
        foreach (StandTestResetCommand::STEPS as $stepName) {
            $commands[$stepName] = new class($stepName) extends TestOnlyCommand {
                public const array OWNS_DB = ['test_stand_reset_step_col' => TruthSourceOperation::BY_KIND];

                public function __construct(private string $name)
                {
                }

                public function getName(): string
                {
                    return $this->name;
                }

                public function execution(): CommandExecution
                {
                    return CommandExecution::cliOfflineWrite('test stub');
                }

                public function getDescription(): string
                {
                    return 'test stub';
                }

                public function getHelp(): string
                {
                    return 'test stub';
                }

                protected function run(array $options, array $args): int
                {
                    return ExitCode::SUCCESS;
                }
            };
        }

        $command = new StandTestResetCommand($commands);
        ob_start();
        try {
            $code = $command->execute([], []);
        } finally {
            ob_end_clean();
        }

        self::assertSame(ExitCode::SUCCESS, $code);
        self::assertFalse(TruthSourceRegistry::hasTruthSource('test_stand_reset_step_col'));
        self::assertSame(
            [],
            SourceInterestRegistry::collectionsOfConsumer(
                SourceConsumer::agent(TestOnlyCommand::TRUTH_SOURCE_ID),
                SourceChange::KIND_DB,
            ),
        );
    }

    public function testOfflineResetCommandsMatchStandResetSteps(): void
    {
        $executions = new CliManager([])->executions();
        $resetCommands = [];
        foreach ($executions as $name => $execution) {
            if (
                preg_match('/^test:[^:]+:reset$/', $name) === 1
                && $execution->site === CommandExecutionSite::CLI_OFFLINE_WRITE
                && $name !== CliCommands::STAND_TEST_RESET
            ) {
                $resetCommands[] = $name;
            }
        }

        sort($resetCommands);
        $expectedSteps = StandTestResetCommand::STEPS;
        sort($expectedSteps);

        self::assertSame($expectedSteps, $resetCommands);
    }
}
