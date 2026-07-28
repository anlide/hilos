<?php

declare(strict_types=1);

namespace Hilos\Tables\Communications;

use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the framework delivery-logs table (HIL-201).
 *
 * One row is one channel delivery of one notification, projected from a
 * hilos_notification_delivery row joined to its hilos_notification (type, title,
 * recipient). The journal is admin-facing and read-only; the only row action is
 * "retry" on a {@see \Hilos\Notification\Delivery\DeliveryStatus::FAILED} row,
 * which the deliveries page owns.
 *
 * The delivery id rides the row fragment's {@see rowKey}, never a field named `id`
 * inside the slot: a slot payload carrying `id` is ingested by the frontend
 * normalizer as an entity fragment and replaced with a reference, which would strip
 * every other field off this row ({@see \Hilos\Core\Table\Row\AbstractTableRow}).
 * The notification body is deliberately absent — the journal shows the title so an
 * admin can tell what failed to arrive, but the body may carry personal detail.
 */
final class HilosNotificationDeliveryTableRow extends AbstractTableRow
{
    /**
     * Payload key of the row identity (the delivery id).
     *
     * It rides the row fragment's `rowKey`, never a field inside the slot, so the
     * frontend normalizer keeps the row whole ({@see HilosNotificationDeliveryTableRow}).
     */
    public const string rowKey = 'rowKey';

    public const string createdAt = 'createdAt';
    public const string channel = 'channel';
    public const string status = 'status';
    public const string attempts = 'attempts';
    public const string deliveredAt = 'deliveredAt';
    public const string lastError = 'lastError';
    public const string userId = 'userId';
    public const string userLabel = 'userLabel';
    public const string notificationType = 'notificationType';
    public const string notificationTitle = 'notificationTitle';

    /**
     * @param int $rowKey Delivery id (stable table row key)
     * @param string $createdAt ISO-8601 row creation timestamp
     * @param string $channel Delivery channel name
     * @param string $status Delivery status (pending/sent/failed)
     * @param int $attempts Delivery attempts made
     * @param ?string $deliveredAt ISO-8601 delivered timestamp, or null when not sent
     * @param ?string $lastError Last failure detail, or null
     * @param ?int $userId Recipient user id, or null when the notification is gone
     * @param string $userLabel Recipient display label (empty when the project resolves none)
     * @param string $notificationType Notification machine type
     * @param string $notificationTitle Notification title
     */
    public function __construct(
        public int $rowKey,
        public string $createdAt,
        public string $channel,
        public string $status,
        public int $attempts,
        public ?string $deliveredAt,
        public ?string $lastError,
        public ?int $userId,
        public string $userLabel,
        public string $notificationType,
        public string $notificationTitle,
    ) {
    }

    /**
     * Returns the stable table row key (the delivery id).
     *
     * @return int Row key
     */
    public function getRowKey(): int
    {
        return $this->rowKey;
    }

    /**
     * Serializes the row to the delivery-logs table payload shape.
     *
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            // The key rides the payload under a name the normalizer ignores; `id` would make
            // the whole slot look like an entity fragment on the frontend.
            self::rowKey => $this->rowKey,
            self::createdAt => $this->createdAt,
            self::channel => $this->channel,
            self::status => $this->status,
            self::attempts => $this->attempts,
            self::deliveredAt => $this->deliveredAt,
            self::lastError => $this->lastError,
            self::userId => $this->userId,
            self::userLabel => $this->userLabel,
            self::notificationType => $this->notificationType,
            self::notificationTitle => $this->notificationTitle,
        ];
    }

    /**
     * Builds a delivery row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed delivery table row
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: (int) ($data[self::rowKey] ?? 0),
            createdAt: (string) ($data[self::createdAt] ?? ''),
            channel: (string) ($data[self::channel] ?? ''),
            status: (string) ($data[self::status] ?? ''),
            attempts: (int) ($data[self::attempts] ?? 0),
            deliveredAt: isset($data[self::deliveredAt]) ? (string) $data[self::deliveredAt] : null,
            lastError: isset($data[self::lastError]) ? (string) $data[self::lastError] : null,
            userId: isset($data[self::userId]) ? (int) $data[self::userId] : null,
            userLabel: (string) ($data[self::userLabel] ?? ''),
            notificationType: (string) ($data[self::notificationType] ?? ''),
            notificationTitle: (string) ($data[self::notificationTitle] ?? ''),
        );
    }
}
