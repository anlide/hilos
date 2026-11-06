<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Core\CLI\Commands\CronTestPauseCommand;
use Hilos\Core\CLI\Commands\CronTestCancelPauseCommand;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The terminal halves only parse, send and print the owner's answer. */
final class CronTestScheduleCommandsTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private string|false $previousAppEnv;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousAppEnv = getenv('APP_ENV');
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        putenv($this->previousAppEnv === false ? 'APP_ENV' : 'APP_ENV=' . $this->previousAppEnv);
        parent::tearDown();
    }

    /**
     * @param string $selector CLI rule address
     * @param ?string $agentId Exact wire owner
     * @param string $ruleName Exact wire rule
     */
    #[DataProvider('selectors')]
    public function testPauseArgumentsPayloadAndOutput(string $selector, ?string $agentId, string $ruleName): void
    {
        $command = new CronSchedulePauseSpy();
        $command->result = CommandChannelResult::replied(CommandReplyDTO::ok('c-1', ['pauseId' => 'opaque-id']), '127.0.0.1:1');
        self::assertSame('test:cron:pause', $command->getName());
        self::assertInstanceOf(DatabaseFreeCommand::class, $command);
        self::assertEquals(CommandExecution::daemon(), $command->execution());
        $this->expectOutputString("Paused {$selector}: pause opaque-id\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], [$selector]));
        self::assertSame('test:cron:pause', $command->sentCommand);
        self::assertSame(['agentId' => $agentId, 'ruleName' => $ruleName], $command->sentPayload);
    }

    /** @return iterable<string, array{string, ?string, string}> Selectors cut at their first slash */
    public static function selectors(): iterable
    {
        yield 'daemon' => ['daemon/cleanup', null, 'cleanup'];
        yield 'agent' => ['mailer:7/nightly/digest', 'mailer:7', 'nightly/digest'];
    }

    public function testCancellationArgumentsPayloadAndOutput(): void
    {
        $command = new CronScheduleCancelSpy();
        $command->result = CommandChannelResult::replied(CommandReplyDTO::ok('c-1'), '127.0.0.1:1');
        self::assertSame('test:cron:cancel-pause', $command->getName());
        self::assertInstanceOf(DatabaseFreeCommand::class, $command);
        self::assertEquals(CommandExecution::daemon(), $command->execution());
        $this->expectOutputString("Canceled pause opaque-id\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], ['opaque-id']));
        self::assertSame('test:cron:cancel-pause', $command->sentCommand);
        self::assertSame(['pauseId' => 'opaque-id'], $command->sentPayload);
    }

    public function testInvalidArgumentsNeverReachTheChannel(): void
    {
        foreach ([new CronSchedulePauseSpy(), new CronScheduleCancelSpy()] as $command) {
            $invalid = [[], [''], ['a', 'b']];
            if ($command instanceof CronTestPauseCommand) {
                array_push($invalid, ['daemon'], ['/rule'], ['daemon/']);
            }
            foreach ($invalid as $args) {
                ob_start();
                try {
                    self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute([], $args));
                } finally {
                    $output = ob_get_clean();
                }
                self::assertStringContainsString('Usage: ' . $command->getName(), $output);
                self::assertNull($command->sentCommand);
            }
        }
    }

    public function testRefusalsAndTransportFailuresUseTheSharedErrorStream(): void
    {
        foreach ([new CronSchedulePauseSpy(), new CronScheduleCancelSpy()] as $command) {
            foreach ([
                CommandChannelResult::replied(CommandReplyDTO::error('c-1', 'No pause opaque-id'), '127.0.0.1:1'),
                CommandChannelResult::unreachable('127.0.0.1:1'),
                CommandChannelResult::timedOut('127.0.0.1:1'),
            ] as $result) {
                $command->result = $result;
                $command->standardError = '';
                self::assertSame(ExitCode::ERROR, $command->execute([], ['daemon/cleanup']));
                self::assertNotSame('', $command->standardError);
                if ($result->reply !== null) {
                    self::assertSame('Refused: No pause opaque-id', $command->standardError);
                }
            }
        }
        $this->expectOutputString('');
    }
}

/** Captures the channel and stderr, leaving parsing and successful stdout real. */
trait CronScheduleChannelSpy
{
    public ?string $sentCommand = null;
    public ?array $sentPayload = null;
    public CommandChannelResult $result;
    public string $standardError = '';

    /**
     * @param string $command Wire command name
     * @param array<string, mixed> $payload Parsed arguments
     * @param ?float $waitSeconds Unused wait budget
     * @return CommandChannelResult Canned channel outcome
     */
    protected function sendCommand(string $command, array $payload, ?float $waitSeconds = null): CommandChannelResult
    {
        $this->sentCommand = $command;
        $this->sentPayload = $payload;
        return $this->result;
    }

    /** @param string $text Shared refusal or transport sentence */
    protected function writeToStandardError(string $text): void
    {
        $this->standardError .= $text;
    }
}

final class CronSchedulePauseSpy extends CronTestPauseCommand
{
    use CronScheduleChannelSpy;
}

final class CronScheduleCancelSpy extends CronTestCancelPauseCommand
{
    use CronScheduleChannelSpy;
}
