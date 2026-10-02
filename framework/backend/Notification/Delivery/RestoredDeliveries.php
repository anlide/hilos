<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Settles restored pending deliveries whose fate the backup cannot know (HIL-1135).
 *
 * A row pending in a snapshot may have gone out immediately afterward. Replaying it
 * days later could send it again, so restore marks it failed with an explicit reason;
 * a person decides whether to retry it in the delivery journal.
 */
final class RestoredDeliveries
{
    public const string UNKNOWN_FATE = 'Restored from a backup: whether it was sent is not known';

    /**
     * @return int Number of pending rows settled as failed on primary
     * @throws DatabaseException When primary cannot be reached or the update fails
     */
    public static function settlePending(): int
    {
        $callerIndex = Database::getCurrentIndex();
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            if (!Database::isConnected()) {
                Database::connect();
            }

            return Database::sql(
                'UPDATE `' . EntityNotificationDelivery::_table . '` SET `'
                . EntityNotificationDelivery::status . '` = ?, `'
                . EntityNotificationDelivery::last_error . '` = ?, `'
                . EntityNotificationDelivery::updated_at . '` = ? WHERE `'
                . EntityNotificationDelivery::status . '` = ?',
                [DeliveryStatus::FAILED, self::UNKNOWN_FATE, TimeHelper::getSqlDateTime(), DeliveryStatus::PENDING],
            )->affectedRows();
        } finally {
            Database::useConnection($callerIndex);
        }
    }
}
