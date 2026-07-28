<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\NotificationPreferences as ObjectNotificationPreferences;
use Hilos\Database\View\Collection\NotificationPreferences as DbCollectionNotificationPreferences;
use Hilos\Database\View\Item\NotificationPreference;

/**
 * NotificationPreferencesActions - write operations for the NotificationPreferences collection.
 *
 * Backs the `profile_notification_channel_set` client action (HIL-485): applies a
 * recipient's opt in/out for one channel. The action is mounted on the profile
 * page, which resolves the acting user server-side from the connection, so a
 * client can only ever change its own preferences.
 *
 * @extends DbActions<NotificationPreference, ObjectNotificationPreferences>
 * @property-read DbCollectionNotificationPreferences $collection
 * @property-read ObjectNotificationPreferences $objectCollection
 */
final class NotificationPreferencesActions extends DbActions
{
    /**
     * Applies a recipient's opt in/out for one channel.
     *
     * @param int $userId Recipient user id
     * @param string $channel Channel name
     * @param bool $enabled True to allow the channel, false to mute it
     * @throws EmptyValueException When the channel name is empty
     * @throws DatabaseException When the write query fails
     */
    public function setChannel(int $userId, string $channel, bool $enabled): void
    {
        $this->objectCollection->setChannel($userId, $channel, $enabled);
    }

    /**
     * Removes every preference row of a recipient (account-deletion cleanup).
     *
     * @param int $userId Recipient user id
     * @throws DatabaseException When a delete query fails
     */
    public function deleteForUser(int $userId): void
    {
        $this->objectCollection->deleteForUser($userId);
    }
}
