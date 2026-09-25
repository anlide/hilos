<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\TableTestLagCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CLI half of test:table:lag (HIL-1020).
 *
 * What is pinned is what the operator's options turn into on the wire and what the master's
 * answer turns into on the terminal. A value the queue could not read is refused here, before
 * anything is sent, so a typo never reaches a running stand; and every call names the whole
 * state, so a bare call is the one that takes the lag off. The write itself is the master's and
 * is covered by CommandClientTableLagTest.
 *
 * Runs under a non-production APP_ENV so the {@see TestOnlyCommand} guard admits the body.
 */
final class TableTestLagCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    /** @var string|false APP_ENV the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousAppEnv = getenv('APP_ENV');
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
    }

    public function testTheNameIsTestOnly(): void
    {
        self::assertTrue(TestOnlyCommandRegistry::isTestOnly(CliCommands::TABLE_TEST_LAG));
        self::assertSame(CliCommands::TABLE_TEST_LAG, new TableTestLagCommand()->getName());
    }

    public function testACallWithoutOptionsSendsBothLagsOff(): void
    {
        $command = new TableTestLagCommandSpy();

        $this->expectOutputString("Table lag off\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], []));
        self::assertSame(CliCommands::TABLE_TEST_LAG, $command->sentCommand);
        self::assertSame([
            CommandConstants::FIELD_WINDOW_MS => 0,
            CommandConstants::FIELD_FACETS_MS => 0,
        ], $command->sentPayload);
    }

    public function testAnOptionLeftOutTravelsAsZeroBesideTheOneNamed(): void
    {
        $command = new TableTestLagCommandSpy();

        $this->expectOutputString("Table lag: window 60000 ms, facets 0 ms\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute(['window' => '60000'], []));
        self::assertSame([
            CommandConstants::FIELD_WINDOW_MS => 60000,
            CommandConstants::FIELD_FACETS_MS => 0,
        ], $command->sentPayload);
    }

    public function testWhatIsPrintedIsTheStateTheMasterAnswered(): void
    {
        // The terminal says what the node holds now, not what was typed: the two only differ when
        // something between them is wrong, and that is exactly when the operator needs to see it.
        $command = new TableTestLagCommandSpy();
        $command->reply = CommandReplyDTO::ok('cid', [
            CommandConstants::FIELD_WINDOW_MS => 0,
            CommandConstants::FIELD_FACETS_MS => 1500,
        ]);

        $this->expectOutputString("Table lag: window 0 ms, facets 1500 ms\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute(['window' => '0', 'facets' => '1500'], []));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}> Options and the option the refusal names
     */
    public static function unreadableLags(): array
    {
        return [
            'window not a number' => [['window' => 'abc'], 'window'],
            'window negative' => [['window' => '-1'], 'window'],
            'window without a value' => [['window' => true], 'window'],
            'window fractional' => [['window' => '1.5'], 'window'],
            'facets not a number' => [['facets' => 'abc'], 'facets'],
            'facets negative' => [['facets' => '-1'], 'facets'],
            'facets without a value' => [['facets' => true], 'facets'],
        ];
    }

    /**
     * @param array<string, mixed> $options Parsed options as the CLI hands them over
     * @param string $option Option the refusal has to name
     */
    #[DataProvider('unreadableLags')]
    public function testAnUnreadableLagIsRefusedBeforeAnythingIsSent(array $options, string $option): void
    {
        $command = new TableTestLagCommandSpy();

        $this->expectOutputString("Error: --{$option} must be a whole number of milliseconds, 0 or more\n");
        self::assertSame(ExitCode::ERROR, $command->execute($options, []));
        self::assertNull($command->sentCommand);
    }

    public function testTheMastersRefusalIsRelayedAsAFailure(): void
    {
        $command = new TableTestLagCommandSpy();
        $command->reply = CommandReplyDTO::error('cid', 'This node holds no runtime state for a table lag');

        self::assertSame(ExitCode::ERROR, $command->execute(['window' => '100'], []));
        self::assertStringContainsString('This node holds no runtime state for a table lag', $command->standardError);
    }

    public function testAReplyWithoutTheLagsIsAFailure(): void
    {
        $command = new TableTestLagCommandSpy();
        $command->reply = CommandReplyDTO::ok('cid', []);

        $this->expectOutputString("Command failed: the reply names no table lag\n");
        self::assertSame(ExitCode::ERROR, $command->execute(['window' => '100'], []));
    }
}

/**
 * The command with its one outward call stood in: what it would have put on the wire is captured
 * instead, and a canned reply comes back.
 */
final class TableTestLagCommandSpy extends TableTestLagCommand
{
    /** @var ?string Wire name the command sent, or null when it refused before sending */
    public ?string $sentCommand = null;

    /** @var ?array<string, mixed> Payload the command sent, or null when it refused before sending */
    public ?array $sentPayload = null;

    /** @var ?CommandReplyDTO Reply handed back to the command; the sent payload echoed as written by default */
    public ?CommandReplyDTO $reply = null;

    /** @var string What the command wrote to stderr, kept here instead of the test run's own stream */
    public string $standardError = '';

    /**
     * Captures the round-trip instead of opening a socket.
     *
     * @param string $command Command-channel wire name
     * @param array<string, mixed> $payload Request payload
     * @param ?float $waitSeconds Wait budget (ignored)
     * @return CommandChannelResult Canned reply
     */
    protected function sendCommand(
        string $command,
        array $payload,
        ?float $waitSeconds = null,
    ): CommandChannelResult {
        $this->sentCommand = $command;
        $this->sentPayload = $payload;

        return CommandChannelResult::replied($this->reply ?? CommandReplyDTO::ok('cid', $payload), '127.0.0.1:8094');
    }

    /**
     * Keeps a refusal instead of writing it to the test run's stderr.
     *
     * @param string $text Sentence the command wrote
     */
    protected function writeToStandardError(string $text): void
    {
        $this->standardError .= $text;
    }
}
