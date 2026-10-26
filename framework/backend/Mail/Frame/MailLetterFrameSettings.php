<?php

declare(strict_types=1);

namespace Hilos\Mail\Frame;

use Hilos\Database\Settings\SettingsCatalogConstants;

/** Durable installation-wide email appearance, written by the settings library. */
final class MailLetterFrameSettings
{
    public const string SETTING_KEY = 'notifications.channel.email.frame';
    public const string FIELD = 'frame';

    /**
     * @param ?string $projectFrame Registered email descriptor's frame, or null for the framework default
     * @return array<string, mixed> Settings catalog entry
     */
    public static function catalogEntry(?string $projectFrame): array
    {
        return [
            SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
            SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $projectFrame ?? MailLetterFrame::DEFAULT_HTML,
            SettingsCatalogConstants::CATALOG_ENTRY_RULE => MailLetterFrameRule::class,
            SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
        ];
    }
}
