<?php

declare(strict_types=1);

namespace Hilos\Notification\DTO;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Notification\NotificationAction;

/**
 * NotificationMarkReadPayloadDTO - payload for the notification_mark_read action.
 *
 * Carries the id of the single notification the recipient marked read. Declared
 * with the durable backend (HIL-102); mounted on the notification-center page in
 * HIL-195.
 */
final class NotificationMarkReadPayloadDTO extends ActionPayloadDTO
{
    /** Payload key: id of the notification to mark read. */
    public const string id = 'id';

    /**
     * @param int $id Notification id to mark read
     */
    public function __construct(
        public readonly int $id,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return NotificationAction::MARK_READ;
    }

    /**
     * @return array<string, mixed> Data with the notification id
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Instance
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            id: (int)($inner[self::id] ?? 0),
        );
    }
}
