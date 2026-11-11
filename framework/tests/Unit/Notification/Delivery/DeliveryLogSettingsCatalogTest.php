<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification\Delivery;

use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Notification\Delivery\DeliveryLogRetention;
use Hilos\Notification\Delivery\DeliveryLogSettingsCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the delivery-journal settings-catalog fragment (HIL-201).
 *
 * Locks the one entry a project folds in: the retention key, typed as an integer and defaulting
 * to the retention policy window. The feature registry names this class among what NOTIFICATION_DELIVERY
 * obliges a project to fold in, so what the key is called is part of that contract.
 */
final class DeliveryLogSettingsCatalogTest extends TestCase
{
    public function testExposesRetentionKeyWithDefault(): void
    {
        $fragment = DeliveryLogSettingsCatalog::getCatalog();

        self::assertArrayHasKey(DeliveryLogRetention::RETENTION_SETTING_KEY, $fragment);
        $entry = $fragment[DeliveryLogRetention::RETENTION_SETTING_KEY];
        self::assertSame(SettingsCatalogConstants::TYPE_INTEGER, $entry[SettingsCatalogConstants::CATALOG_ENTRY_TYPE]);
        self::assertSame(DeliveryLogRetention::DEFAULT_RETENTION_DAYS, $entry[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]);
    }
}
