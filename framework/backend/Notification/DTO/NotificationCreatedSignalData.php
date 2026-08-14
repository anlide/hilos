<?php

declare(strict_types=1);

namespace Hilos\Notification\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\Object\Item\Notification as ObjectNotification;

/**
 * NotificationCreatedSignalData - server → client payload for NOTIFICATION_CREATED.
 *
 * Carries the freshly persisted notification so the recipient's connections can
 * prepend it to the list and increment the unread badge without a refetch. The
 * shape mirrors {@see ObjectNotification::toArray()} (data
 * decoded to structure, no server-only columns).
 */
final class NotificationCreatedSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: notification id. */
    public const string id = 'id';

    /** Payload key: recipient user id. */
    public const string userId = 'userId';

    /** Payload key: machine notification type. */
    public const string type = 'type';

    /** Payload key: severity level. */
    public const string severity = 'severity';

    /** Payload key: rendered title. */
    public const string title = 'title';

    /** Payload key: rendered body (nullable). */
    public const string body = 'body';

    /** Payload key: structured data (nullable). */
    public const string data = 'data';

    /** Payload key: read timestamp (null when unread). */
    public const string readAt = 'readAt';

    /** Payload key: creation timestamp. */
    public const string createdAt = 'createdAt';

    /**
     * @param int $id Notification id
     * @param int $userId Recipient user id
     * @param string $type Machine notification type
     * @param string $severity Severity level
     * @param string $title Rendered title
     * @param ?string $body Rendered body, or null
     * @param ?array<string, mixed> $data Structured data, or null
     * @param ?string $readAt Read timestamp, or null when unread
     * @param ?string $createdAt Creation timestamp
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $type,
        public readonly string $severity,
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?array $data = null,
        public readonly ?string $readAt = null,
        public readonly ?string $createdAt = null,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::userId => $this->userId,
            self::type => $this->type,
            self::severity => $this->severity,
            self::title => $this->title,
            self::body => $this->body,
            self::data => $this->data,
            self::readAt => $this->readAt,
            self::createdAt => $this->createdAt,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload is missing a field the row is addressed
     *                                and rendered by
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id: self::requireInt($data, self::id),
            userId: self::requireInt($data, self::userId),
            type: self::requireString($data, self::type),
            severity: self::requireString($data, self::severity),
            title: self::requireString($data, self::title),
            body: self::optionalString($data, self::body),
            data: self::optionalArray($data, self::data),
            readAt: self::optionalString($data, self::readAt),
            createdAt: self::optionalString($data, self::createdAt),
        );
    }
}
