<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Anonymization\ArchiveSchemaReader;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Database\Migration;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;

/**
 * ArchiveMigrationMarker - the migration level a schema archive declares in its own dump text.
 *
 * A {@see BackupScope::FULL} archive carries its level in the rows of the `migration` table it
 * dumps. A schema archive has no rows: both schema scopes dump `migration` empty, so a restore
 * that only imported the schema would leave {@see Migration::migrateUp()} reading level 0 and
 * replaying the whole history over a finished schema. The level therefore has to travel in the
 * one thing a schema dump does carry - its text.
 *
 * The carrier is a table comment on `migration`, written by {@see BackupCreator} as a separate
 * statement appended after the mysqldump passes:
 *
 *     ALTER TABLE `migration` COMMENT='hilos-migration-index=7';
 *
 * Appended rather than stamped into the generated `CREATE TABLE`: after one
 * backup-restore-backup cycle mysqldump emits the inherited comment itself, so writing into the
 * block would be a parse-and-replace, while an append is just a line at the end of the file.
 * The price is that the inherited comment stays in the block as an echo of the previous level,
 * which is why {@see MARKER_PATTERN} is anchored on `ALTER TABLE` and why {@see read()} takes
 * the LAST match: the only declared reader of the level is the appended statement.
 *
 * The live database is never altered to take a backup - the statement is written into the dump
 * file, not run against the source.
 */
final class ArchiveMigrationMarker
{
    /**
     * Comment prefix carrying the level.
     *
     * Public because it is what tells this marker from an unrelated table comment: a reader
     * outside this class judging a comment string judges it by this prefix, not by its own copy.
     */
    public const string COMMENT_PREFIX = 'hilos-migration-index=';

    /** Table the marker is written on - the one whose rows would otherwise carry the level. */
    private const string MIGRATION_TABLE = 'migration';

    /**
     * The appended statement, level captured.
     *
     * Anchored on `ALTER TABLE` so the inherited comment inside a `CREATE TABLE` block cannot be
     * read as a level, and case-insensitive because the statement is matched as SQL rather than
     * as a byte-for-byte echo of what {@see statement()} wrote.
     */
    private const string MARKER_PATTERN = '/^ALTER\s+TABLE\s+`' . self::MIGRATION_TABLE
        . '`\s+COMMENT\s*=\s*\'' . self::COMMENT_PREFIX . '(\d+)\'\s*;$/i';

    /**
     * Renders the marker statement for one connection's dump file.
     *
     * @param int $index Migration level the dump was taken at
     * @return string Statement line, its newline included, ready to append to a dump
     */
    public static function statement(int $index): string
    {
        return 'ALTER TABLE `' . self::MIGRATION_TABLE . '` COMMENT='
            . '\'' . self::COMMENT_PREFIX . $index . '\';' . "\n";
    }

    /**
     * Reads the migration level one connection's extracted dump declares.
     *
     * Streamed line by line rather than read whole, for the reason {@see ArchiveSchemaReader::read()}
     * gives: the same path is walked for archives whose dump file is larger than memory.
     *
     * @param string $path Absolute path of the extracted `db-<index>.sql`
     * @return ?int Declared migration level, or null when the dump declares none
     * @throws RestoreFailedException When the dump file is missing or unreadable
     */
    public static function read(string $path): ?int
    {
        $level = null;
        try {
            foreach (FsPath::readLines($path) as $line) {
                $level = self::levelOnLine($line) ?? $level;
            }
        } catch (FileNotFoundException $failure) {
            throw new RestoreFailedException("Archive dump not found for the migration marker: {$path}", 0, $failure);
        } catch (FsException $failure) {
            throw new RestoreFailedException("Cannot read the archive dump for the migration marker: {$path}", 0, $failure);
        }

        return $level;
    }

    /**
     * Reads the migration level a dump text already in hand declares.
     *
     * The same reader over a string instead of a file, mirroring {@see ArchiveSchemaReader::parse()};
     * a dump from storage takes {@see read()}, which never holds more than one line of it.
     *
     * @param string $sql Dump text
     * @return ?int Declared migration level, or null when the text declares none
     */
    public static function parse(string $sql): ?int
    {
        $level = null;
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $level = self::levelOnLine($line) ?? $level;
        }

        return $level;
    }

    /**
     * Reads the level one dump line declares.
     *
     * @param string $rawLine Dump line, with or without its line ending
     * @return ?int Level the line declares, or null when it is not a marker
     */
    private static function levelOnLine(string $rawLine): ?int
    {
        if (preg_match(self::MARKER_PATTERN, trim($rawLine), $marker) !== 1) {
            return null;
        }

        return (int)$marker[1];
    }
}
