<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandChannelClientTrait;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use PHPUnit\Framework\TestCase;

/**
 * Pins the two sentences a failed command-channel round-trip prints, and that they are two.
 *
 * Before HIL-728 there was one, "No reply from daemon (is it running?)", hand-copied into
 * twenty-five files. It asked the operator a question the CLI already knew the answer to, and
 * gave the same answer to two problems that are fixed differently: nothing listening on the
 * channel, and a daemon that heard the command and never replied. The copies also meant the
 * wording could drift file by file, which is what this test now makes impossible.
 */
final class CommandChannelFailureTextTest extends TestCase
{
    private const string ADDRESS = '127.0.0.1:8094';

    private const string COMMAND = 'test:backup:prune';

    public function testAnUnreachableChannelNamesTheAddressItTried(): void
    {
        $this->expectOutputString("Cannot reach the daemon command channel at 127.0.0.1:8094\n");

        $printer = new ChannelFailurePrinter();
        self::assertSame(
            ExitCode::ERROR,
            $printer->print(CommandChannelResult::unreachable(self::ADDRESS), self::COMMAND),
        );
    }

    public function testASilentDaemonNamesTheCommandAndTheBudgetItWaited(): void
    {
        $this->expectOutputString("The daemon did not answer test:backup:prune within 5s\n");

        $printer = new ChannelFailurePrinter();
        self::assertSame(
            ExitCode::ERROR,
            $printer->print(CommandChannelResult::timedOut(self::ADDRESS), self::COMMAND),
        );
    }
}

/**
 * Reaches the trait's protected printer, which is where both sentences live.
 */
final class ChannelFailurePrinter
{
    use CommandChannelClientTrait;

    /**
     * @param CommandChannelResult $result Failed round-trip to word
     * @param string $command Wire name that went unanswered
     * @return int Exit code the printer answers with
     */
    public function print(CommandChannelResult $result, string $command): int
    {
        return $this->printChannelFailure($result, $command);
    }
}
