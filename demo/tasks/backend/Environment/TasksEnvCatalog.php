<?php

declare(strict_types=1);

namespace Demo\Tasks\Environment;

use Demo\Tasks\Constants\TasksEnvConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;

/**
 * Tasks demo environment catalog.
 *
 * The framework stub default for DB_DATABASE is an empty string, so the demo
 * overrides it with its own database name. The OAuth block is the demo's own: the
 * framework has no opinion about which providers an application signs people in
 * with. Both client pairs default to empty, which is what selects the offline stub
 * provider in dev and e2e; a deployment fills them in. Everything else - WebAuthn,
 * anti-abuse, code channels - inherits the stub.
 */
final class TasksEnvCatalog implements CatalogProviderInterface
{
    /**
     * Returns the tasks demo environment catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return array_replace(EnvCatalogStub::getCatalog(), [
            EnvConstants::DB_DATABASE->name => self::stringEntry('hilos-demo-tasks', emptyIsMissing: true),
            TasksEnvConstants::OAUTH_STATE_SECRET => self::stringEntry(
                'dev-oauth-state-secret-change-me',
                emptyIsMissing: true,
            ),
            TasksEnvConstants::OAUTH_GITHUB_CLIENT_ID => self::stringEntry(''),
            TasksEnvConstants::OAUTH_GITHUB_CLIENT_SECRET => self::stringEntry(''),
            TasksEnvConstants::OAUTH_GOOGLE_CLIENT_ID => self::stringEntry(''),
            TasksEnvConstants::OAUTH_GOOGLE_CLIENT_SECRET => self::stringEntry(''),
            TasksEnvConstants::OAUTH_REDIRECT_URI => self::stringEntry(
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
