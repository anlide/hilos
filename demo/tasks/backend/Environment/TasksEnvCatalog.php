<?php

declare(strict_types=1);

namespace Demo\Tasks\Environment;

use Demo\Tasks\Constants\TasksEnvConstants;
use Demo\Tasks\Hilos;
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
 * with. Both client pairs default to empty, which leaves both providers out; a
 * deployment fills them in, and the test stand fills a fake pair aimed at its own
 * provider emulator. The backup block switches the
 * subsystem on and gives it every value its agent reads, the same values the chat demo
 * gives it: a demo that declares the feature and leaves it off, or leaves a key it reads
 * out of the catalog, carries a page whose actions cannot run. Everything else -
 * WebAuthn, anti-abuse, code channels - inherits the stub.
 */
final class TasksEnvCatalog implements CatalogProviderInterface
{
    /** Backup storage folder under the project data dir ({@see defaultBackupDir()}). */
    private const string BACKUP_STORAGE_DIR = 'backup';

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
            EnvConstants::BACKUP_DIR->name => self::stringEntry(self::defaultBackupDir(), emptyIsMissing: true),
            EnvConstants::BACKUP_ENABLED->name => self::boolEntry(true, emptyIsMissing: true),
            EnvConstants::BACKUP_CLI_ENTRY->name => self::stringEntry(
                '/app/backend/Bootstrap/cli.php',
                emptyIsMissing: true,
            ),
            EnvConstants::BACKUP_TIMEOUT->name => self::intEntry(1800, emptyIsMissing: true),
            EnvConstants::BACKUP_RESTORE_TIMEOUT->name => self::intEntry(3600, emptyIsMissing: true),
            EnvConstants::BACKUP_RETENTION_DAILY->name => self::intEntry(45, emptyIsMissing: true),
            EnvConstants::BACKUP_RETENTION_WEEKLY->name => self::intEntry(45, emptyIsMissing: true),
            EnvConstants::BACKUP_RETENTION_MONTHLY->name => self::intEntry(45, emptyIsMissing: true),
            EnvConstants::BACKUP_RETENTION_YEARLY->name => self::intEntry(45, emptyIsMissing: true),
            EnvConstants::BACKUP_ERROR_RETENTION_COUNT->name => self::intEntry(20, emptyIsMissing: true),
            EnvConstants::BACKUP_MAX_TOTAL_BYTES->name => self::intEntry(0, emptyIsMissing: true),
            EnvConstants::BACKUP_SPACE_MARGIN->name => self::floatEntry(1.5, emptyIsMissing: true),
            EnvConstants::BACKUP_MIN_FREE_BYTES->name => self::intEntry(1073741824, emptyIsMissing: true),
            EnvConstants::BACKUP_REFUSE_WITHOUT_ESTIMATE->name => self::boolEntry(false, emptyIsMissing: true),
        ]);
    }

    /**
     * Project-relative backup storage root: demo/tasks/data/backup.
     *
     * Computed rather than left to the deployment, so the activation is complete on its own: an
     * unset BACKUP_DIR would disable storage, and the whole subsystem would sit enabled but unable
     * to write a single archive. `BACKUP_DIR` stays an override for a deployment that stores
     * backups elsewhere.
     *
     * @return string Absolute path to the default backup root
     */
    private static function defaultBackupDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . Hilos::DATA_DIR . DIRECTORY_SEPARATOR . self::BACKUP_STORAGE_DIR;
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
     * @param int $default Default value
     * @param bool $emptyIsMissing Whether empty values fall back to the default
     * @return array<string, mixed> Catalog entry for an integer-typed variable
     */
    private static function intEntry(int $default, bool $emptyIsMissing = false): array
    {
        return [
            EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_INTEGER,
            EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $default,
            EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => $emptyIsMissing,
            EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => false,
        ];
    }

    /**
     * @param float $default Default value
     * @param bool $emptyIsMissing Whether empty values fall back to the default
     * @return array<string, mixed> Catalog entry for a float-typed variable
     */
    private static function floatEntry(float $default, bool $emptyIsMissing = false): array
    {
        return [
            EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_FLOAT,
            EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $default,
            EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => $emptyIsMissing,
            EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => false,
        ];
    }
}
