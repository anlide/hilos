<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Exact test notification passed to its personal owner, with a direct command-channel reply. */
final class UserNotificationTestCleanupSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string notificationId = 'notificationId';
    public const string title = 'title';
    public const string correlationId = 'correlationId';

    /**
     * @param int $userId Recipient and addressed agent index
     * @param int $notificationId Exact notification primary key
     * @param string $title Exact title reserved before the test action
     * @param string $correlationId Original command request identity
     * @throws InvalidFormatException When an id is not positive or the command identity is empty
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $notificationId,
        public readonly string $title,
        public readonly string $correlationId,
    ) {
        if ($userId <= 0 || $notificationId <= 0 || $correlationId === '') {
            throw new InvalidFormatException('Notification cleanup needs positive ids and a command correlation id');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Exact cleanup request
     * @throws InvalidFormatException When the payload fields are missing, unexpected or invalid
     */
    public static function fromArray(array $data): static
    {
        if (array_diff(array_keys($data), [self::userId, self::notificationId, self::title, self::correlationId]) !== []) {
            throw new InvalidFormatException('Unexpected notification cleanup fields');
        }

        return new static(
            userId: self::requireInt($data, self::userId),
            notificationId: self::requireInt($data, self::notificationId),
            title: self::requireString($data, self::title),
            correlationId: self::requireString($data, self::correlationId),
        );
    }

    /** @return array<string, int|string> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::notificationId => $this->notificationId,
            self::title => $this->title,
            self::correlationId => $this->correlationId,
        ];
    }
}
