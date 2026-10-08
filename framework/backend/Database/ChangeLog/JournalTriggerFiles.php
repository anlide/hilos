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
use Hilos\Fs\FsException;
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
     * Reads the migration number printed by one already validated service trigger file.
     *
     * @param string $triggerName Service trigger name without the .sql suffix
     * @return int Migration that first supplied this body
     * @throws DatabaseException When the file is missing, unreadable, or has an invalid header
     */
    public static function validFrom(string $triggerName): int
    {
        $name = $triggerName . self::FILE_SUFFIX;
        if (preg_match('/\Ahilos_cl_[A-Za-z_][A-Za-z0-9_]*_after_(insert|update|delete)\.sql\z/D', $name) !== 1) {
            throw new DatabaseException("Invalid journal trigger filename {$name}");
        }
        try {
            $content = FsPath::read(self::path() . '/' . $name);
        } catch (FsException $e) {
            throw new DatabaseException("Journal trigger {$name}: " . $e->getMessage(), previous: $e);
        }
        if (preg_match('/\A-- valid from migration #(0|[1-9][0-9]*)\n(.*)\n\z/sD', $content, $matches) !== 1) {
            throw new DatabaseException("Journal trigger {$name}: invalid migration header or file format");
        }
        $validFrom = filter_var($matches[1], FILTER_VALIDATE_INT);
        if ($validFrom === false) {
            throw new DatabaseException("Journal trigger {$name}: invalid migration number {$matches[1]}");
        }
        return $validFrom;
    }

    /**
     * Reads and validates the entire directory against the generator before any SQL is applied.
     * The migration header may be older than the current plan when the body is unchanged.
     *
     * @param list<JournalTriggerFile> $plan Complete canonical generator output
     * @param int $migrationIndex Highest applied migration
     * @return list<JournalTriggerFile> Validated files in generator order
     * @throws DatabaseException When a file is missing, extra, unreadable or differs from the generator
     */
    public static function readAll(array $plan, int $migrationIndex): array
    {
        $path = self::path();
        $expected = [];
        foreach ($plan as $file) {
            $expected[$file->name . self::FILE_SUFFIX] = $file;
        }

        try {
            $entries = FsPath::entries($path);
        } catch (FsException $e) {
            throw new DatabaseException("Journal trigger directory {$path}: " . $e->getMessage(), previous: $e);
        }
        $problems = [];
        $files = [];
        foreach ($entries as $entry) {
            if (!str_ends_with(strtolower($entry), self::FILE_SUFFIX)) {
                continue;
            }
            if (!isset($expected[$entry])) {
                $problems[] = "{$entry}: not in the journal trigger generator plan";
                continue;
            }
            try {
                $content = FsPath::read($path . '/' . $entry);
            } catch (FsException $e) {
                throw new DatabaseException("Journal trigger {$entry}: " . $e->getMessage(), previous: $e);
            }
            if (preg_match('/\A-- valid from migration #(0|[1-9][0-9]*)\n(.*)\n\z/sD', $content, $matches) !== 1) {
                $problems[] = "{$entry}: invalid migration header or file format";
                continue;
            }
            $validFrom = filter_var($matches[1], FILTER_VALIDATE_INT);
            if ($validFrom === false || $validFrom > $migrationIndex) {
                $problems[] = "{$entry}: valid-from migration {$matches[1]} exceeds applied level {$migrationIndex}";
                continue;
            }
            $canonical = $expected[$entry];
            // Exact equality also proves there is one expected CREATE/DROP statement; splitting
            // SQL on semicolons would break the generated BEGIN...END compound statement.
            if ($matches[2] !== $canonical->body) {
                $problems[] = "{$entry}: body differs from the journal trigger generator; regenerate the file";
                continue;
            }
            $files[$entry] = new JournalTriggerFile($canonical->name, $canonical->body, $validFrom, $canonical->tombstone);
        }
        foreach ($expected as $entry => $_file) {
            if (!in_array($entry, $entries, true)) {
                $problems[] = "{$entry}: missing journal trigger file";
            }
        }
        if ($problems !== []) {
            throw new DatabaseException('Journal trigger files refused: ' . implode('; ', $problems));
        }

        return array_map(static fn(string $entry): JournalTriggerFile => $files[$entry], array_keys($expected));
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
