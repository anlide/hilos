<?php

declare(strict_types=1);

namespace Hilos\Files;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Files\Library\AbstractFilesLibraryAgent;

/**
 * FilesSettingsCatalog - the framework settings-catalog fragment of the files registry (HIL-336).
 *
 * One entry: how long a published file nobody has linked is kept before
 * {@see AbstractFilesLibraryAgent} removes it. A setting rather than a constant because the
 * window between publishing and linking is the project's: milliseconds for a chat message,
 * hours for a draft. A project folds this into its own catalog with `array_replace(...)`, and
 * {@see HilosFeature::FILES} lists the class among its required fragments, so startup refuses a
 * project that declared the feature and forgot the fold.
 */
final class FilesSettingsCatalog implements CatalogProviderInterface
{
    /** Setting key: hours an unbound file is kept; zero or less switches the janitor off. */
    public const string UNBOUND_TTL_HOURS_KEY = 'files.unbound_ttl_hours';

    /** Hours an unbound file is kept when the setting was never changed. */
    public const int DEFAULT_UNBOUND_TTL_HOURS = 24;

    /**
     * Builds the unbound-file lifetime entry.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by the setting key
     */
    public static function getCatalog(): array
    {
        return [
            self::UNBOUND_TTL_HOURS_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::DEFAULT_UNBOUND_TTL_HOURS,
            ],
        ];
    }
}
