<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/** Password-change destination, or null members when no code can reach the account (HIL-300). */
final class ProfileChangePasswordOpeningReplyDTO extends ActionReplyDTO
{
    public const string channel = 'channel';
    public const string destination = 'destination';

    /** Channel value: the code goes to the account's confirmed email. */
    public const string CHANNEL_EMAIL = 'email';

    /** Channel value: the code goes to the account's confirmed phone. */
    public const string CHANNEL_PHONE = 'phone';

    /**
     * @param ?string $channel CHANNEL_EMAIL or CHANNEL_PHONE, or null when no code can reach the account
     * @param ?string $destination Full address the code goes to - the person's own - or null with the channel
     */
    public function __construct(
        public readonly ?string $channel,
        public readonly ?string $destination,
    ) {
    }

    /**
     * @return array{channel: ?string, destination: ?string} Opening answer
     */
    public function toArray(): array
    {
        return [
            self::channel => $this->channel,
            self::destination => $this->destination,
        ];
    }

    /**
     * @param array<string, mixed> $data Opening answer payload
     * @return static Restored answer
     * @throws InvalidFormatException When a destination member is mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::optionalString($data, self::channel),
            self::optionalString($data, self::destination),
        );
    }
}
