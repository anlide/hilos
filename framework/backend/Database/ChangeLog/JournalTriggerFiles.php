<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

use Hilos\Database\DatabaseException;
use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsPath;

/** The project-owned directory of generated service trigger files. */
final class JournalTriggerFiles
{
    private const string FILE_SUFFIX = '.sql';
    private static ?string $path = null;

    /**
     * @param string $path Project Database/Migration/Triggers directory
     */
    public static function setPath(string $path): void
    {
        self::$path = rtrim($path, '/\\');
    }

    /**
     * @return list<string> Table names found in existing service trigger filenames
     * @throws DatabaseException When no project path is configured
     * @throws FileReadException When the directory cannot be listed
     * @throws DirectoryNotFoundException When it vanishes before listing
     */
    public static function existingTables(): array
    {
        $path = self::path();
        if (!is_dir($path)) {
            return [];
        }

        $tables = [];
        foreach (FsPath::entries($path) as $entry) {
            if (preg_match('/\Ahilos_cl_([A-Za-z_][A-Za-z0-9_]*)_after_(insert|update|delete)\.sql\z/D', $entry, $matches)) {
                $tables[$matches[1]] = true;
            }
        }
        $names = array_keys($tables);
        sort($names);
        return $names;
    }

    /**
     * Compare the whole rendered plan before changing any file. An unchanged SQL body
     * retains its older valid-from header, so startup drift checks can reproduce it.
     *
     * @param list<JournalTriggerFile> $files Complete rendered plan
     * @return list<string> Written filenames, sorted as the plan gives them
     * @throws DatabaseException When a file has an invalid header or no path is configured
     * @throws DirectoryCreateException When the project directory cannot be created
     * @throws FileReadException When an existing file cannot be read
     * @throws FileNotFoundException When an existing file vanishes during comparison
     * @throws FileWriteException When a temporary file cannot be written
     * @throws FileMoveException When a temporary file cannot be published
     * @throws FileDeleteException When failed temporary output cannot be removed
     */
    public static function write(array $files): array
    {
        $path = self::path();
        $pending = [];
        foreach ($files as $file) {
            $name = $file->name . self::FILE_SUFFIX;
            if (preg_match('/\Ahilos_cl_[A-Za-z_][A-Za-z0-9_]*_after_(insert|update|delete)\.sql\z/D', $name) !== 1) {
                throw new DatabaseException("Invalid journal trigger filename {$name}");
            }
            $target = $path . '/' . $name;
            if (is_file($target)) {
                $existing = FsPath::read($target);
                if (preg_match('/\A-- valid from migration #[0-9]+\n(.*)\z/sD', $existing, $matches) !== 1) {
                    throw new DatabaseException("Invalid journal trigger header in {$name}");
                }
                if ($matches[1] === $file->body . "\n") {
                    continue;
                }
            }
            $pending[$name] = $file->content();
        }

        if ($pending === []) {
            return [];
        }
        FsPath::ensureDirectory($path);
        foreach ($pending as $name => $content) {
            $target = $path . '/' . $name;
            $temporary = $target . '.tmp.' . getmypid();
            try {
                FsPath::write($temporary, $content);
                FsPath::publish($temporary, $target);
            } finally {
                FsPath::delete($temporary);
            }
        }

        return array_keys($pending);
    }

    /**
     * @return string Configured project trigger directory
     * @throws DatabaseException When the CLI did not configure its path
     */
    private static function path(): string
    {
        if (self::$path === null || self::$path === '') {
            throw new DatabaseException('Journal trigger file path is not configured');
        }
        return self::$path;
    }
}
