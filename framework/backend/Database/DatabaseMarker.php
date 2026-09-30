<?php

declare(strict_types=1);

namespace Hilos\Database;

use Closure;
use Hilos\Backup\BackupRestorer;
use Hilos\Database\Exception\SqlRuntime\DeadlockDetectedException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Exception\SqlRuntime\TableNotFoundException;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Logger;

/**
 * The database marker: one row that names this logical database, so that nodes can tell whether
 * they read the same one (HIL-1206).
 *
 * A project promises that every node reads one logical database ({@see DatabaseGuarantee}), and
 * the marker is how a cluster checks it. The first node to start writes a random name into the
 * row; every node reads the row once at the start of its daemon and names it to its peers on the
 * handshake. A node that reads another name reads another database. Neither the server's own
 * identity nor its host would do: on an honest multi-primary setup each server has its own while
 * the database is logically one, and such a check would raise the alarm on exactly the right
 * configuration.
 *
 * The first write is decided by the insert itself, as with {@see MigrationClaim}: a duplicate key
 * on one server, a certification conflict on Galera. The loser may not see the winner's row for a
 * moment on Galera, so it waits for the fact rather than for a deadline.
 *
 * The marker is the name of the database, not its content: a restore does not bring it
 * ({@see BackupRestorer}), and a single-node installation never writes it - there is nobody to
 * compare it with. Outside the ORM, like the claim: only the start of a daemon reads and writes it.
 * The table is created beside `migration` by {@see Migration::initialize()}.
 *
 * The row lives on the primary connection whichever connection the caller stands on; the other
 * connections carry no marker.
 *
 * A new marker is drawn from the tolerant random axis ({@see RandomHelper::hex()}): it is a name,
 * not a secret - it only has to differ from the name of every other database, and a stranger who
 * guessed it would gain nothing without the database itself. A node whose entropy source refuses
 * still starts, where the secure axis would have stopped it over a value nobody needs to guess.
 */
final class DatabaseMarker
{
    /** @var string Table the marker row lives in */
    public const string TABLE = 'hilos_database_marker';

    /** @var int Key of the one marker row: one database, one name */
    public const int ROW_ID = 1;

    /** @var int Random bytes a new marker is drawn from; their hex spelling is the marker */
    private const int MARKER_BYTES = 16;

    /** @var int Seconds a node that lost the first write sleeps between two reads of the row */
    private const int POLL_INTERVAL_SECONDS = 1;

    /** @var int Polls between two waiting lines in the journal; the first poll always writes one */
    private const int POLLS_PER_WAITING_LINE = 30;

    /**
     * Reads the marker, writing it first when this database has none yet.
     *
     * There is no deadline: a node that lost the first write goes on only once the winner's row is
     * readable here, and says so in the journal on the first poll and on every 30th after it.
     *
     * @param string $writtenBy CLUSTER_NODE_ID of the node asking; recorded only if it writes the marker
     * @param ?Closure(): void $pause Wait between two polls; one second when null
     * @return DatabaseMarkerRow The marker of this database
     * @throws DatabaseException When the primary connection or the marker table cannot be read or written
     */
    public static function ensure(string $writtenBy, ?Closure $pause = null): DatabaseMarkerRow
    {
        $polls = 0;
        while (true) {
            $row = self::current();
            if ($row !== null) {
                return $row;
            }

            $marker = RandomHelper::hex(self::MARKER_BYTES);
            try {
                self::onPrimary(static function () use ($marker, $writtenBy): void {
                    Database::sql(
                        'INSERT INTO `' . self::TABLE . '` (`id`, `marker`, `written_by`, `written_at`) VALUES (?, ?, ?, NOW())',
                        [self::ROW_ID, $marker, $writtenBy],
                    );
                });

                continue;
            } catch (DuplicateEntryException | DeadlockDetectedException) {
                // Another node wrote the row first: a duplicate key on one server, a certification
                // conflict on Galera. On Galera its row may not be readable here yet.
            }

            if ($polls % self::POLLS_PER_WAITING_LINE === 0) {
                Logger::warning('Waiting for the database marker another node is writing to become readable here');
            }
            $polls++;

            if ($pause === null) {
                sleep(self::POLL_INTERVAL_SECONDS);
            } else {
                $pause();
            }
        }
    }

    /**
     * @return ?DatabaseMarkerRow The marker as it stands, or null when the table or its row is not there
     * @throws DatabaseException When the primary connection or the marker table cannot be read
     */
    public static function current(): ?DatabaseMarkerRow
    {
        try {
            $row = self::onPrimary(static function (): ?array {
                Database::sql(
                    'SELECT `marker`, `written_by`, `written_at` FROM `' . self::TABLE . '` WHERE `id` = ?',
                    [self::ROW_ID],
                );

                return Database::row();
            });
        } catch (TableNotFoundException) {
            return null;
        }

        return $row === null
            ? null
            : new DatabaseMarkerRow((string)$row['marker'], (string)$row['written_by'], (string)$row['written_at']);
    }

    /**
     * Writes a marker row as given, over whatever row is there.
     *
     * For a restore alone: it puts back the marker the target had before the archive replaced it.
     *
     * @param DatabaseMarkerRow $row Marker to leave in the database
     * @throws DatabaseException When the primary connection or the marker table cannot be written
     */
    public static function put(DatabaseMarkerRow $row): void
    {
        self::onPrimary(static function () use ($row): void {
            Database::sql(
                'REPLACE INTO `' . self::TABLE . '` (`id`, `marker`, `written_by`, `written_at`) VALUES (?, ?, ?, ?)',
                [self::ROW_ID, $row->marker, $row->writtenBy, $row->writtenAt],
            );
        });
    }

    /**
     * Empties the marker table.
     *
     * For a restore alone: a target that had no marker is left without one, whatever the archive carried.
     *
     * @throws DatabaseException When the primary connection or the marker table cannot be written
     */
    public static function clear(): void
    {
        self::onPrimary(static function (): void {
            Database::sql('DELETE FROM `' . self::TABLE . '`');
        });
    }

    /**
     * Names where this node reads the marker from, for the lines that print it.
     *
     * @return string The primary connection's database, host and port
     * @throws DatabaseException When the primary connection is not configured
     */
    public static function place(): string
    {
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);

        return "database '{$config->database}' on {$config->host}:{$config->port}";
    }

    /**
     * Runs a statement on the primary connection and gives the caller its own connection back.
     *
     * Connects the primary connection first when this process has not: a restore may stand on
     * another one.
     *
     * @template T
     * @param Closure(): T $work Statements to run on the primary connection
     * @return T Whatever the statements return
     * @throws DatabaseException When the primary connection cannot be reached or a statement fails
     */
    private static function onPrimary(Closure $work): mixed
    {
        $callerIndex = Database::getCurrentIndex();
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            if (!Database::isConnected()) {
                Database::connect();
            }

            return $work();
        } finally {
            Database::useConnection($callerIndex);
        }
    }
}
