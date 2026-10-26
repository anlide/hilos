<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification\Delivery;

use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Frame\MailLetterFrame;
use Hilos\Mail\Frame\MailLetterFrameRule;
use Hilos\Mail\Frame\MailLetterFrameSettings;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
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

        // 1 enablement + 6 non-secret fields + 1 frame, 2 secrets skipped.
        self::assertCount(8, $catalog);
    }

    public function testFrameIsEmailOnlyAndHasAVisibleSharedRuleAndProjectDefault(): void
    {
        self::assertArrayNotHasKey(
            MailLetterFrameSettings::SETTING_KEY,
            ChannelSettingsCatalog::entriesFor(['sms' => new SmsDeliveryChannel()]),
        );
        foreach ([new MailDeliveryChannel(), new CatalogProjectMailChannel()] as $descriptor) {
            $entry = ChannelSettingsCatalog::entriesFor(['email' => $descriptor])[MailLetterFrameSettings::SETTING_KEY];
            self::assertSame('string', $entry[SettingsCatalogConstants::CATALOG_ENTRY_TYPE]);
            self::assertSame(MailLetterFrameRule::class, $entry[SettingsCatalogConstants::CATALOG_ENTRY_RULE]);
            self::assertTrue($entry[SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE]);
            self::assertSame(
                $descriptor->projectLetterFrame() ?? MailLetterFrame::DEFAULT_HTML,
                $entry[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE],
            );
            self::assertCount(8, $descriptor->configFields(), 'The frame does not add an operational field');
        }
    }
}

final class CatalogProjectMailChannel extends MailDeliveryChannel
{
    protected const ?string LETTER_FRAME = '<project>[[:content]]</project>';
}
