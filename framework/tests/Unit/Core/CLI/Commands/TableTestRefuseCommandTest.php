<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Commands\TableTestRefuseCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CLI half of test:table:refuse (HIL-1131).
 *
 * What is pinned is what the operator's argument turns into on the wire and what the master's
 * answer turns into on the terminal. Every call names the whole state, so a bare call is the one
 * that takes the refusal off. The write itself is the master's and is covered by
 * CommandClientTableRefusalTest.
 *
 * Runs under a non-production APP_ENV so the {@see TestOnlyCommand} guard admits the body.
 */
final class TableTestRefuseCommandTest extends TestCase
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

    public function testTheNameIsTestOnlyAndTheCommandNeedsNoDatabase(): void
    {
        $command = new TableTestRefuseCommand();

        self::assertTrue(TestOnlyCommandRegistry::isTestOnly(CliCommands::TABLE_TEST_REFUSE));
        self::assertSame(CliCommands::TABLE_TEST_REFUSE, $command->getName());
        self::assertInstanceOf(DatabaseFreeCommand::class, $command);
    }

    public function testACallWithoutAKeySendsTheRefusalOff(): void
    {
        $command = new TableTestRefuseCommandSpy();

        $this->expectOutputString("Table refusal off\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], []));
        self::assertSame(CliCommands::TABLE_TEST_REFUSE, $command->sentCommand);
        self::assertSame([CommandConstants::FIELD_TABLE_KEY => ''], $command->sentPayload);
    }

    public function testACallWithAKeySendsTheTableItNames(): void
    {
        $command = new TableTestRefuseCommandSpy();

        $this->expectOutputString("Table windows refused: hilosSecurityStepUp\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], ['hilosSecurityStepUp']));
        self::assertSame(CliCommands::TABLE_TEST_REFUSE, $command->sentCommand);
        self::assertSame([CommandConstants::FIELD_TABLE_KEY => 'hilosSecurityStepUp'], $command->sentPayload);
    }

    public function testWhatIsPrintedIsTheStateTheMasterAnswered(): void
    {
        // The terminal says what the node holds now, not what was typed: the two only differ when
        // something between them is wrong, and that is exactly when the operator needs to see it.
        $command = new TableTestRefuseCommandSpy();
        $command->reply = CommandReplyDTO::ok('cid', [CommandConstants::FIELD_TABLE_KEY => '']);

        $this->expectOutputString("Table refusal off\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], ['settings']));
    }

    public function testTheMastersRefusalIsRelayedAsAFailure(): void
    {
        $command = new TableTestRefuseCommandSpy();
        $command->reply = CommandReplyDTO::error('cid', 'This node holds no runtime state for a table refusal');

        self::assertSame(ExitCode::ERROR, $command->execute([], ['settings']));
        self::assertStringContainsString('This node holds no runtime state for a table refusal', $command->standardError);
    }

    public function testAReplyWithoutTheKeyIsAFailure(): void
    {
        $command = new TableTestRefuseCommandSpy();
        $command->reply = CommandReplyDTO::ok('cid', []);

        $this->expectOutputString("Command failed: the reply names no table refusal\n");
        self::assertSame(ExitCode::ERROR, $command->execute([], ['settings']));
    }
}

/**
 * The command with its one outward call stood in: what it would have put on the wire is captured
 * instead, and a canned reply comes back.
 */
final class TableTestRefuseCommandSpy extends TableTestRefuseCommand
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
