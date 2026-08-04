<?php

declare(strict_types=1);

namespace Hilos\Notification\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Notification\NotificationSignalName;
use Hilos\Pages\AbstractHilosNotificationsPage;

/**
 * NotificationsSnapshotSignalData - server → client payload for the notification-center subscribe.
 *
 * The initial snapshot the {@see AbstractHilosNotificationsPage} sends
 * when a recipient's connection subscribes (HIL-195): the recent notifications
 * newest-first plus the unread badge count. After the snapshot the list stays
 * live off the per-user group signals {@see NotificationSignalName::CREATED}
 * / {@see NotificationSignalName::READ}, so this payload is
 * sent once per subscribe, not on every change. Each row reuses the
 * {@see NotificationCreatedSignalData} shape rather than a second row shape.
 */
final class NotificationsSnapshotSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: recent notifications, newest first. */
    public const string recent = 'recent';

    /** Payload key: number of unread notifications. */
    public const string unreadCount = 'unreadCount';

    /**
     * @param list<NotificationCreatedSignalData> $recent Recent notifications, newest first
     * @param int $unreadCount Number of unread notifications for the recipient
     */
    public function __construct(
        public readonly array $recent,
        public readonly int $unreadCount,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array (rows decoded to structure)
     */
    public function toArray(): array
    {
        return [
            self::recent => array_map(
                static fn (NotificationCreatedSignalData $row): array => $row->toArray(),
                $this->recent,
            ),
            self::unreadCount => $this->unreadCount,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $rawRecent = $data[self::recent] ?? [];
        $recent = [];
        if (is_array($rawRecent)) {
            foreach ($rawRecent as $row) {
                if (is_array($row)) {
                    $recent[] = NotificationCreatedSignalData::fromArray($row);
                }
            }
        }

        return new static(
            recent: $recent,
            unreadCount: (int)($data[self::unreadCount] ?? 0),
        );
    }
}
