<?php

declare(strict_types=1);

namespace Hilos\Notification\DTO;

use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Notification\NotificationAction;

/**
 * NotificationMarkAllReadPayloadDTO - payload for the notification_mark_all_read action.
 *
 * The action carries no fields: the recipient is resolved from the subscribed
 * session, not the payload, so a client can never mark another user's
 * notifications read. Declared with the durable backend (HIL-102); mounted on the
 * notification-center page in HIL-195.
 */
final class NotificationMarkAllReadPayloadDTO extends ActionPayloadDTO
{
    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return NotificationAction::MARK_ALL_READ;
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
