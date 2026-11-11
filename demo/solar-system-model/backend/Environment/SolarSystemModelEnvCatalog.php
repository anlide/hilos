<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Environment;

use Demo\SolarSystemModel\Constants\SolarSystemModelEnvConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;

/** Solar System Model database default and OAuth provider environment values. */
final class SolarSystemModelEnvCatalog implements CatalogProviderInterface
{
    /**
     * Returns the Solar System Model demo environment catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return array_replace(EnvCatalogStub::getCatalog(), [
            EnvConstants::DB_DATABASE->name => self::stringEntry('hilos-demo-solar-system-model', emptyIsMissing: true),
            SolarSystemModelEnvConstants::OAUTH_STATE_SECRET => self::stringEntry(
                'dev-oauth-state-secret-change-me',
                emptyIsMissing: true,
                sensitive: true,
            ),
            SolarSystemModelEnvConstants::OAUTH_GITHUB_CLIENT_ID => self::stringEntry(''),
            SolarSystemModelEnvConstants::OAUTH_GITHUB_CLIENT_SECRET => self::stringEntry('', sensitive: true),
            SolarSystemModelEnvConstants::OAUTH_GOOGLE_CLIENT_ID => self::stringEntry(''),
            SolarSystemModelEnvConstants::OAUTH_GOOGLE_CLIENT_SECRET => self::stringEntry('', sensitive: true),
            SolarSystemModelEnvConstants::OAUTH_REDIRECT_URI => self::stringEntry(
                '/auth/callback',
                emptyIsMissing: true,
            ),
        ]);
    }

    /**
     * @param string $default Default value
     * @param bool $emptyIsMissing Whether empty values fall back to defaults
     * @param bool $sensitive Whether the value must remain on this node
     * @return array<string, mixed> Catalog entry for a string-typed variable
     */
    private static function stringEntry(string $default, bool $emptyIsMissing = false, bool $sensitive = false): array
    {
        $entry = [
            EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
            EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $default,
            EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => $emptyIsMissing,
            EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => false,
        ];

        if ($sensitive) {
            $entry[EnvCatalogConstants::CATALOG_ENTRY_SENSITIVE] = true;
        }

        return $entry;
    }

}
