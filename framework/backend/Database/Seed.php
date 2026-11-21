<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\File;
use Hilos\Environment\Exception\EnvException;
use Hilos\Files\ContentHash;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Idempotent seed runner — the SQL of a seed and the files it carries — blocked when APP_ENV
 * is PROD or STAGING.
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
     * @throws EnvException When the environment refuses to answer for APP_ENV
     */
    public static function isProduction(): bool
    {
        $env = Hilos::$env[EnvConstants::APP_ENV]->string();
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
     * @throws DatabaseException When production-like env, seed not found, read fails, SQL fails, or its files are refused
     * @throws EnvException When the environment refuses to answer for APP_ENV
     * @throws HilosException When the seed carries files and the files registry door cannot be opened
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

        try {
            $content = FsPath::read($targetFile);
        } catch (FsException $failure) {
            throw new DatabaseException("Failed to read seed file: {$targetFile}", 0, $failure);
        }

        self::runSqlWithDelimiter($content);
        self::storeFiles($targetFile);

        return true;
    }

    /**
     * @return int Number of seed files applied
     * @throws DatabaseException When production-like env, read fails, SQL fails, or a seed's files are refused
     * @throws EnvException When the environment refuses to answer for APP_ENV
     * @throws HilosException When a seed carries files and the files registry door cannot be opened
     */
    public static function apply(): int
    {
        if (self::isProduction()) {
            throw new DatabaseException('Seeds are disabled when APP_ENV is PROD or STAGING');
        }

        $seeds = self::getAvailableSeeds();
        $applied = 0;

        foreach ($seeds as $file) {
            try {
                $content = FsPath::read($file);
            } catch (FsException $failure) {
                throw new DatabaseException("Failed to read seed file: {$file}", 0, $failure);
            }

            self::runSqlWithDelimiter($content);
            self::storeFiles($file);
            $applied++;
        }

        return $applied;
    }

    /**
     * Puts the files a seed carries into the files storage, after its SQL wrote their registry rows.
     *
     * The files lie in a directory beside the SQL file, named like it without `.sql`
     * (`Seed/001_catalog.sql`, `Seed/001_catalog/`); a seed without the directory carries none
     * and this does nothing. Only the plain files right in the directory are carried, in name
     * order: a name starting with a dot is skipped, a symbolic link is skipped, and a
     * subdirectory is not read. Each file is kept under its own name, which is the
     * `stored_name` of its row; a file already kept under that name is replaced, so the seed
     * can be run again.
     *
     * The bytes take the way of the files registry door — a temporary file handed to its
     * storage — rather than a copy into a directory, so a project that keeps its files elsewhere
     * gets the seed's there too. The door is opened here because `test:db:reset` never passes
     * the full init. Seeds run only while the daemon is down, so nothing races the files library
     * for the storage.
     *
     * @param string $seedFile Path of the seed's SQL file
     * @throws DatabaseException When the project does not declare FILES, a file has no row of its name or disagrees with
     *     its size or hash, or a file cannot be read or kept
     * @throws HilosException When the files registry door cannot be opened
     */
    private static function storeFiles(string $seedFile): void
    {
        $seed = pathinfo($seedFile, PATHINFO_FILENAME);
        $directory = dirname($seedFile) . DIRECTORY_SEPARATOR . $seed;
        if (!is_dir($directory)) {
            return;
        }

        if (!Hilos::hasFeature(HilosFeature::FILES)) {
            throw new DatabaseException("Seed {$seed} carries files, but the project does not declare FILES");
        }

        Hilos::appClass()::initFiles();
        try {
            $names = FsPath::entries($directory);
        } catch (FsException $failure) {
            throw new DatabaseException("Failed to read seed directory: {$directory}", 0, $failure);
        }

        foreach ($names as $name) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (!str_starts_with($name, '.') && !is_link($path) && is_file($path)) {
                self::storeFile($seed, $name, $path);
            }
        }
    }

    /**
     * Checks one carried file against its registry row, then keeps it in the files storage.
     *
     * @param string $seed Seed name, the SQL file's name without `.sql`
     * @param string $name File name, the stored name of its row
     * @param string $path Path of the file in the seed's directory
     * @throws DatabaseException When the file has no row, disagrees with it, or cannot be read or kept
     * @throws LogicException When the files registry door is not created
     */
    private static function storeFile(string $seed, string $name, string $path): void
    {
        $row = Database::sql(
            'SELECT `' . File::size . '`, `' . File::content_hash . '` FROM `' . File::_table . '` WHERE `' . File::stored_name
                . '` = ? LIMIT 1',
            [$name],
        )->firstRow();
        if ($row === null) {
            throw new DatabaseException("Seed {$seed} carries {$name}, but no hilos_file row is stored under that name");
        }

        try {
            $size = FsPath::size($path);
            if ($size !== (int)$row[File::size]) {
                throw new DatabaseException("Seed {$seed} carries {$name} of {$size} bytes, but its hilos_file row says {$row[File::size]}");
            }

            $hash = ContentHash::ofFile($path);
            if ($hash !== $row[File::content_hash]) {
                throw new DatabaseException(
                    "Seed {$seed} carries {$name} with sha256 {$hash}, but its hilos_file row says {$row[File::content_hash]}",
                );
            }

            $bytes = FsPath::read($path);
        } catch (FsException $failure) {
            throw new DatabaseException("Failed to read seed file: {$path}", 0, $failure);
        }

        try {
            $tmp = (Hilos::$fs ?? throw new DirectoryNotFoundException('FS context is not configured'))->getTmp();
            $index = $tmp->create();
            $tmp[$index]->append($bytes);
            (Hilos::$files ?? throw new LogicException('The files door is not created'))->storage->storeFromTmp($name, $index);
        } catch (FsException $failure) {
            throw new DatabaseException("Seed {$seed} cannot put {$name} into the files storage", 0, $failure);
        }
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
