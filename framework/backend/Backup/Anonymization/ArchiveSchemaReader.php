<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupScope;
use Hilos\Backup\Exception\RestoreFailedException;

/**
 * ArchiveSchemaReader - reads the tables an archive's dump file declares.
 *
 * The coverage gate has to judge the archive before a single row of it is imported, so what
 * it judges comes out of the dump text rather than out of a database. Names are all it
 * takes: how a column of that table is shaped is a question about the schema the pass
 * writes into, and that one is asked of the live database after the migrations
 * ({@see LiveSchemaReader}).
 *
 * Reading the names out of text is workable because the text is not arbitrary SQL: the
 * archive was written by {@see BackupCreator} through mysqldump, and every scope it dumps
 * under leaves each table with exactly one `CREATE TABLE` block
 * ({@see BackupCreator::scopeDumpPasses()}). {@see BackupScope::FULL} is a single pass with
 * no restricting flags, {@see BackupScope::SCHEMA_ONLY} a single `--no-data` pass, and
 * {@see BackupScope::SCHEMA_SEED} a `--no-data` pass followed by a `--no-create-info` one
 * that adds rows without repeating a block.
 *
 * A block is counted once it closes, so a line that merely looks like an opening one inside
 * a block cannot invent a table. A table the reader does not see is simply not gated, and
 * the gate stays honest anyway - a table it fails to read is a table the anonymization pass
 * also never touches.
 */
final class ArchiveSchemaReader
{
    /** Opening line of a table block: `CREATE TABLE `name` (`. */
    private const string CREATE_PATTERN = '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`\s*\($/i';

    /** @var list<string> Tables whose block has closed, in the order the dump writes them */
    private array $tables = [];

    /** @var ?string Table whose block is open, or null between blocks */
    private ?string $table = null;

    /**
     * Reads the tables one connection's dump file declares.
     *
     * Streamed line by line rather than read whole. Under {@see BackupScope::FULL} this file
     * is every row of the database, and the rest of the restore path never brings it into PHP
     * at all - the import hands it to the mysql client as a file descriptor. A preflight check
     * that loaded a multi-gigabyte dump into memory would be the one step that fails on exactly
     * the databases most worth anonymizing.
     *
     * @param string $path Absolute path of the extracted `db-<index>.sql`
     * @return list<string> Declared table names, in the order the dump writes them
     * @throws RestoreFailedException When the dump file is missing or unreadable
     */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            throw new RestoreFailedException("Archive dump not found for schema reading: {$path}");
        }

        // warning-suppressed: false becomes RestoreFailedException on the next line
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RestoreFailedException("Cannot read the archive dump for schema: {$path}");
        }

        $reader = new self();
        try {
            while (($line = fgets($handle)) !== false) {
                $reader->consume($line);
            }
        } finally {
            fclose($handle);
        }

        return $reader->tables;
    }

    /**
     * Reads the tables a dump text already in hand declares.
     *
     * The same reader over a string instead of a file, for callers holding the text; a dump
     * from storage takes {@see read()}, which never holds more than one line of it.
     *
     * @param string $sql Dump text
     * @return list<string> Declared table names, in the order the text writes them
     */
    public static function parse(string $sql): array
    {
        $reader = new self();
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $reader->consume($line);
        }

        return $reader->tables;
    }

    /**
     * Feeds one line of a dump to the reader.
     *
     * @param string $rawLine Dump line, with or without its line ending
     */
    private function consume(string $rawLine): void
    {
        $line = trim($rawLine);
        if ($this->table === null) {
            if (preg_match(self::CREATE_PATTERN, $line, $opening) === 1) {
                $this->table = $opening[1];
            }

            return;
        }

        if (str_starts_with($line, ')')) {
            $this->tables[] = $this->table;
            $this->table = null;
        }
    }
}
