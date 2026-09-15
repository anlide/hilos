<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CliCommands;
use Hilos\Constants\DaemonConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\StatusCommand;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use PHPUnit\Framework\TestCase;

/**
 * Command-channel double: replays one canned outcome and records what was sent.
 *
 * The daemon round-trip is the one seam this suite must not cross (no daemon runs here), so
 * the trait method is overridden at exactly that boundary; the wording of both failures and
 * the whole table stay the real ones.
 */
final class StatusCommandProbe extends StatusCommand
{
    /** @var list<array{command: string, payload: array<string, mixed>}> What the command put on the wire */
    public array $sent = [];

    /** @var list<string> Sentences the command wrote to stderr, in order */
    public array $printedToStandardError = [];

    /** Address the canned round-trip reports itself as having used. */
    public const string ADDRESS = '127.0.0.1:8094';

    /**
     * @param CommandChannelResult $outcome Outcome the single round-trip reports: a reply, or why none came
     */
    public function __construct(
        private readonly CommandChannelResult $outcome,
    ) {
    }

    /**
     * @param string $command Command-channel wire name
     * @param array<string, mixed> $payload Request payload
     * @param ?float $waitSeconds Wait budget (ignored)
     * @return CommandChannelResult The armed outcome
     */
    protected function sendCommand(
        string $command,
        array $payload,
        ?float $waitSeconds = null,
    ): CommandChannelResult {
        $this->sent[] = ['command' => $command, 'payload' => $payload];

        return $this->outcome;
    }

    /**
     * Records what would have gone to stderr, which no output buffer can read back.
     *
     * `ob_start()` captures stdout and nothing else, so the double stands in for the stream the
     * same way it stands in for the channel above: what is replaced is the writing, not the
     * wording, and not the exit code {@see StatusCommand} answers with.
     *
     * @param string $text Sentence the command wrote
     */
    protected function writeToStandardError(string $text): void
    {
        $this->printedToStandardError[] = $text;
    }
}

/**
 * Unit tests for daemon:status over the command channel (HIL-749).
 *
 * The three outcomes are the point of the move. A daemon that is not running still answers the
 * operator's question, so it prints its table and succeeds; a daemon that opened the socket and
 * then went quiet is a fault, and the HTTP door could not tell the two apart at all; a daemon
 * that refused says why, which beats a table of N/A that explains nothing.
 */
final class StatusCommandChannelTest extends TestCase
{
    private const string CORRELATION_ID = 'c0ffee00';

    public function testAnAnsweringDaemonDrawsItsFiguresAndSucceeds(): void
    {
        $command = new StatusCommandProbe(CommandChannelResult::replied(
            CommandReplyDTO::ok(self::CORRELATION_ID, new DaemonStatusDTO(
                uptime: 3661,
                memory: 2097152,
                cpu: 12.5,
                timestamp: 1786000000,
                workersRegular: 4,
                workersMonopolistic: 1,
                workersMaxRegular: 8,
            )->toArray()),
            StatusCommandProbe::ADDRESS,
        ));

        [$exitCode, $output] = $this->execute($command);

        $this->assertSame(ExitCode::SUCCESS, $exitCode);
        $this->assertStringContainsString(DaemonConstants::STATUS_ONLINE, $output);
        $this->assertStringContainsString('01:01:01', $output);
        $this->assertStringContainsString('12.5%', $output);
        $this->assertSame([], $command->printedToStandardError);
        // The wire name is the one already declared for this command, and the request is empty:
        // the master needs nothing from the caller to describe itself.
        $this->assertSame([['command' => CliCommands::DAEMON_STATUS, 'payload' => []]], $command->sent);
    }

    public function testASilentDaemonReadsAsNotRespondingAndFails(): void
    {
        $command = new StatusCommandProbe(CommandChannelResult::timedOut(StatusCommandProbe::ADDRESS));

        [$exitCode, $output] = $this->execute($command);

        $this->assertSame(ExitCode::ERROR, $exitCode);
        $this->assertStringContainsString(DaemonConstants::STATUS_NOT_RESPONDING, $output);
        $this->assertStringContainsString(DaemonConstants::VALUE_NOT_AVAILABLE, $output);
        $this->assertCount(1, $command->printedToStandardError);
        $this->assertStringContainsString(CliCommands::DAEMON_STATUS, $command->printedToStandardError[0]);
    }

    public function testAnAbsentDaemonReadsAsOfflineAndStillSucceeds(): void
    {
        // The zero is the contract: "the daemon is not running" answers the question this
        // command asks, and the scripts that call it branch on the table, not on the code.
        $command = new StatusCommandProbe(CommandChannelResult::unreachable(StatusCommandProbe::ADDRESS));

        [$exitCode, $output] = $this->execute($command);

        $this->assertSame(ExitCode::SUCCESS, $exitCode);
        $this->assertStringContainsString(DaemonConstants::STATUS_OFFLINE, $output);
        // The sentence is printed on this outcome too: the commonest reason for an empty status
        // is a channel address the operator did not expect, and this is where the address shows.
        $this->assertCount(1, $command->printedToStandardError);
        $this->assertStringContainsString(StatusCommandProbe::ADDRESS, $command->printedToStandardError[0]);
    }

    public function testARefusingDaemonIsRelayedInsteadOfDrawnAsATable(): void
    {
        $command = new StatusCommandProbe(CommandChannelResult::replied(
            CommandReplyDTO::error(self::CORRELATION_ID, 'This daemon reports no status'),
            StatusCommandProbe::ADDRESS,
        ));

        [$exitCode, $output] = $this->execute($command);

        $this->assertSame(ExitCode::ERROR, $exitCode);
        $this->assertStringNotContainsString(DaemonConstants::STATUS_OFFLINE, $output);
        $this->assertSame(['Refused: This daemon reports no status'], $command->printedToStandardError);
    }

    /**
     * Runs the command with stdout captured.
     *
     * @param StatusCommandProbe $command Command double to execute
     * @return array{0: int, 1: string} Exit code and everything the command drew on stdout
     */
    private function execute(StatusCommandProbe $command): array
    {
        ob_start();
        $exitCode = $command->execute([], []);
        $output = (string)ob_get_clean();

        return [$exitCode, $output];
    }
}
