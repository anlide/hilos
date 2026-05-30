<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Hilos;

/**
 * Idempotent SQL seed runner blocked when APP_ENV is PROD or STAGING.
 */
class Seed
{
    /** @var ?string Seed directory path (null when not configured) */
    private static ?string $seedPath = null;

    /**
     * @param string $path Absolute or relative path to .sql seed files
     */
    public static function setSeedPath(string $path): void
    {
        self::$seedPath = rtrim($path, '/\\');
    }

    /**
     * @return ?string Configured seed directory path
     */
    public static function getSeedPath(): ?string
    {
        return self::$seedPath;
    }

    /**
     * Useful when directory has .sql files that do not match NNN_name.sql.
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
     * Only files matching NNN_name.sql are included.
     *
     * @return list<string> Full paths to seed files sorted by numeric prefix
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
     * Unrecognized APP_ENV values default to DEV (seeds allowed).
     *
     * @return bool Whether APP_ENV is PROD or STAGING
     */
    public static function isProduction(): bool
    {
        $env = Hilos::$env[EnvConstants::APP_ENV];
        $appEnv = AppEnv::fromString($env);

        if ($appEnv === null) {
            return false;
        }

        return $appEnv->isProductionLike();
    }

    /**
     * Identifier can be numeric prefix (001) or full basename (001_some_name).
     *
     * @param string $seedId Seed identifier (numeric prefix or basename)
     * @return bool Whether seed was applied
     * @throws DatabaseException When production-like env, seed not found, read fails, or SQL fails
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
     * @return int Number of seed files applied
     * @throws DatabaseException When production-like env, read fails, or SQL fails
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
     * Handles DELIMITER statements for stored procedures and functions.
     *
     * @param string $content Raw SQL file content
     * @throws DatabaseException When SQL execution fails
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
