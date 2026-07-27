<?php

declare(strict_types=1);

namespace Hilos\Notification\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * NotificationReadSignalData - server → client payload for NOTIFICATION_READ.
 *
 * Multi-device badge sync (HIL-102): tells the recipient's other connections that
 * one notification, or all of them, was marked read. A single id targets one row;
 * the {@see self::ALL} sentinel means every unread notification of the recipient
 * was cleared.
 */
final class NotificationReadSignalData extends BaseDTO implements SignalDataInterface
{
    /** Sentinel id meaning "all of the recipient's notifications". */
    public const string ALL = 'all';

    /** Payload key: the marked-read target (a notification id, or the "all" sentinel). */
    public const string id = 'id';

    /**
     * @param int|string $id Notification id, or {@see self::ALL} for a mark-all
     */
    public function __construct(
        public readonly int|string $id,
    ) {
    }

    /**
     * Builds a payload for a single marked-read notification.
     *
     * @param int $id Notification id
     * @return self Signal data for one row
     */
    public static function one(int $id): self
    {
        return new self($id);
    }

    /**
     * Builds a payload for a mark-all-read.
     *
     * @return self Signal data with the "all" sentinel
     */
    public static function all(): self
    {
        return new self(self::ALL);
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $id = $data[self::id] ?? self::ALL;

        return new static(
            id: $id === self::ALL ? self::ALL : (int)$id,
        );
    }
}
