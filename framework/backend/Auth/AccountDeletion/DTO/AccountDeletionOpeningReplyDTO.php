<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountDeletion\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/**
 * Opening answer of the account deletion window (HIL-302).
 *
 * The first step says how long the grace period is, and the second where the code went. An
 * account no code can reach answers a null channel, and the window starts the deletion from
 * its first step.
 */
final class AccountDeletionOpeningReplyDTO extends ActionReplyDTO
{
    public const string graceDays = 'graceDays';
    public const string channel = 'channel';
    public const string destination = 'destination';

    /** Channel value: the code goes to the account's confirmed email. */
    public const string CHANNEL_EMAIL = 'email';

    /** Channel value: the code goes to the account's confirmed phone. */
    public const string CHANNEL_PHONE = 'phone';

    /**
     * @param int $graceDays Days between the request and the erasure
     * @param ?string $channel CHANNEL_EMAIL or CHANNEL_PHONE, or null when no code can reach the account
     * @param ?string $destination Full address the code goes to - the person's own - or null with the channel
     */
    public function __construct(
        public readonly int $graceDays,
        public readonly ?string $channel,
        public readonly ?string $destination,
    ) {
    }

    /**
     * @return array{graceDays: int, channel: ?string, destination: ?string} Opening answer
     */
    public function toArray(): array
    {
        return [
            self::graceDays => $this->graceDays,
            self::channel => $this->channel,
            self::destination => $this->destination,
        ];
    }

    /**
     * @param array<string, mixed> $data Opening answer payload
     * @return static Restored answer
     * @throws InvalidFormatException When the grace period is absent or a member is mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireInt($data, self::graceDays),
            self::optionalString($data, self::channel),
            self::optionalString($data, self::destination),
        );
    }
}
