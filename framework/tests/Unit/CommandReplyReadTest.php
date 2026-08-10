<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupConstants;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\BackupTestRunScheduleCommand;
use Hilos\Core\CLI\Commands\PingCommand;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use PHPUnit\Framework\TestCase;

/**
 * Pins what a CLI command prints when the daemon answers with a field missing (HIL-549).
 *
 * Both commands read fields their own protocol guarantees on a successful reply — the
 * ping echoes back the very message the client put on the wire, and the schedule run
 * reports the backup it started. A reply without one is a broken command channel, and
 * used to print as `Reply (ok): ` or `Started scheduled backup  (scope=)`: the shape of
 * success with the news taken out of it. It now reads as the failure it is.
 */
final class CommandReplyReadTest extends TestCase
{
    private const string CORRELATION_ID = 'unit-correlation';

    private const string MESSAGE = 'unit-ping';

    public function testPingPrintsTheEchoedMessageWhenTheReplyCarriesIt(): void
    {
        $command = new PingCommandStub(CommandReplyDTO::ok(self::CORRELATION_ID, [
            CommandConstants::FIELD_MESSAGE => self::MESSAGE,
        ]));

        $this->assertSame(ExitCode::SUCCESS, $this->runCapturing($command));
        $this->assertStringContainsString(self::MESSAGE, $this->output);
    }

    public function testPingComplainsWhenTheReplyEchoesNothing(): void
    {
        $command = new PingCommandStub(CommandReplyDTO::ok(self::CORRELATION_ID, []));

        $this->assertSame(ExitCode::ERROR, $this->runCapturing($command));
        $this->assertStringNotContainsString('Reply (ok)', $this->output);
    }

    public function testRunScheduleReportsTheStartedBackupWhenTheReplyNamesIt(): void
    {
        $command = new RunScheduleCommandStub(CommandReplyDTO::ok(self::CORRELATION_ID, [
            BackupConstants::FIELD_BACKUP_ID => '2026-08-10_01-00-00',
            BackupConstants::FIELD_SCOPE => 'full',
        ]));

        $this->assertSame(ExitCode::SUCCESS, $this->runCapturing($command));
        $this->assertStringContainsString('2026-08-10_01-00-00', $this->output);
    }

    public function testRunScheduleComplainsWhenTheReplyNamesNoBackup(): void
    {
        $command = new RunScheduleCommandStub(CommandReplyDTO::ok(self::CORRELATION_ID, [
            BackupConstants::FIELD_SCOPE => 'full',
        ]));

        $this->assertSame(ExitCode::ERROR, $this->runCapturing($command));
        $this->assertStringNotContainsString('Started scheduled backup', $this->output);
    }

    /** @var string Everything the command printed during the last run */
    private string $output = '';

    /**
     * @param PingCommandStub|RunScheduleCommandStub $command Command to run against its canned reply
     * @return int Exit code the command returned
     */
    private function runCapturing(PingCommandStub|RunScheduleCommandStub $command): int
    {
        ob_start();
        try {
            $exitCode = $command->runForTest();
        } finally {
            $this->output = (string)ob_get_clean();
        }

        return $exitCode;
    }
}

/**
 * A ping that answers itself, so the reply-reading branch is reachable with no daemon.
 */
final class PingCommandStub extends PingCommand
{
    /**
     * @param CommandReplyDTO $reply Canned reply the ping resolves to
     */
    public function __construct(private readonly CommandReplyDTO $reply)
    {
    }

    /**
     * @return int Exit code the command returned
     */
    public function runForTest(): int
    {
        return $this->execute([], []);
    }

    /**
     * @param string $message Echo message (ignored; the canned reply stands in for the round-trip)
     * @return ?CommandReplyDTO Canned reply
     */
    protected function sendPing(string $message): ?CommandReplyDTO
    {
        return $this->reply;
    }
}

/**
 * A schedule run that answers itself, for the same reason.
 */
final class RunScheduleCommandStub extends BackupTestRunScheduleCommand
{
    /**
     * @param CommandReplyDTO $reply Canned reply the command resolves to
     */
    public function __construct(private readonly CommandReplyDTO $reply)
    {
    }

    /**
     * @return int Exit code the command returned
     */
    public function runForTest(): int
    {
        return $this->run([], []);
    }

    /**
     * @param string $command Command-channel wire name (ignored)
     * @param array<string, mixed> $payload Request payload (ignored)
     * @return ?CommandReplyDTO Canned reply
     */
    protected function sendCommand(string $command, array $payload): ?CommandReplyDTO
    {
        return $this->reply;
    }
}
