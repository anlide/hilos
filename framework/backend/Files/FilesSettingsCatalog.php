<?php

declare(strict_types=1);

namespace Hilos\Files;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Files\Library\AbstractFilesLibraryAgent;
use Hilos\Files\Upload\Check\StorageLimitCheck;

/**
 * FilesSettingsCatalog - the framework settings-catalog fragment of the files registry (HIL-336).
 *
 * Two entries. How long a published file nobody has linked is kept before
 * {@see AbstractFilesLibraryAgent} removes it - a setting rather than a constant because the
 * window between publishing and linking is the project's: milliseconds for a chat message,
 * hours for a draft. And how many bytes the whole storage may hold (HIL-136), which
 * {@see StorageLimitCheck} holds every declared upload to. A project folds this into its own catalog with `array_replace(...)`, and
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
     * Setting key: bytes the whole storage may hold - the registry's files together with the
     * uploads that hold a file; zero or less means no limit. Read on every declaration.
     */
    public const string MAX_TOTAL_BYTES_KEY = 'files.max_total_bytes';

    /** No limit: the framework does not know the size of the project's disk. */
    public const int DEFAULT_MAX_TOTAL_BYTES = 0;

    /**
     * Builds the unbound-file lifetime and storage limit entries.
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
            self::MAX_TOTAL_BYTES_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::DEFAULT_MAX_TOTAL_BYTES,
            ],
        ];
    }
}
