<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\AdminViewModeTestCommand;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CLI half of test:admin-view-mode (HIL-1249).
 *
 * What is pinned is what on and off turn into on the wire and what the master's answer turns into
 * on the terminal. Anything but on or off is refused here, before anything is sent, so a typo never
 * flips a running stand. The write itself is the master's and is covered by
 * CommandClientAdminViewModeTest.
 *
 * Runs under a non-production APP_ENV so the {@see TestOnlyCommand} guard admits the body.
 */
final class AdminViewModeTestCommandTest extends TestCase
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
        self::assertTrue(TestOnlyCommandRegistry::isTestOnly(CliCommands::ADMIN_VIEW_MODE_TEST));
        self::assertSame(CliCommands::ADMIN_VIEW_MODE_TEST, new AdminViewModeTestCommand()->getName());
    }

    public function testOnSendsTheModeOn(): void
    {
        $command = new AdminViewModeTestCommandSpy();

        $this->expectOutputString("Admin view mode on\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], ['on']));
        self::assertSame(CliCommands::ADMIN_VIEW_MODE_TEST, $command->sentCommand);
        self::assertSame([CommandConstants::FIELD_ENABLED => true], $command->sentPayload);
    }

    public function testOffSendsTheModeOff(): void
    {
        $command = new AdminViewModeTestCommandSpy();

        $this->expectOutputString("Admin view mode off\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], ['off']));
        self::assertSame([CommandConstants::FIELD_ENABLED => false], $command->sentPayload);
    }

    public function testWhatIsPrintedIsTheModeTheMasterAnswered(): void
    {
        // The terminal says what the node holds now, not what was typed: the two only differ when
        // something between them is wrong, and that is exactly when the operator needs to see it.
        $command = new AdminViewModeTestCommandSpy();
        $command->reply = CommandReplyDTO::ok('cid', [CommandConstants::FIELD_ENABLED => false]);

        $this->expectOutputString("Admin view mode off\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], ['on']));
    }

    /**
     * @return array<string, array{0: list<string>}> Positional args the command cannot read
     */
    public static function unreadableArguments(): array
    {
        return [
            'nothing' => [[]],
            'a word it does not know' => [['yes']],
            'on in capitals' => [['ON']],
        ];
    }

    /**
     * @param list<string> $args Positional args as the CLI hands them over
     */
    #[DataProvider('unreadableArguments')]
    public function testAnythingButOnOrOffIsRefusedBeforeAnythingIsSent(array $args): void
    {
        $command = new AdminViewModeTestCommandSpy();

        $this->expectOutputString("Error: say on or off\n");
        self::assertSame(ExitCode::ERROR, $command->execute([], $args));
        self::assertNull($command->sentCommand);
    }

    public function testTheMastersRefusalIsRelayedAsAFailure(): void
    {
        $command = new AdminViewModeTestCommandSpy();
        $command->reply = CommandReplyDTO::error('cid', 'This node holds no runtime state for the admin view mode');

        self::assertSame(ExitCode::ERROR, $command->execute([], ['on']));
        self::assertStringContainsString('This node holds no runtime state for the admin view mode', $command->standardError);
    }

    public function testAReplyWithoutTheModeIsAFailure(): void
    {
        $command = new AdminViewModeTestCommandSpy();
        $command->reply = CommandReplyDTO::ok('cid', []);

        $this->expectOutputString("Command failed: the reply names no admin view mode\n");
        self::assertSame(ExitCode::ERROR, $command->execute([], ['on']));
    }
}

/**
 * The command with its one outward call stood in: what it would have put on the wire is captured
 * instead, and a canned reply comes back.
 */
final class AdminViewModeTestCommandSpy extends AdminViewModeTestCommand
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
