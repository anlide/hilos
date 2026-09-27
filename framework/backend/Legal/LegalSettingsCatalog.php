<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;

/** The legal settings fragment projects mount in their settings catalog. */
final class LegalSettingsCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Types, defaults and write rules by setting key */
    public static function getCatalog(): array
    {
        return [
            LegalSettings::CONSENT_FORM_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => LegalSettings::CONSENT_FORM_CHECKBOX,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => LegalConsentFormRule::class,
            ],
            LegalSettings::REFUSAL_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => LegalSettings::REFUSAL_FREEZE,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => LegalRefusalRule::class,
            ],
        ];
    }
}
