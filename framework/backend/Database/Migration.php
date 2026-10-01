<?php

namespace Hilos\Database;

use Hilos\AdminViewMode\AdminViewModeLatchTable;
use Hilos\AdminViewMode\AdminViewModeStartup;
use Hilos\Database\Exception\MigrationMarkedFailedException;
use Hilos\Database\Exception\MigrationNumberTakenTwiceException;
use Hilos\Database\Exception\MigrationSkippedBelowLevelException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SQL migration track management with up/down SQL files.
 */
class Migration
{
    /** @var ?string Path to migration list directory */
    private static ?string $migrationListPath = null;

    /** @var string Migration track name */
    private static string $migrationName = 'main';

    /** @var ?string Path to routines (stored procedures) directory */
    private static ?string $routinesPath = null;

    /**
     * Whether the migration table is ensured, keyed by database connection index.
     *
     * Per connection, not per process: the `migration` table lives inside each database,
     * so a once-per-process flag makes the second connection migrated in one process skip
     * initialize() and then fail in getCurrentIndex() on a table that was never created.
     * A restore migrating every connection it imported is the first caller to hit that.
     *
     * @var array<int, bool> Connection index => migration table ensured
     */
    private static array $initialized = [];

    /**
     * @param string $path Path to migration list directory
     */
    public static function setMigrationListPath(string $path): void
    {
        self::$migrationListPath = rtrim($path, '/\\');
    }

    /**
     * @param string $name Migration track name (e.g. main)
     */
    public static function setMigrationName(string $name): void
    {
        self::$migrationName = $name;
    }

    /**
     * @param string $path Path to routines directory
     */
    public static function setRoutinesPath(string $path): void
    {
        self::$routinesPath = rtrim($path, '/\\');
    }

    /**
     * Ensures the four framework tables every migrated database carries: `migration`, the
     * rollout claim's, the admin view mode latch's and the database marker's.
     *
     * The first two are what the migration track runs on. The latch is read by the start of a
     * daemon ({@see AdminViewModeStartup}), and so is the marker, by the start of a daemon in a
     * cluster ({@see DatabaseMarker}; docs/agents/architecture/daemon-lifecycle.md). Both live
     * here because a project's own migrations know nothing about them: a table the framework needs
     * on every database is created by the framework, the way the claim's is.
     *
     * Each is probed on its own: an installation migrated before the claim, the latch or the
     * marker existed already has `migration`, and stopping at it would leave the later tables
     * uncreated there.
     *
     * @throws DatabaseException When connection fails or a migration table cannot be created
     */
    public static function initialize(): void
    {
        $connectionIndex = Database::getCurrentIndex();
        if (self::$initialized[$connectionIndex] ?? false) {
            return;
        }

        self::ensureTable(
            'migration',
            'CREATE TABLE IF NOT EXISTS `migration` (
                `index` int(10) UNSIGNED NOT NULL,
                `failed` tinyint(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`index`)
            ) ' . DatabaseConnectionDefaults::DDL_TABLE_SUFFIX
        );
        self::ensureTable(
            MigrationClaim::TABLE,
            'CREATE TABLE IF NOT EXISTS `' . MigrationClaim::TABLE . '` (
                `id` tinyint(3) UNSIGNED NOT NULL,
                `holder` varchar(255) NOT NULL,
                `claimed_at` datetime NOT NULL,
                PRIMARY KEY (`id`)
            ) ' . DatabaseConnectionDefaults::DDL_TABLE_SUFFIX
        );
        self::ensureTable(
            AdminViewModeLatchTable::TABLE,
            'CREATE TABLE IF NOT EXISTS `' . AdminViewModeLatchTable::TABLE . '` (
                `id` tinyint(3) UNSIGNED NOT NULL,
                `environment` varchar(32) NOT NULL,
                `node` varchar(255) NOT NULL,
                `closed_at` int(10) UNSIGNED NOT NULL,
                PRIMARY KEY (`id`)
            ) ' . DatabaseConnectionDefaults::DDL_TABLE_SUFFIX
        );
        self::ensureTable(
            DatabaseMarker::TABLE,
            'CREATE TABLE IF NOT EXISTS `' . DatabaseMarker::TABLE . '` (
                `id` tinyint(3) UNSIGNED NOT NULL,
                `marker` char(32) NOT NULL,
                `written_by` varchar(255) NOT NULL,
                `written_at` datetime NOT NULL,
                PRIMARY KEY (`id`)
            ) ' . DatabaseConnectionDefaults::DDL_TABLE_SUFFIX
        );

        self::$initialized[$connectionIndex] = true;
    }

    /**
     * Creates a table only after the probe says it is not there, so an existing one sees no DDL.
     *
     * @param string $table Table to probe
     * @param string $createSql Statement creating it
     * @throws DatabaseException When the table is missing and cannot be created
     */
    private static function ensureTable(string $table, string $createSql): void
    {
        try {
            Database::sql(DatabaseSql::tableExistsProbe($table));
            return;
        } catch (DatabaseException) {
            // Table doesn't exist, create it
        }

        Database::sqlRun($createSql);
    }

    /**
     * @return int Last successfully applied migration index (0 when none)
     * @throws DatabaseException When migration table query fails
     */
    public static function getCurrentIndex(): int
    {
        self::initialize();

        Database::sql('SELECT MAX(`index`) as max_index FROM `migration` WHERE `failed` = 0');
        $row = Database::row();

        return $row['max_index'] !== null ? (int)$row['max_index'] : 0;
    }

    /**
     * Declares a migration level as applied without replaying anything.
     *
     * The write counterpart of {@see getCurrentIndex()}, and the only caller today is a restore
     * of a schema archive: such an archive brings the finished schema but an empty `migration`
     * table, so without these rows {@see migrateUp()} - here and at the next daemon startup, which
     * calls the same method - would replay the whole history over a schema that already has it.
     * A row is written for every migration file of the track up to the level, and for the level
     * itself: {@see refuseInconsistentTrack()} reads the list of rows, not only MAX(`index`), and one
     * row at the level would leave the restored database blind to a wrong number below it for good.
     *
     * Idempotent on purpose: an archive that carried no `migration` table at all leaves the
     * target's old rows in place, and failing over a duplicate key there would refuse a restore
     * that is otherwise correct.
     *
     * @param int $index Migration level to record as applied
     * @throws DatabaseException When the migration table cannot be created or the write fails
     */
    public static function recordAppliedLevel(int $index): void
    {
        self::initialize();

        $indices = array_filter(
            self::getAvailableMigrations(),
            static fn (int $migrationIndex): bool => $migrationIndex < $index,
        );
        $indices[] = $index;

        Database::sqlRun(
            'INSERT INTO `migration` (`index`, `failed`) VALUES '
            . implode(', ', array_fill(0, count($indices), '(?, 0)'))
            . ' ON DUPLICATE KEY UPDATE `failed` = 0',
            array_values($indices)
        );
    }

    /**
     * @return list<int> Sorted unique migration indices
     */
    public static function getAvailableMigrations(): array
    {
        return array_keys(self::upFilesByNumber(self::trackFileNames()));
    }

    /**
     * Refuses a track the database cannot follow: a number taken by more than one file, or a file
     * below the database's level that was never applied there (HIL-1238).
     *
     * Duplicates come first: while a number has two files, which of them the database counts as
     * applied is not defined, and a skip found on such a track would be a guess. The rows and the
     * level are read by one query, so a rollout by another holder in between cannot pair the rows
     * of one moment with the level of another. Takes no claim and writes nothing: a gap is a
     * property of the track and the database, and a rollout of the same track adds none, since it
     * writes its rows in order. What is below the lowest row is not judged - see
     * {@see MigrationTrackCheck::skipped()}.
     *
     * @throws DatabaseException When the migration table cannot be created or read
     * @throws MigrationNumberTakenTwiceException When a number of the track is taken by more than one file
     * @throws MigrationSkippedBelowLevelException When files below the database's level were never applied to it
     */
    public static function refuseInconsistentTrack(): void
    {
        self::initialize();

        $fileNames = self::trackFileNames();
        $takenTwice = MigrationTrackCheck::takenTwice($fileNames);
        if ($takenTwice !== []) {
            throw MigrationNumberTakenTwiceException::forNumbers(self::$migrationName, $takenTwice);
        }

        Database::sql('SELECT `index`, `failed` FROM `migration`');
        $recorded = [];
        $level = 0;
        while ($row = Database::row()) {
            $index = (int)$row['index'];
            $recorded[] = $index;
            if ((int)$row['failed'] === 0) {
                $level = max($level, $index);
            }
        }

        $upFiles = self::upFilesByNumber($fileNames);
        $skipped = MigrationTrackCheck::skipped(array_keys($upFiles), $recorded, $level);
        if ($skipped !== []) {
            $skippedFiles = array_merge(...array_map(static fn (int $number): array => $upFiles[$number], $skipped));
            sort($skippedFiles);
            throw MigrationSkippedBelowLevelException::forFiles(self::$migrationName, $skippedFiles, $level);
        }
    }

    /**
     * Lists the track directory in one pass, for the numbers, the lookup by number and the check alike.
     *
     * @return list<string> Entry names in scandir() order; empty when no list path is set or the track has no directory
     */
    private static function trackFileNames(): array
    {
        if (self::$migrationListPath === null) {
            return [];
        }

        $migrationPath = self::$migrationListPath . '/' . self::$migrationName;
        if (!is_dir($migrationPath)) {
            return [];
        }

        return scandir($migrationPath);
    }

    /**
     * @param list<string> $fileNames Entry names of the track directory
     * @return array<int, list<string>> Number => up files of that number in name order, numbers ascending
     */
    private static function upFilesByNumber(array $fileNames): array
    {
        $upFiles = [];
        foreach ($fileNames as $fileName) {
            if (preg_match(MigrationTrackCheck::UP_FILE_PATTERN, $fileName, $matches) === 1) {
                $upFiles[(int)$matches[1]][] = $fileName;
            }
        }
        ksort($upFiles);

        return $upFiles;
    }

    /**
     * Applies the pending migrations under the schema rollout claim ({@see MigrationClaim}).
     *
     * The level is read without the claim, and a database already at the target takes no claim
     * at all; otherwise the level is read again under the claim, because another holder may have
     * rolled the schema out while this one waited. The claim is given up on success and on
     * failure alike, so the next holder sees a failed migration at once instead of waiting.
     *
     * @param ?int $targetIndex Target migration index (null applies through latest)
     * @param ?MigrationClaimHolder $holder Who takes the claim; this process when null
     * @return int Number of migrations applied
     * @throws DatabaseException When migration file is missing, unreadable, or SQL fails
     * @throws EnvException When the holder name cannot read CLUSTER_NODE_ID
     * @throws MigrationMarkedFailedException When the next migration is marked failed by an earlier run
     * @throws MigrationNumberTakenTwiceException When a number of the track is taken by more than one file
     * @throws MigrationSkippedBelowLevelException When files below the database's level were never applied to it
     */
    public static function migrateUp(?int $targetIndex = null, ?MigrationClaimHolder $holder = null): int
    {
        self::initialize();
        self::refuseInconsistentTrack();

        $availableMigrations = self::getAvailableMigrations();

        if ($targetIndex === null) {
            $targetIndex = !empty($availableMigrations) ? max($availableMigrations) : 0;
        }

        if (self::pendingMigrations($availableMigrations, self::getCurrentIndex(), $targetIndex) === []) {
            return 0;
        }

        $holder ??= MigrationClaimHolder::process();
        MigrationClaim::take($holder);
        try {
            $applied = 0;
            foreach (self::pendingMigrations($availableMigrations, self::getCurrentIndex(), $targetIndex) as $migrationIndex) {
                self::refuseMarkedFailed($migrationIndex);
                self::applyMigrationUp($migrationIndex);
                $applied++;
            }

            return $applied;
        } finally {
            MigrationClaim::release($holder);
        }
    }

    /**
     * Rolls migrations back under the schema rollout claim ({@see MigrationClaim}), taken by this process.
     *
     * @param int $targetIndex Target migration index
     * @return int Number of migrations rolled back
     * @throws DatabaseException When rollback file is missing, unreadable, or SQL fails
     * @throws EnvException When the holder name cannot read CLUSTER_NODE_ID
     * @throws MigrationNumberTakenTwiceException When a number rolled back is taken by more than one down file
     */
    public static function migrateDown(int $targetIndex): int
    {
        self::initialize();

        $holder = MigrationClaimHolder::process();
        MigrationClaim::take($holder);
        try {
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
        } finally {
            MigrationClaim::release($holder);
        }
    }

    /**
     * @param list<int> $availableMigrations Sorted migration indices the track lists
     * @param int $currentIndex Level the database is at
     * @param int $targetIndex Level to stop at
     * @return list<int> Indices above the level and up to the target, in order
     */
    private static function pendingMigrations(array $availableMigrations, int $currentIndex, int $targetIndex): array
    {
        return array_values(array_filter(
            $availableMigrations,
            static fn (int $migrationIndex): bool => $migrationIndex > $currentIndex && $migrationIndex <= $targetIndex,
        ));
    }

    /**
     * Refuses a migration an earlier run left marked failed: its SQL may be half applied, and
     * running it again over that is the operator's decision (`db:migration:retry`), not a start's.
     *
     * @param int $index Migration about to be applied
     * @throws DatabaseException When the migration table cannot be read
     * @throws MigrationMarkedFailedException When the migration is marked failed
     */
    private static function refuseMarkedFailed(int $index): void
    {
        Database::sql('SELECT `index` FROM `migration` WHERE `index` = ? AND `failed` = 1', [$index]);
        if (Database::row() !== null) {
            throw MigrationMarkedFailedException::forIndex($index);
        }
    }

    /**
     * Supports both 1_up.sql and 001_create_users.sql naming.
     *
     * @param int $index Migration index
     * @param string $type Migration type (up or down)
     * @return ?string Full file path or null when not found
     * @throws MigrationNumberTakenTwiceException When the number has more than one file of the direction
     */
    private static function findMigrationFile(int $index, string $type): ?string
    {
        $fileNames = self::trackFileNames();
        $pattern = $type === 'up' ? MigrationTrackCheck::UP_FILE_PATTERN : MigrationTrackCheck::DOWN_FILE_PATTERN;

        $found = [];
        foreach ($fileNames as $fileName) {
            if (preg_match($pattern, $fileName, $matches) === 1 && (int)$matches[1] === $index) {
                $found[] = $fileName;
            }
        }

        if (count($found) > 1) {
            throw MigrationNumberTakenTwiceException::forNumbers(
                self::$migrationName,
                [$index => MigrationTrackCheck::takenTwice($fileNames)[$index]],
            );
        }

        return $found === [] ? null : self::$migrationListPath . '/' . self::$migrationName . '/' . $found[0];
    }

    /**
     * @param int $index Migration index
     * @throws DatabaseException When up file is missing, unreadable, or SQL fails
     * @throws MigrationNumberTakenTwiceException When the number has more than one up file
     */
    private static function applyMigrationUp(int $index): void
    {
        $upFile = self::findMigrationFile($index, 'up');

        if ($upFile === null || !file_exists($upFile)) {
            throw new DatabaseException("Migration file not found for index: {$index}");
        }

        try {
            $content = FsPath::read($upFile);
        } catch (FsException $failure) {
            throw new DatabaseException("Failed to read migration file: {$upFile}", 0, $failure);
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
     * @param int $index Migration index
     * @throws DatabaseException When down file is missing, unreadable, or SQL fails
     * @throws MigrationNumberTakenTwiceException When the number has more than one down file
     */
    private static function applyMigrationDown(int $index): void
    {
        $downFile = self::findMigrationFile($index, 'down');

        if ($downFile === null || !file_exists($downFile)) {
            throw new DatabaseException("Migration rollback file not found for index: {$index}");
        }

        try {
            $content = FsPath::read($downFile);
        } catch (FsException $failure) {
            throw new DatabaseException("Failed to read migration file: {$downFile}", 0, $failure);
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
     * Handles DELIMITER statements for stored procedures and functions.
     *
     * @param string $content Raw SQL content (may contain DELIMITER)
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

    /**
     * Executes each .sql file in the configured routines path.
     *
     * @throws DatabaseException When SQL execution fails
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
            try {
                $content = FsPath::read($file);
            } catch (FsException) {
                continue;
            }

            self::runSqlWithDelimiter($content);
        }
    }

    /**
     * @param string $name Migration name or description
     * @return int New migration index
     * @throws DatabaseException When migration path is not configured
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
     * @return array<string, mixed> current_index, latest_available, available_migrations, failed_migrations, pending_count
     * @throws DatabaseException When migration table query fails
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
     * Deletes failed record and re-applies migration up, under the schema rollout claim
     * ({@see MigrationClaim}) taken by this process.
     *
     * @param int $index Migration index to retry
     * @throws DatabaseException When migration is missing, not failed, or re-apply fails
     * @throws EnvException When the holder name cannot read CLUSTER_NODE_ID
     * @throws MigrationNumberTakenTwiceException When the number retried has more than one up file
     */
    public static function retryFailed(int $index): void
    {
        self::initialize();

        $holder = MigrationClaimHolder::process();
        MigrationClaim::take($holder);
        try {
            Database::sql('SELECT `failed` FROM `migration` WHERE `index` = ?', [$index]);
            $row = Database::row()
                ?? throw new DatabaseException("Migration {$index} not found in database");

            if ((int)$row['failed'] === 0) {
                throw new DatabaseException("Migration {$index} is not failed");
            }

            // Mark for retry
            Database::sqlRun('DELETE FROM `migration` WHERE `index` = ?', [$index]);

            // Apply again
            self::applyMigrationUp($index);
        } finally {
            MigrationClaim::release($holder);
        }
    }
}
