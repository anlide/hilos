<?php

declare(strict_types=1);

namespace Demo\Pmd\Database\Settings;

use Demo\Pmd\Notification\PmdDeliveryChannelRegistry;
use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\Auth\AccountDeletion\AccountDeletionSettingsCatalog;
use Hilos\Auth\Method\AuthMethodSettingsCatalog;
use Hilos\Auth\OAuth\OAuthSettingsCatalog;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Auth\Throttle\AuthThrottleSettingsCatalog;
use Hilos\Auth\Verification\VerificationSweepSettingsCatalog;
use Hilos\Auth\Impersonation\ImpersonationSettingsCatalog;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Files\FilesSettingsCatalog;
use Hilos\Files\Upload\UploadsSettingsCatalog;
use Hilos\Legal\LegalSettingsCatalog;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Notification\Delivery\DeliveryLogSettingsCatalog;
use Hilos\Theme\ThemeSettingsCatalog;

/** PMD settings, account policies and activated framework feature catalogs. */
final class PmdSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Returns the settings catalog for the PMD demo.
     *
     * @return array<string, array<string, mixed>> Setting definitions and defaults
     */
    public static function getCatalog(): array
    {
        return array_replace([
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => '',
            ],
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_INTEGER => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 0,
            ],
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_BOOLEAN => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => false,
            ],
        ],
            ChannelSettingsCatalog::entriesFor(PmdDeliveryChannelRegistry::all()),
            [
                DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME) => [
                    SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                    SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                    SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => true,
                ],
            ],
            DeliveryLogSettingsCatalog::getCatalog(),
            ThemeSettingsCatalog::getCatalog(),
            AnalyticsSettingsCatalog::getCatalog(),
            LegalSettingsCatalog::getCatalog(),
            OAuthSettingsCatalog::getCatalog(),
            AuthMethodSettingsCatalog::getCatalog(),
            SecondFactorSettingsCatalog::getCatalog(),
            StepUpSettingsCatalog::getCatalog(),
            ImpersonationSettingsCatalog::getCatalog(),
            AccountDeletionSettingsCatalog::getCatalog(),
            VerificationSweepSettingsCatalog::getCatalog(),
            AuthThrottleSettingsCatalog::getCatalog(),
            FilesSettingsCatalog::getCatalog(),
            UploadsSettingsCatalog::getCatalog(),
            ModerationSettingsCatalog::getCatalog(),
        );
    }
}
