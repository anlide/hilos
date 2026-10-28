<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/** Uniform guest mutation outcome carried on the existing correlated action ack. */
final class StatusSubscriptionReplyDTO extends ActionReplyDTO
{
    public const string status = 'status';
    public const string CHECK_INBOX = 'check_inbox';
    public const string SUBSCRIBED = 'subscribed';
    public const string UNSUBSCRIBED = 'unsubscribed';

    /**
     * @param string $status Approved guest subscription outcome
     * @throws InvalidFormatException When the outcome is outside the guest contract
     */
    public function __construct(public readonly string $status)
    {
        if (!in_array($status, [self::CHECK_INBOX, self::SUBSCRIBED, self::UNSUBSCRIBED], true)) {
            throw new InvalidFormatException('Invalid subscription outcome');
        }
    }

    /** @return array<string, string> Outcome only, without recipient or link data */
    public function toArray(): array
    {
        return [self::status => $this->status];
    }

    /**
     * @param array<string, mixed> $data Serialized action reply
     * @return static Restored guest outcome
     * @throws InvalidFormatException When the status is missing, mistyped or unknown
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::status));
    }
}
