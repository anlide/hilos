<?php

declare(strict_types=1);

namespace Demo\Polls\Environment;

use Demo\Polls\Constants\PollsEnvConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;

/**
 * Polls demo environment catalog.
 *
 * The framework stub default for DB_DATABASE is an empty string, so the demo
 * overrides it with its own database name. The OAuth block is the demo's own: the
 * framework has no opinion about which providers an application signs people in
 * with. Both client pairs default to empty, which leaves both providers out; a
 * deployment fills them in, and the test stand fills a fake pair aimed at its own
 * provider emulator. Everything else - WebAuthn, anti-abuse, code channels -
 * inherits the stub.
 */
final class PollsEnvCatalog implements CatalogProviderInterface
{
    /**
     * Returns the polls demo environment catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return array_replace(EnvCatalogStub::getCatalog(), [
            EnvConstants::DB_DATABASE->name => self::stringEntry('hilos-demo-polls', emptyIsMissing: true),
            PollsEnvConstants::OAUTH_STATE_SECRET => self::stringEntry(
                'dev-oauth-state-secret-change-me',
                emptyIsMissing: true,
            ),
            PollsEnvConstants::OAUTH_GITHUB_CLIENT_ID => self::stringEntry(''),
            PollsEnvConstants::OAUTH_GITHUB_CLIENT_SECRET => self::stringEntry(''),
            PollsEnvConstants::OAUTH_GOOGLE_CLIENT_ID => self::stringEntry(''),
            PollsEnvConstants::OAUTH_GOOGLE_CLIENT_SECRET => self::stringEntry(''),
            PollsEnvConstants::OAUTH_REDIRECT_URI => self::stringEntry(
                '/auth/callback',
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
