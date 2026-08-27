<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Socket\Command\DTO\CommandReplyDTO;

/**
 * CommandChannelResult - the outcome of one command-channel round-trip: a reply, or why not.
 *
 * {@see CommandChannelClientTrait::sendCommand()} used to answer `?CommandReplyDTO`, and null had
 * to stand for two different things at once - nothing listening, and nothing answered. Every
 * caller then worded that null itself, which is how one sentence came to be copied into
 * twenty-five files and how the copies drifted from the two facts underneath.
 *
 * Exactly one of {@see $reply} and {@see $failure} is set. The address is carried rather than
 * looked up again when the failure is printed: reading it back out of the environment is what
 * would let a print path throw, and by the time a result exists the address is already known.
 */
final readonly class CommandChannelResult
{
    /**
     * @param ?CommandReplyDTO $reply Reply from the daemon; null when the round-trip failed
     * @param ?CommandChannelFailure $failure Why no reply came; null when one did
     * @param string $address host:port the round-trip was addressed to
     */
    private function __construct(
        public ?CommandReplyDTO $reply,
        public ?CommandChannelFailure $failure,
        public string $address,
    ) {
    }

    /**
     * Reports a completed round-trip.
     *
     * @param CommandReplyDTO $reply Reply the daemon sent
     * @param string $address host:port the round-trip was addressed to
     * @return self Result carrying the reply
     */
    public static function replied(CommandReplyDTO $reply, string $address): self
    {
        return new self($reply, null, $address);
    }

    /**
     * Reports that nothing is listening on the command channel, or that the socket broke.
     *
     * @param string $address host:port the round-trip was addressed to
     * @return self Result carrying {@see CommandChannelFailure::UNREACHABLE}
     */
    public static function unreachable(string $address): self
    {
        return new self(null, CommandChannelFailure::UNREACHABLE, $address);
    }

    /**
     * Reports that the channel was reached but no reply arrived inside the budget.
     *
     * @param string $address host:port the round-trip was addressed to
     * @return self Result carrying {@see CommandChannelFailure::TIMEOUT}
     */
    public static function timedOut(string $address): self
    {
        return new self(null, CommandChannelFailure::TIMEOUT, $address);
    }
}
