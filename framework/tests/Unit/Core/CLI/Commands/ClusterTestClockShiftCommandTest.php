<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\ClusterTestClockShiftCommand;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the CLI half of test:cluster:clock:shift.
 *
 * The CLI parses the signed option, sends the shift to the master and prints its reply. The
 * master's validation and process-local write are covered by CommandClientClockShiftTest.
 */
final class ClusterTestClockShiftCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    /** @var string|false APP_ENV the suite ran under, restored for the next case */
    private string|false $previousAppEnv = false;

    /** Prepares a non-production command environment. */
    protected function setUp(): void
    {
        $this->previousAppEnv = getenv('APP_ENV');
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    /** Restores the environment the next unit test will read. */
    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
    }

    /** Confirms the registry name and terminal command name share the test-only prefix. */
    public function testTheNameIsTestOnly(): void
    {
        self::assertTrue(TestOnlyCommandRegistry::isTestOnly(CliCommands::CLUSTER_TEST_CLOCK_SHIFT));
        self::assertSame(CliCommands::CLUSTER_TEST_CLOCK_SHIFT, new ClusterTestClockShiftCommand()->getName());
    }

    /** Confirms an omitted option sends the zero shift used to clear the master's value. */
    public function testACommandWithoutMsSendsZeroToClearTheShift(): void
    {
        $command = new ClusterTestClockShiftCommandSpy();

        $this->expectOutputString("Cluster clock test shift: 0 ms\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], []));
        self::assertSame(CliCommands::CLUSTER_TEST_CLOCK_SHIFT, $command->sentCommand);
        self::assertSame([CommandConstants::FIELD_SHIFT_MS => 0], $command->sentPayload);
    }

    /** @return array<string, array{string, int}> Signed option and integer sent to the master */
    public static function signedShifts(): array
    {
        return [
            'positive' => ['1200', 1200],
            'explicit positive' => ['+1200', 1200],
            'negative' => ['-1200', -1200],
            'positive one day' => ['86400000', 86400000],
            'negative one day' => ['-86400000', -86400000],
        ];
    }

    /**
     * @param string $rawShiftMs Signed integer as the CLI option reader supplies it
     * @param int $shiftMs Integer payload sent to the master
     */
    #[DataProvider('signedShifts')]
    public function testASignedShiftIsSent(string $rawShiftMs, int $shiftMs): void
    {
        $command = new ClusterTestClockShiftCommandSpy();

        $this->expectOutputString("Cluster clock test shift: {$shiftMs} ms\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute(['ms' => $rawShiftMs], []));
        self::assertSame([CommandConstants::FIELD_SHIFT_MS => $shiftMs], $command->sentPayload);
    }

    /** Confirms successful output comes from the reply payload, not the option value. */
    public function testTheTerminalPrintsTheMasterReadback(): void
    {
        $command = new ClusterTestClockShiftCommandSpy();
        $command->reply = CommandReplyDTO::ok('cid', [CommandConstants::FIELD_SHIFT_MS => -25]);

        $this->expectOutputString("Cluster clock test shift: -25 ms\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute(['ms' => '125'], []));
        self::assertSame([CommandConstants::FIELD_SHIFT_MS => 125], $command->sentPayload);
    }

    /** Confirms the master refusal travels through the shared command-channel error printer. */
    public function testAMasterRefusalIsRelayed(): void
    {
        $command = new ClusterTestClockShiftCommandSpy();
        $command->reply = CommandReplyDTO::error(
            'cid',
            'shiftMs must be a whole number of milliseconds within one day',
        );

        self::assertSame(ExitCode::ERROR, $command->execute(['ms' => '86400001'], []));
        self::assertStringContainsString(
            'shiftMs must be a whole number of milliseconds within one day',
            $command->standardError,
        );
    }

    /** @return array<string, array{mixed}> Values that are not signed whole-number options */
    public static function invalidOptions(): array
    {
        return [
            'letters' => ['abc'],
            'fraction' => ['1.5'],
            'empty' => [''],
            'flag without value' => [true],
            'integer type' => [100],
            'float type' => [1.5],
        ];
    }

    /** @param mixed $rawShiftMs Value handed to the option reader */
    #[DataProvider('invalidOptions')]
    public function testAnInvalidOptionIsRefusedBeforeSending(mixed $rawShiftMs): void
    {
        $command = new ClusterTestClockShiftCommandSpy();

        $this->expectOutputString("Error: --ms must be a whole number of milliseconds\n");
        self::assertSame(ExitCode::ERROR, $command->execute(['ms' => $rawShiftMs], []));
        self::assertNull($command->sentCommand);
    }

    /** Confirms TestOnlyCommand refuses before the command channel is called. */
    public function testTheCliProductionGateRefusesBeforeTheRoundTrip(): void
    {
        putenv('APP_ENV=prod');
        Hilos::$env = new EnvAccessor();
        $command = new ClusterTestClockShiftCommandSpy();

        $this->expectException(TestOnlyCommandOnProductionException::class);
        $command->execute([], []);
    }
}

/** Captures the command-channel exchange instead of opening a socket. */
final class ClusterTestClockShiftCommandSpy extends ClusterTestClockShiftCommand
{
    /** @var ?string Wire name sent, or null when the option was refused first */
    public ?string $sentCommand = null;

    /** @var ?array<string, mixed> Payload sent, or null when the option was refused first */
    public ?array $sentPayload = null;

    /** @var ?CommandReplyDTO Canned master reply */
    public ?CommandReplyDTO $reply = null;

    /** @var string Text sent to stderr by the shared refusal printer */
    public string $standardError = '';

    /**
     * Captures the request and returns a canned reply.
     *
     * @param string $command Command-channel wire name
     * @param array<string, mixed> $payload Request payload
     * @param ?float $waitSeconds Wait budget (ignored)
     * @return CommandChannelResult Canned reply
     */
    protected function sendCommand(string $command, array $payload, ?float $waitSeconds = null): CommandChannelResult
    {
        $this->sentCommand = $command;
        $this->sentPayload = $payload;

        return CommandChannelResult::replied($this->reply ?? CommandReplyDTO::ok('cid', $payload), '127.0.0.1:8094');
    }

    /** Captures stderr output instead of writing it during the suite. */
    protected function writeToStandardError(string $text): void
    {
        $this->standardError .= $text;
    }
}
