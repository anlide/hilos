<?php

declare(strict_types=1);

namespace Demo\Pmd\Environment;

use Demo\Pmd\Constants\PmdEnvConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;

/** PMD database default and OAuth provider environment values. */
final class PmdEnvCatalog implements CatalogProviderInterface
{
    /**
     * Returns the PMD demo environment catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return array_replace(EnvCatalogStub::getCatalog(), [
            EnvConstants::DB_DATABASE->name => self::stringEntry('hilos-demo-pmd', emptyIsMissing: true),
            PmdEnvConstants::OAUTH_STATE_SECRET => self::stringEntry(
                'dev-oauth-state-secret-change-me',
                emptyIsMissing: true,
                sensitive: true,
            ),
            PmdEnvConstants::OAUTH_GITHUB_CLIENT_ID => self::stringEntry(''),
            PmdEnvConstants::OAUTH_GITHUB_CLIENT_SECRET => self::stringEntry('', sensitive: true),
            PmdEnvConstants::OAUTH_GOOGLE_CLIENT_ID => self::stringEntry(''),
            PmdEnvConstants::OAUTH_GOOGLE_CLIENT_SECRET => self::stringEntry('', sensitive: true),
            PmdEnvConstants::OAUTH_REDIRECT_URI => self::stringEntry(
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
