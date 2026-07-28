<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification\Delivery;

use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the delivery-channel settings-catalog fragment (HIL-200).
 *
 * Locks the derivation from channel descriptors: one boolean enablement key plus one
 * empty-defaulted key per non-secret config field, with secret fields skipped (they
 * are env-only and never persisted). An empty registry yields an empty fragment.
 */
final class ChannelSettingsCatalogTest extends TestCase
{
    public function testEmptyRegistryYieldsEmptyFragment(): void
    {
        self::assertSame([], ChannelSettingsCatalog::entriesFor([]));
    }

    public function testDerivesEnablementAndNonSecretFieldKeys(): void
    {
        $catalog = ChannelSettingsCatalog::entriesFor([MailDeliveryChannel::NAME => new MailDeliveryChannel()]);

        $enabledKey = DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME);
        self::assertArrayHasKey($enabledKey, $catalog);
        self::assertSame(SettingsCatalogConstants::TYPE_BOOLEAN, $catalog[$enabledKey][SettingsCatalogConstants::CATALOG_ENTRY_TYPE]);
        self::assertFalse($catalog[$enabledKey][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]);

        $portKey = DeliveryChannelSettings::fieldKey(MailDeliveryChannel::NAME, MailDeliveryChannel::FIELD_SMTP_PORT);
        self::assertArrayHasKey($portKey, $catalog);
        self::assertSame(SettingsCatalogConstants::TYPE_INTEGER, $catalog[$portKey][SettingsCatalogConstants::CATALOG_ENTRY_TYPE]);
        // The catalog default is empty; the real default lives in env.
        self::assertSame(0, $catalog[$portKey][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]);

        $fromKey = DeliveryChannelSettings::fieldKey(MailDeliveryChannel::NAME, MailDeliveryChannel::FIELD_FROM_ADDRESS);
        self::assertArrayHasKey($fromKey, $catalog);
        self::assertSame('', $catalog[$fromKey][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]);
    }

    public function testSecretFieldsGetNoSettingsKey(): void
    {
        $catalog = ChannelSettingsCatalog::entriesFor([MailDeliveryChannel::NAME => new MailDeliveryChannel()]);

        $passwordKey = DeliveryChannelSettings::fieldKey(MailDeliveryChannel::NAME, MailDeliveryChannel::FIELD_SMTP_PASSWORD);
        $usernameKey = DeliveryChannelSettings::fieldKey(MailDeliveryChannel::NAME, MailDeliveryChannel::FIELD_SMTP_USERNAME);
        self::assertArrayNotHasKey($passwordKey, $catalog);
        self::assertArrayNotHasKey($usernameKey, $catalog);

        // 1 enablement + 6 non-secret fields, 2 secrets skipped.
        self::assertCount(7, $catalog);
    }
}
