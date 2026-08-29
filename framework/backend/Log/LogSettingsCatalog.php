<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\CronExpressionRule;
use Hilos\Database\Settings\Validation\NonNegativeIntegerRule;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * The framework settings-catalog fragment for log rotation and archive retention (HIL-760).
 *
 * Five values an administrator could not reach before: they lived in the environment and were
 * read once, at agent start. A project folds this fragment into its own catalog with
 * `array_replace(...)`, the same way it folds the delivery-channel fragment, and the five keys
 * appear in the settings table as ordinary rows.
 *
 * The environment stays the default under each key rather than a layer beside it: with no row
 * written, the key reads exactly what the node's env says, so an installation that configured
 * nothing keeps behaving as it did. Writing a row overrides that for every node of the cluster —
 * the database is shared, the environment is per node.
 *
 * Every key names the rule its values must pass ({@see SettingsCatalogConstants::CATALOG_ENTRY_RULE}),
 * so a schedule that would never fire, or a negative threshold, is refused at the point of writing.
 */
final class LogSettingsCatalog implements CatalogProviderInterface
{
    /** Elapsed seconds since the last rotation after which the live logs are rotated; 0 disables the axis. */
    public const string ROTATION_MAX_AGE_SECONDS = 'logs.rotation.max_age_seconds';

    /** Summed size in bytes of the live logs above which they are rotated; 0 disables the axis. */
    public const string ROTATION_MAX_LIVE_SIZE_BYTES = 'logs.rotation.max_live_size_bytes';

    /** Five-field cron expression the rotation runs on; empty disables the schedule axis. */
    public const string ROTATION_CRON = 'logs.rotation.cron';

    /** Newest archived batches always kept, whatever their age; 0 disables the count criterion. */
    public const string ARCHIVE_RETENTION_KEEP_BATCHES = 'logs.archive_retention.keep_batches';

    /** Age in seconds beyond which an archived batch becomes an eviction candidate; 0 disables it. */
    public const string ARCHIVE_RETENTION_MAX_AGE_SECONDS = 'logs.archive_retention.max_age_seconds';

    /**
     * Builds the log settings entries, each defaulting to its environment value.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return [
            self::ROTATION_MAX_AGE_SECONDS => self::integerEntry(
                self::envInt(EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS, 0),
            ),
            self::ROTATION_MAX_LIVE_SIZE_BYTES => self::integerEntry(
                self::envInt(EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES, 0),
            ),
            self::ROTATION_CRON => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::envString(
                    EnvConstants::LOG_ROTATION_CRON,
                    '',
                ),
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => CronExpressionRule::class,
            ],
            self::ARCHIVE_RETENTION_KEEP_BATCHES => self::integerEntry(
                self::envInt(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES, 20),
            ),
            self::ARCHIVE_RETENTION_MAX_AGE_SECONDS => self::integerEntry(
                self::envInt(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS, 2_592_000),
            ),
        ];
    }

    /**
     * Builds a catalog entry for one of the four numeric keys.
     *
     * @param int $default Default value, already read from the environment
     * @return array<string, mixed> Catalog entry
     */
    private static function integerEntry(int $default): array
    {
        return [
            SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
            SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $default,
            SettingsCatalogConstants::CATALOG_ENTRY_RULE => NonNegativeIntegerRule::class,
        ];
    }

    /**
     * Reads a numeric default from the environment, clamping negatives to 0 as the policies do.
     *
     * A project whose environment catalog does not carry the key at all falls back to the literal:
     * the fragment has to describe a complete key even where the environment says nothing.
     *
     * @param EnvConstants $key Environment variable backing this setting
     * @param int $fallback Value to use when the environment cannot answer
     * @return int Default value for the catalog entry
     */
    private static function envInt(EnvConstants $key, int $fallback): int
    {
        $env = Hilos::$env;
        if ($env === null) {
            return $fallback;
        }

        try {
            return max(0, $env->int($key));
        } catch (EnvException) {
            return $fallback;
        }
    }

    /**
     * Reads the schedule default from the environment.
     *
     * @param EnvConstants $key Environment variable backing this setting
     * @param string $fallback Value to use when the environment cannot answer (empty = schedule off)
     * @return string Default value for the catalog entry
     */
    private static function envString(EnvConstants $key, string $fallback): string
    {
        $env = Hilos::$env;
        if ($env === null) {
            return $fallback;
        }

        try {
            return $env->string($key);
        } catch (EnvException) {
            return $fallback;
        }
    }
}
