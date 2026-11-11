<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Push delivery channel → the person's agent: a captured device endpoint is dead (HIL-1895).
 *
 * The frame travels outside a connection and is not answered. revision is the counter the channel
 * captured when it opened the send. The owner compares it with the current row, so a late report
 * leaves a renewed subscription alone.
 */
final class UserPushEndpointGoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string subscriptionId = 'subscriptionId';
    public const string revision = 'revision';

    /**
     * @param int $userId Person who owns the device, and the agent index
     * @param int $subscriptionId Captured subscription row id
     * @param int $revision Captured subscription revision
     * @throws InvalidFormatException When any id or revision is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $subscriptionId,
        public readonly int $revision,
    ) {
        if ($userId <= 0 || $subscriptionId <= 0 || $revision <= 0) {
            throw new InvalidFormatException('User and subscription ids and revision must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Captured dead-device report
     * @throws InvalidFormatException When a field has the wrong type or any value is not positive
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            subscriptionId: self::requireInt($data, self::subscriptionId),
            revision: self::requireInt($data, self::revision),
        );
    }

    /** @return array<string, int> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::subscriptionId => $this->subscriptionId,
            self::revision => $this->revision,
        ];
    }
}
