<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Utils\Env;

/**
 * Database seed management system.
 *
 * Runs SQL seed files from a configured directory.
 * Seeds are idempotent and safe to run multiple times.
 * Blocked when APP_ENV is PROD or STAGING (see AppEnv).
 */
class Seed
{
    /** @var ?string Seed directory path (null when not configured) */
    private static ?string $seedPath = null;

    /**
     * Set seed directory path.
     *
     * @param string $path Absolute or relative path to directory containing .sql seed files
     */
    public static function setSeedPath(string $path): void
    {
        self::$seedPath = rtrim($path, '/\\');
    }

    /**
     * Get configured seed directory path.
     *
     * @return ?string Path or null when not configured
     */
    public static function getSeedPath(): ?string
    {
        return self::$seedPath;
    }

    /**
     * Get basenames of .sql files that do not match seed pattern (NNN_name.sql).
     *
     * Useful to hint user when no valid seeds exist but directory has .sql files.
     *
     * @return list<string> Basenames of non-matching .sql files
     */
    public static function getUnmatchedSqlBasenames(): array
    {
        if (self::$seedPath === null || !is_dir(self::$seedPath)) {
            return [];
        }

        $files = glob(self::$seedPath . '/*.sql');
        if ($files === false) {
            return [];
        }

        $unmatched = [];
        foreach ($files as $file) {
            $basename = basename($file);
            if (!preg_match('/^\d+_/', $basename)) {
                $unmatched[] = $basename;
            }
        }

        return $unmatched;
    }

    /**
     * Get list of available seed files (sorted by numeric prefix).
     *
     * Only files matching pattern NNN_name.sql are included.
     *
     * @return list<string> Full paths to seed files
     */
    public static function getAvailableSeeds(): array
    {
        if (self::$seedPath === null || !is_dir(self::$seedPath)) {
            return [];
        }

        $files = glob(self::$seedPath . '/*.sql');
        if ($files === false) {
            return [];
        }

        $seeds = [];
        foreach ($files as $file) {
            $basename = basename($file);
            if (preg_match('/^(\d+)_/', $basename)) {
                $seeds[$basename] = $file;
            }
        }

        ksort($seeds);
        return array_values($seeds);
    }

    /**
     * Check if running in production-like environment (seeds are disabled).
     *
     * Seeds are disabled when APP_ENV is PROD or STAGING (AppEnv).
     * Unrecognized values default to DEV (seeds allowed).
     *
     * @return bool True when APP_ENV is PROD or STAGING
     */
    public static function isProduction(): bool
    {
        $env = Env::get(EnvConstants::APP_ENV, AppEnv::DEV->value);
        $appEnv = AppEnv::fromString($env);

        if ($appEnv === null) {
            return false;
        }

        return $appEnv->isProductionLike();
    }

    /**
     * Apply a single seed file by identifier.
     *
     * Identifier can be numeric prefix (e.g. "001") or full basename (e.g. "001_some_name").
     * Blocked when APP_ENV=production.
     *
     * @param string $seedId Seed identifier (numeric prefix or basename)
     * @return bool True if seed was applied
     * @throws DatabaseException If production, seed not found, file read fails, or SQL execution fails
     */
    public static function applyOne(string $seedId): bool
    {
        if (self::isProduction()) {
            throw new DatabaseException('Seeds are disabled when APP_ENV is PROD or STAGING');
        }

        $seedId = trim($seedId);
        if ($seedId === '') {
            throw new DatabaseException('Seed identifier is required');
        }

        $seeds = self::getAvailableSeeds();
        $targetFile = null;

        foreach ($seeds as $file) {
            $basename = basename($file);
            $nameWithoutExt = pathinfo($basename, PATHINFO_FILENAME);
            $prefix = preg_match('/^(\d+)_/', $basename, $m) ? $m[1] : null;

            if ($seedId === $basename || $seedId === $nameWithoutExt || $seedId === $prefix) {
                $targetFile = $file;
                break;
            }
        }

        if ($targetFile === null) {
            $available = array_map(static fn (string $f): string => basename($f), $seeds);
            $availableStr = $available !== [] ? ' Available: ' . implode(', ', $available) : ' No valid seed files found.';
            throw new DatabaseException("Seed not found: {$seedId}.{$availableStr}");
        }

        $content = file_get_contents($targetFile);
        if ($content === false) {
            throw new DatabaseException("Failed to read seed file: {$targetFile}");
        }

        self::runSqlWithDelimiter($content);

        return true;
    }

    /**
     * Apply all seed files from configured directory.
     *
     * Executes each .sql file in order (sorted by numeric prefix).
     * Blocked when APP_ENV=production.
     *
     * @return int Number of seed files applied
     * @throws DatabaseException If production, seed path not configured, file read fails, or SQL execution fails
     */
    public static function apply(): int
    {
        if (self::isProduction()) {
            throw new DatabaseException('Seeds are disabled when APP_ENV is PROD or STAGING');
        }

        $seeds = self::getAvailableSeeds();
        $applied = 0;

        foreach ($seeds as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                throw new DatabaseException("Failed to read seed file: {$file}");
            }

            self::runSqlWithDelimiter($content);
            $applied++;
        }

        return $applied;
    }

    /**
     * Run SQL content with custom delimiter support.
     *
     * Handles DELIMITER statements for stored procedures and functions.
     * Skips empty lines and comment lines (--, #).
     *
     * @param string $content Raw SQL file content
     * @throws DatabaseException If SQL execution fails
     */
    private static function runSqlWithDelimiter(string $content): void
    {
        $delimiter = ';';
        $lines = explode("\n", $content);
        $statement = '';

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments
            if (empty($line) || str_starts_with($line, '--') || str_starts_with($line, '#')) {
                continue;
            }

            // Check for DELIMITER change
            if (preg_match('/^DELIMITER\s+(.+)$/i', $line, $matches)) {
                $delimiter = trim($matches[1]);
                continue;
            }

            $statement .= $line . "\n";

            // Check if statement is complete
            if (str_ends_with(rtrim($line), $delimiter)) {
                // Remove delimiter from statement
                if ($delimiter !== ';') {
                    $statement = substr($statement, 0, -strlen($delimiter));
                } else {
                    $statement = rtrim($statement, ';');
                }

                $statement = trim($statement);

                if (!empty($statement)) {
                    Database::sql($statement);
                }

                $statement = '';
            }
        }

        // Execute remaining statement
        $statement = trim($statement);
        if (!empty($statement)) {
            Database::sql($statement);
        }
    }
}
