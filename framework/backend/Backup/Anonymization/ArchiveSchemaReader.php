<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupScope;
use Hilos\Backup\Exception\RestoreFailedException;

/**
 * ArchiveSchemaReader - reads the table schemas an archive's dump file declares.
 *
 * The coverage gate has to judge the archive before a single row of it is imported, so
 * the schema it judges against comes out of the dump text rather than out of a database.
 * That is workable because the text is not arbitrary SQL: the archive was written by
 * {@see BackupCreator} through mysqldump, and every scope it dumps under leaves each table
 * with exactly one `CREATE TABLE` block, laid out one column per line
 * ({@see BackupCreator::scopeDumpPasses()}). {@see BackupScope::FULL} is a single pass with
 * no restricting flags, {@see BackupScope::SCHEMA_ONLY} a single `--no-data` pass, and
 * {@see BackupScope::SCHEMA_SEED} a `--no-data` pass followed by a `--no-create-info` one
 * that adds rows without repeating a block.
 *
 * Anything the block does not lay out that way is skipped rather than guessed at: an
 * unrecognized line inside a block is not a column, and a table the reader does not see
 * is simply not gated. The gate stays honest anyway - a table it fails to read is a table
 * the anonymization pass also never touches.
 */
final class ArchiveSchemaReader
{
    /** Opening line of a table block: `CREATE TABLE `name` (`. */
    private const string CREATE_PATTERN = '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`\s*\($/i';

    /** Column definition: a backtick-quoted name, its type, and an optional length. */
    private const string COLUMN_PATTERN = '/^`([^`]+)`\s+(\w+)(?:\(\s*(\d+)(?:\s*,\s*\d+)?\s*\))?/';

    /** Primary key clause of a table block. */
    private const string PRIMARY_KEY_PATTERN = '/^PRIMARY\s+KEY\s*\((.+)\)/i';

    /** Backtick-quoted identifier, as listed inside a key clause. */
    private const string IDENTIFIER_PATTERN = '/`([^`]+)`/';

    /** A column that may not hold NULL says so; everything else in MySQL may. */
    private const string NOT_NULL_PATTERN = '/\bNOT\s+NULL\b/i';

    /** Single-quoted literal, dropped before NOT NULL is looked for so a default cannot fake one. */
    private const string LITERAL_PATTERN = "/'(?:[^'\\\\]|\\\\.|'')*'/";

    /**
     * Column types whose declared length bounds the characters a value may carry.
     *
     * The parenthesized number of the other types is a display width or a precision, and
     * truncating a hash to it would be arithmetic dressed as a length.
     */
    private const array LENGTH_BEARING_TYPES = ['char', 'varchar', 'binary', 'varbinary'];

    /** @var list<ArchiveTableSchema> Tables closed so far */
    private array $schemas = [];

    /** @var ?string Table whose block is open, or null between blocks */
    private ?string $table = null;

    /** @var array<string, bool> Columns of the open block */
    private array $columns = [];

    /** @var array<string, ?int> Declared column lengths of the open block */
    private array $lengths = [];

    /** @var list<string> Primary key of the open block */
    private array $primaryKey = [];

    /**
     * Reads the table schemas one connection's dump file declares.
     *
     * Streamed line by line rather than read whole. Under {@see BackupScope::FULL} this file
     * is every row of the database, and the rest of the restore path never brings it into PHP
     * at all - the import hands it to the mysql client as a file descriptor. A preflight check
     * that loaded a multi-gigabyte dump into memory would be the one step that fails on exactly
     * the databases most worth anonymizing.
     *
     * @param string $path Absolute path of the extracted `db-<index>.sql`
     * @return list<ArchiveTableSchema> Declared tables, in the order the dump writes them
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

        return $reader->schemas;
    }

    /**
     * Parses the `CREATE TABLE` blocks of a dump text already in hand.
     *
     * The same reader over a string instead of a file, for callers holding the text; a dump
     * from storage takes {@see read()}, which never holds more than one line of it.
     *
     * @param string $sql Dump text
     * @return list<ArchiveTableSchema> Declared tables, in the order the text writes them
     */
    public static function parse(string $sql): array
    {
        $reader = new self();
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $reader->consume($line);
        }

        return $reader->schemas;
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
                $this->columns = [];
                $this->lengths = [];
                $this->primaryKey = [];
            }

            return;
        }

        if (str_starts_with($line, ')')) {
            $this->schemas[] = new ArchiveTableSchema(
                $this->table,
                $this->columns,
                $this->lengths,
                $this->primaryKey,
            );
            $this->table = null;

            return;
        }

        $definition = rtrim($line, ',');
        if (preg_match(self::COLUMN_PATTERN, $definition, $column) === 1) {
            $name = $column[1];
            $this->columns[$name] = preg_match(self::NOT_NULL_PATTERN, self::withoutLiterals($definition)) !== 1;
            $this->lengths[$name] = self::lengthOf($column[2], $column[3] ?? null);

            return;
        }

        if (preg_match(self::PRIMARY_KEY_PATTERN, $definition, $key) === 1) {
            preg_match_all(self::IDENTIFIER_PATTERN, $key[1], $identifiers);
            $this->primaryKey = $identifiers[1];
        }
    }

    /**
     * Returns the character length a column type bounds its values by.
     *
     * @param string $type Declared column type
     * @param ?string $length Parenthesized number the declaration carried, when any
     * @return ?int Character length, or null when the type carries none
     */
    private static function lengthOf(string $type, ?string $length): ?int
    {
        if ($length === null || !in_array(strtolower($type), self::LENGTH_BEARING_TYPES, true)) {
            return null;
        }

        return (int)$length;
    }

    /**
     * Strips single-quoted literals from a column definition.
     *
     * A default or a comment may spell the words the nullability test looks for, and it
     * carries no meaning for the schema, so it leaves before the test runs.
     *
     * @param string $definition Column definition line
     * @return string The definition with its literals removed
     */
    private static function withoutLiterals(string $definition): string
    {
        return (string)preg_replace(self::LITERAL_PATTERN, "''", $definition);
    }
}
