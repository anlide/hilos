<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Environment;

use Demo\ConcertTickets\Constants\ConcertTicketsEnvConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;

/**
 * Concert Tickets demo environment catalog.
 *
 * The framework stub default for DB_DATABASE is an empty string, so the demo
 * overrides it with its own database name. Auth throttling defaults to off
 * because the demo does not activate AUTH_THROTTLE. The OAuth block is the demo's
 * own: the framework has no opinion about which providers an application signs
 * people in with. The GitHub pair defaults to empty, which leaves the provider out;
 * a deployment fills it in, and the test stand fills a fake pair aimed at its own
 * provider emulator. Everything else - WebAuthn, the code channel - inherits the stub.
 */
final class ConcertTicketsEnvCatalog implements CatalogProviderInterface
{
    /**
     * Returns the concert-tickets demo environment catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return array_replace(EnvCatalogStub::getCatalog(), [
            EnvConstants::DB_DATABASE->name => self::stringEntry(
                'hilos-demo-concert-tickets',
                emptyIsMissing: true,
            ),
            EnvConstants::HILOS_AUTH_THROTTLE_ENABLED->name => self::boolEntry(false, emptyIsMissing: true),
            ConcertTicketsEnvConstants::CONCERT_TICKETS_FILES_PUBLISHED_DIR => self::stringEntry(''),
            ConcertTicketsEnvConstants::OAUTH_STATE_SECRET => self::stringEntry(
                'dev-oauth-state-secret-change-me',
                emptyIsMissing: true,
                sensitive: true,
            ),
            ConcertTicketsEnvConstants::OAUTH_GITHUB_CLIENT_ID => self::stringEntry(''),
            ConcertTicketsEnvConstants::OAUTH_GITHUB_CLIENT_SECRET => self::stringEntry('', sensitive: true),
            ConcertTicketsEnvConstants::OAUTH_REDIRECT_URI => self::stringEntry(
                '/auth/callback',
                emptyIsMissing: true,
            ),
        ]);
    }

    /**
     * @param bool $default Default value
     * @param bool $emptyIsMissing Whether empty values fall back to the default
     * @return array<string, mixed> Catalog entry for a boolean-typed variable
     */
    private static function boolEntry(bool $default, bool $emptyIsMissing = false): array
    {
        return [
            EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_BOOLEAN,
            EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $default,
            EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => $emptyIsMissing,
            EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => false,
        ];
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
