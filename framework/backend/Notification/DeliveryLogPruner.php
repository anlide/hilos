<?php

declare(strict_types=1);

namespace Hilos\Notification;

use DateInterval;
use DateTimeImmutable;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Notification\Delivery\DeliveryStatus;

/**
 * DeliveryLogPruner - age-based auto-cleanup of the channel delivery journal (HIL-201).
 *
 * The delivery-logs table (hilos_notification_delivery) is the fastest-growing table
 * in the notifications subsystem, so it gets an owner: rows in a terminal state
 * (sent/failed) older than the retention window are deleted in bounded batches, and
 * the retention is a settings value ({@see RETENTION_SETTING_KEY}) defaulting to
 * {@see DEFAULT_RETENTION_DAYS} days. A retention of 0 (or less) means keep forever —
 * {@see prune()} is a no-op. Pending rows are NEVER deleted: an unfinished delivery
 * must survive to be driven to a terminal state.
 *
 * Only the technical delivery trace is removed; the notification itself lives on in
 * hilos_notification, so no user data is lost. This is silent cleanup by design
 * (unlike the operator-confirmed log model of HIL-381), because there is nothing a
 * human needs to review — a delivered/failed trace past its window is pure history.
 */
final class DeliveryLogPruner
{
    /** Settings key holding the journal retention window in days (0 = keep forever). */
    public const string RETENTION_SETTING_KEY = 'notifications.delivery_log.retention_days';

    /** Default retention window when the setting is unset. */
    public const int DEFAULT_RETENTION_DAYS = 90;

    /** Rows deleted per batch, bounding the delete transaction length. */
    private const int BATCH_SIZE = 1000;

    /**
     * Deletes terminal delivery rows older than the retention window, in batches.
     *
     * @param int $retentionDays Retention window in days (0 or less = keep forever, no-op)
     * @param DateTimeImmutable $now Reference "now" (injected so the decision is testable and clock-free)
     * @return int Number of rows deleted
     * @throws DatabaseException When a delete batch fails
     */
    public function prune(int $retentionDays, DateTimeImmutable $now): int
    {
        $cutoff = $this->cutoff($retentionDays, $now);
        if ($cutoff === null) {
            return 0;
        }

        $deleted = 0;
        do {
            Database::sql(
                'DELETE FROM `' . EntityNotificationDelivery::_table . '`'
                . ' WHERE `' . EntityNotificationDelivery::status . '` IN (?, ?)'
                . ' AND `' . EntityNotificationDelivery::created_at . '` < ?'
                . ' LIMIT ' . self::BATCH_SIZE,
                [DeliveryStatus::SENT, DeliveryStatus::FAILED, $cutoff],
            );
            $affected = Database::affectedRows();
            $deleted += $affected;
        } while ($affected >= self::BATCH_SIZE);

        return $deleted;
    }

    /**
     * Computes the created_at cutoff for a retention window, or null when disabled.
     *
     * Pure (no I/O, no clock read): a retention of 0 or less disables cleanup and
     * yields null; otherwise the cutoff is `$now` minus the window, as a SQL datetime.
     *
     * @param int $retentionDays Retention window in days
     * @param DateTimeImmutable $now Reference "now"
     * @return ?string SQL datetime cutoff (rows strictly older are pruned), or null when disabled
     */
    public function cutoff(int $retentionDays, DateTimeImmutable $now): ?string
    {
        if ($retentionDays <= 0) {
            return null;
        }

        return $now->sub(new DateInterval("P{$retentionDays}D"))->format('Y-m-d H:i:s');
    }

    /**
     * The framework settings-catalog fragment for the journal retention key.
     *
     * A project folds this into its own settings catalog (the same way it folds the
     * channel fragment), so the retention key is framework-owned and present without
     * the project spelling it out.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by the retention setting key
     */
    public static function catalogFragment(): array
    {
        return [
            self::RETENTION_SETTING_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::DEFAULT_RETENTION_DAYS,
            ],
        ];
    }
}
