<?php

namespace Hilos\Database;

use Hilos\Utils\Helpers\TimeHelper;

/**
 * Database migration management system
 * Handles up/down migrations with SQL files
 */
class Migration
{
    private static ?string $migrationListPath = null;
    private static string $migrationName = 'main';
    private static ?string $routinesPath = null;
    private static bool $initialized = false;

    /**
     * Set migration list path
     */
    public static function setMigrationListPath(string $path): void
    {
        self::$migrationListPath = rtrim($path, '/\\');
    }

    /**
     * Set migration name (for multiple migration tracks)
     */
    public static function setMigrationName(string $name): void
    {
        self::$migrationName = $name;
    }

    /**
     * Set routines path
     */
    public static function setRoutinesPath(string $path): void
    {
        self::$routinesPath = rtrim($path, '/\\');
    }

    /**
     * Initialize migration system (create migration table if not exists)
     * @throws DatabaseException
     */
    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        try {
            // Check if table exists
            Database::sql('SELECT 1 FROM `migration` LIMIT 1');
            self::$initialized = true;
            return;
        } catch (DatabaseException $e) {
            // Table doesn't exist, create it
        }

        // Create migration table
        Database::sqlRun(
            'CREATE TABLE IF NOT EXISTS `migration` (
                `index` int(10) UNSIGNED NOT NULL,
                `failed` tinyint(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`index`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
        );

        self::$initialized = true;
    }

    /**
     * Get current migration index
     * @throws DatabaseException
     */
    public static function getCurrentIndex(): int
    {
        self::initialize();

        Database::sql('SELECT MAX(`index`) as max_index FROM `migration` WHERE `failed` = 0');
        $row = Database::row();

        return $row['max_index'] !== null ? (int)$row['max_index'] : 0;
    }

    /**
     * Get list of available migrations
     * 
     * @return array Array of migration indices
     */
    public static function getAvailableMigrations(): array
    {
        if (self::$migrationListPath === null) {
            return [];
        }

        $migrationPath = self::$migrationListPath . '/' . self::$migrationName;

        if (!is_dir($migrationPath)) {
            return [];
        }

        $migrations = [];
        $files = scandir($migrationPath);

        foreach ($files as $file) {
            // Match files like: 001_create_users.sql or 1_up.sql (but NOT 001_create_users_down.sql)
            if (preg_match('/^(\d+)_(?!.*_down).*\.sql$/', $file, $matches)) {
                $migrations[] = (int)$matches[1];
            }
        }

        sort($migrations);
        return array_unique($migrations);
    }

    /**
     * Migrate up to specific version
     *
     * @param ?int $targetIndex Target migration index (null = latest)
     * @return int Number of migrations applied
     * @throws DatabaseException
     */
    public static function migrateUp(?int $targetIndex = null): int
    {
        self::initialize();

        $currentIndex = self::getCurrentIndex();
        $availableMigrations = self::getAvailableMigrations();

        if ($targetIndex === null) {
            $targetIndex = !empty($availableMigrations) ? max($availableMigrations) : 0;
        }

        $applied = 0;

        foreach ($availableMigrations as $migrationIndex) {
            if ($migrationIndex <= $currentIndex) {
                continue; // Already applied
            }

            if ($migrationIndex > $targetIndex) {
                break; // Stop at target
            }

            self::applyMigrationUp($migrationIndex);
            $applied++;
        }

        return $applied;
    }

    /**
     * Migrate down to specific version
     *
     * @param int $targetIndex Target migration index
     * @return int Number of migrations rolled back
     * @throws DatabaseException
     */
    public static function migrateDown(int $targetIndex): int
    {
        self::initialize();

        $currentIndex = self::getCurrentIndex();
        $availableMigrations = self::getAvailableMigrations();

        $rolledBack = 0;

        // Rollback in reverse order
        rsort($availableMigrations);

        foreach ($availableMigrations as $migrationIndex) {
            if ($migrationIndex <= $targetIndex) {
                break; // Stop at target
            }

            if ($migrationIndex > $currentIndex) {
                continue; // Not applied yet
            }

            self::applyMigrationDown($migrationIndex);
            $rolledBack++;
        }

        return $rolledBack;
    }

    /**
     * Find migration file by index and type
     * Supports both formats: 1_up.sql and 001_create_users.sql
     */
    private static function findMigrationFile(int $index, string $type): ?string
    {
        $migrationPath = self::$migrationListPath . '/' . self::$migrationName;
        
        if (!is_dir($migrationPath)) {
            return null;
        }

        $files = scandir($migrationPath);
        
        // Pattern to match files starting with digits (with leading zeros)
        // For up: matches "001_something.sql" but not "001_something_down.sql"
        // For down: matches "001_something_down.sql"
        $pattern = $type === 'up' 
            ? '/^(\d+)_(?!.*_down).*\.sql$/'
            : '/^(\d+)_.*_down\.sql$/';

        foreach ($files as $file) {
            if (preg_match($pattern, $file, $matches)) {
                // Extract the numeric index from filename (handles leading zeros)
                $fileIndex = (int)$matches[1];
                if ($fileIndex === $index) {
                    return $migrationPath . '/' . $file;
                }
            }
        }

        return null;
    }

    /**
     * Apply single migration up
     * @throws DatabaseException
     */
    private static function applyMigrationUp(int $index): void
    {
        $upFile = self::findMigrationFile($index, 'up');

        if ($upFile === null || !file_exists($upFile)) {
            throw new DatabaseException("Migration file not found for index: {$index}");
        }

        $content = file_get_contents($upFile);
        if ($content === false) {
            throw new DatabaseException("Failed to read migration file: {$upFile}");
        }

        // Mark migration as started (failed)
        Database::sqlRun("INSERT INTO `migration` (`index`, `failed`) VALUES (?, 1)", [intval($index)]);

        try {
            // Execute migration
            self::runSqlWithDelimiter($content);

            // Mark as successful
            Database::sqlRun('UPDATE `migration` SET `failed` = 0 WHERE `index` = ?', [intval($index)]);
        } catch (DatabaseException $e) {
            // Migration failed, leave it marked as failed
            // Preserve MySQL error details from original exception
            $newException = new DatabaseException("Migration {$index} failed: " . $e->getMessage(), $e->getCode(), $e);
            $newException->setMysqlError($e->getMysqlErrorCode(), $e->getMysqlErrorMessage());
            if ($e->getQuery()) {
                $newException->setQuery($e->getQuery());
            }
            throw $newException;
        }
    }

    /**
     * Apply single migration down
     * @throws DatabaseException
     */
    private static function applyMigrationDown(int $index): void
    {
        $downFile = self::findMigrationFile($index, 'down');

        if ($downFile === null || !file_exists($downFile)) {
            throw new DatabaseException("Migration rollback file not found for index: {$index}");
        }

        $content = file_get_contents($downFile);
        if ($content === false) {
            throw new DatabaseException("Failed to read migration file: {$downFile}");
        }

        // Mark migration as failed (in case rollback fails)
        Database::sqlRun('UPDATE `migration` SET `failed` = 1 WHERE `index` = ?', [intval($index)]);

        try {
            // Execute rollback
            self::runSqlWithDelimiter($content);

            // Remove migration record
            Database::sqlRun('DELETE FROM `migration` WHERE `index` = ?', [intval($index)]);
        } catch (DatabaseException $e) {
            // Preserve MySQL error details from original exception
            $newException = new DatabaseException("Migration {$index} rollback failed: " . $e->getMessage(), $e->getCode(), $e);
            $newException->setMysqlError($e->getMysqlErrorCode(), $e->getMysqlErrorMessage());
            if ($e->getQuery()) {
                $newException->setQuery($e->getQuery());
            }
            throw $newException;
        }
    }

    /**
     * Run SQL with custom delimiter support
     * Handles DELIMITER statements for procedures/functions
     * @throws DatabaseException
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

    /**
     * Apply routines from routines directory
     * @throws DatabaseException
     */
    public static function applyRoutines(): void
    {
        if (self::$routinesPath === null || !is_dir(self::$routinesPath)) {
            return;
        }

        $files = glob(self::$routinesPath . '/*.sql');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            self::runSqlWithDelimiter($content);
        }
    }

    /**
     * Create new migration files
     * 
     * @param string $name Migration name/description
     * @return int New migration index
     * @throws DatabaseException If migration path is not configured
     */
    public static function create(string $name): int
    {
        if (self::$migrationListPath === null) {
            throw new DatabaseException("Migration path is not configured. Call Migration::setMigrationListPath() first.");
        }

        $availableMigrations = self::getAvailableMigrations();
        $newIndex = !empty($availableMigrations) ? max($availableMigrations) + 1 : 1;

        $migrationPath = self::$migrationListPath . '/' . self::$migrationName;

        // Create directory if not exists
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        $sanitizedName = preg_replace('/[^a-z0-9_-]/i', '_', $name);
        $timestamp = TimeHelper::getSqlDateTime();

        $upFile = $migrationPath . '/' . $newIndex . '_up.sql';
        $downFile = $migrationPath . '/' . $newIndex . '_down.sql';

        // Create up migration file
        $upContent = "-- Migration: {$sanitizedName}\n";
        $upContent .= "-- Created: {$timestamp}\n";
        $upContent .= "-- Index: {$newIndex}\n\n";
        $upContent .= "-- Add your UP migration SQL here\n\n";

        file_put_contents($upFile, $upContent);

        // Create down migration file
        $downContent = "-- Migration Rollback: {$sanitizedName}\n";
        $downContent .= "-- Created: {$timestamp}\n";
        $downContent .= "-- Index: {$newIndex}\n\n";
        $downContent .= "-- Add your DOWN migration SQL here\n\n";

        file_put_contents($downFile, $downContent);

        return $newIndex;
    }

    /**
     * Get migration status
     *
     * @return array Array with migration info
     * @throws DatabaseException
     */
    public static function getStatus(): array
    {
        self::initialize();

        $currentIndex = self::getCurrentIndex();
        $availableMigrations = self::getAvailableMigrations();
        $latestMigration = !empty($availableMigrations) ? max($availableMigrations) : 0;

        // Get failed migrations
        Database::sql('SELECT `index` FROM `migration` WHERE `failed` = 1 ORDER BY `index`');
        $failed = [];
        while ($row = Database::row()) {
            $failed[] = (int)$row['index'];
        }

        return [
            'current_index' => $currentIndex,
            'latest_available' => $latestMigration,
            'available_migrations' => $availableMigrations,
            'failed_migrations' => $failed,
            'pending_count' => $latestMigration - $currentIndex,
        ];
    }

    /**
     * Retry failed migration
     * @param int $index Migration index to retry
     * @throws DatabaseException
     */
    public static function retryFailed(int $index): void
    {
        Database::sql('SELECT `failed` FROM `migration` WHERE `index` = ?', [$index]);
        $row = Database::row();

        if ($row === null) {
            throw new DatabaseException("Migration {$index} not found in database");
        }

        if ((int)$row['failed'] === 0) {
            throw new DatabaseException("Migration {$index} is not failed");
        }

        // Mark for retry
        Database::sqlRun('DELETE FROM `migration` WHERE `index` = ?', [$index]);

        // Apply again
        self::applyMigrationUp($index);
    }
}

