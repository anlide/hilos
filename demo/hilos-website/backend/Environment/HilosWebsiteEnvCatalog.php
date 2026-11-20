<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Environment;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;

/**
 * HilosWebsite demo environment catalog.
 *
 * The framework stub default for DB_DATABASE is an empty string, so the site
 * overrides it with its own database name. Everything else inherits the stub:
 * the auth throttle the site activates is on by default there, the owner signs
 * in by password and is mailed the codes of an added password and of a reset,
 * and the site declares no provider, no code channel and no backup.
 */
final class HilosWebsiteEnvCatalog implements CatalogProviderInterface
{
    /**
     * Returns the hilos-website demo environment catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return array_replace(EnvCatalogStub::getCatalog(), [
            EnvConstants::DB_DATABASE->name => self::stringEntry(
                'hilos-demo-hilos-website',
                emptyIsMissing: true,
            ),
        ]);
    }

    /**
     * @param string $default Default value
     * @param bool $emptyIsMissing Whether empty values fall back to defaults
     * @return array<string, mixed> Catalog entry for a string-typed variable
     */
    private static function stringEntry(string $default, bool $emptyIsMissing = false): array
    {
        return [
            EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
            EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $default,
            EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => $emptyIsMissing,
            EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => false,
        ];
    }
}
