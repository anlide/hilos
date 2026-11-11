<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use DateInterval;
use DateTimeImmutable;
use Hilos\Constants\TimeConstants;

/** Pure age policy for terminal delivery-journal rows. */
final class DeliveryLogRetention
{
    /** Settings key holding the journal retention window in days (0 = keep forever). */
    public const string RETENTION_SETTING_KEY = 'notifications.delivery_log.retention_days';

    /** Default retention window when the setting is unset. */
    public const int DEFAULT_RETENTION_DAYS = 90;

    /**
     * @param int $retentionDays Retention window in days; zero or less disables cleanup
     * @param DateTimeImmutable $now Reference "now" in UTC
     * @return ?int Epoch millisecond boundary, exclusive, or null when cleanup is disabled
     */
    public function cutoff(int $retentionDays, DateTimeImmutable $now): ?int
    {
        if ($retentionDays <= 0) {
            return null;
        }

        $moment = $now->sub(new DateInterval("P{$retentionDays}D"));

        return $moment->getTimestamp() * TimeConstants::MS_PER_SECOND + (int)$moment->format('v');
    }
}
