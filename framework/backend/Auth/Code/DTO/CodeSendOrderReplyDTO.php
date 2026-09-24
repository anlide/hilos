<?php

declare(strict_types=1);

namespace Hilos\Auth\Code\DTO;

use Hilos\Auth\Code\CodeSendTicket;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/**
 * CodeSendOrderReplyDTO - the answer an order for a phone code gives at once: which send it is
 * (HIL-1044).
 *
 * Not the outcome. The code travels across ticks in another process, and its ending arrives on
 * the send-progress line of the session, which every tab of it reads and which is replayed on a
 * reconnect. What the tab that asked needs from its own action is the name of its send, so that
 * the ending it waits for can be told from a line replayed about somebody else's: the frame of
 * the line carries the same {@see CodeSendTicket}.
 */
final class CodeSendOrderReplyDTO extends ActionReplyDTO
{
    /** Wire key for the ticket of the send the order opened. */
    public const string ticket = 'ticket';

    /**
     * @param string $ticket Ticket of the send the order opened
     */
    public function __construct(
        public readonly string $ticket,
    ) {
    }

    /**
     * @return array<string, string> Reply payload
     */
    public function toArray(): array
    {
        return [self::ticket => $this->ticket];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the reply names no send
     */
    public static function fromArray(array $data): static
    {
        return new static(ticket: self::requireString($data, self::ticket));
    }
}
