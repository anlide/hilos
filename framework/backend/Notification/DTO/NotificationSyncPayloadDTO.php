<?php

declare(strict_types=1);

namespace Hilos\Notification\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Notification\NotificationAction;

/**
 * NotificationSyncPayloadDTO - payload for the notification_sync action.
 *
 * The action carries no fields: the recipient is resolved from the connection,
 * not the payload, so a client can only ever request its own snapshot. Mounted on
 * the notification-center page in HIL-195, whose handler replies the recent rows
 * plus the unread count under
 * {@see HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_NOTIFICATIONS}.
 */
final class NotificationSyncPayloadDTO extends ActionPayloadDTO
{
    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return NotificationAction::SYNC;
    }

    /**
     * @return array<string, mixed> Empty payload
     */
    public function toArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data Raw payload (ignored; no fields)
     * @return static Instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
