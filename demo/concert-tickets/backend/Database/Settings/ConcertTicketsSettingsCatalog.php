<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Database\Settings;

use Demo\ConcertTickets\Notification\ConcertTicketsDeliveryChannelRegistry;
use Hilos\Auth\AccountDeletion\AccountDeletionSettingsCatalog;
use Hilos\Auth\Impersonation\ImpersonationSettingsCatalog;
use Hilos\Auth\Method\AuthMethodSettingsCatalog;
use Hilos\Auth\OAuth\OAuthSettingsCatalog;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Auth\Verification\VerificationSweepSettingsCatalog;
use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Files\FilesSettingsCatalog;
use Hilos\Files\Upload\UploadsSettingsCatalog;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Notification\Delivery\DeliveryLogSettingsCatalog;
use Hilos\Theme\ThemeSettingsCatalog;

/**
 * Project examples, theme, Analytics, mail delivery, logs, sign-in, files and moderation settings.
 *
 * Every activated feature's own catalog is merged in here: the start reads this catalog, and a
 * key a section writes would become an orphan the moment it is saved if its catalog were not.
 */
final class ConcertTicketsSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Returns the settings catalog for this demo.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting name
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
            ChannelSettingsCatalog::entriesFor(ConcertTicketsDeliveryChannelRegistry::all()),
            [
                DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME) => [
                    SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                    SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                    SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => true,
                ],
            ],
            DeliveryLogSettingsCatalog::getCatalog(),
            LogSettingsCatalog::getCatalog(),
            ThemeSettingsCatalog::getCatalog(),
            AnalyticsSettingsCatalog::getCatalog(),
            OAuthSettingsCatalog::getCatalog(),
            AuthMethodSettingsCatalog::getCatalog(),
            SecondFactorSettingsCatalog::getCatalog(),
            StepUpSettingsCatalog::getCatalog(),
            ImpersonationSettingsCatalog::getCatalog(),
            AccountDeletionSettingsCatalog::getCatalog(),
            VerificationSweepSettingsCatalog::getCatalog(),
            FilesSettingsCatalog::getCatalog(),
            UploadsSettingsCatalog::getCatalog(),
            ModerationSettingsCatalog::getCatalog(),
        );
    }
}
