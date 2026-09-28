<?php

declare(strict_types=1);

namespace Hilos\AdminViewMode;

use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\SqlRuntime\DeadlockDetectedException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Migration;
use Hilos\Database\MigrationClaim;

/**
 * The half of the admin view mode latch that lives in the database (HIL-1249).
 *
 * One row per installation, and the database is one for a whole cluster, so a node joining a
 * production cluster that closed the mode finds it closed too. The other half is a file in each
 * node's log directory ({@see AdminViewModeLatchFile}); whichever half survives, the next start
 * writes the other one back.
 *
 * Outside the ORM, like {@see MigrationClaim}: only the startup of a daemon reads and writes it,
 * before any agent exists, and no page or agent owns it. The table is created beside `migration`
 * by {@see Migration::initialize()}, because a project's own migrations know nothing about it.
 *
 * The row lives on the primary connection whichever connection the caller stands on.
 */
final class AdminViewModeLatchTable
{
    /** @var string Table the latch row lives in */
    public const string TABLE = 'hilos_admin_view_mode_latch';

    /** @var int Key of the one latch row: one latch per installation */
    public const int ROW_ID = 1;

    /**
     * @return ?array{environment: string, node: string, closedAt: int} Latch record, or null when no row is there
     * @throws DatabaseException When the primary connection or the latch table cannot be read
     */
    public static function read(): ?array
    {
        $callerIndex = Database::getCurrentIndex();
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::sql(
                'SELECT `environment`, `node`, `closed_at` FROM `' . self::TABLE . '` WHERE `id` = ?',
                [self::ROW_ID],
            );
            $row = Database::row();
        } finally {
            Database::useConnection($callerIndex);
        }

        return $row === null ? null : [
            AdminViewModeLatchFile::KEY_ENVIRONMENT => (string)$row['environment'],
            AdminViewModeLatchFile::KEY_NODE => (string)$row['node'],
            AdminViewModeLatchFile::KEY_CLOSED_AT => (int)$row['closed_at'],
        ];
    }

    /**
     * Writes the latch row unless one is there already.
     *
     * A row already there means another node closed the mode first - a duplicate key on one
     * server, a certification conflict on Galera - and that is the same outcome, not a failure.
     *
     * @param string $environment APP_ENV of the installation the mode was closed on
     * @param string $node CLUSTER_NODE_ID of the node that closed it
     * @param int $closedAt Unix time the mode was closed at
     * @throws DatabaseException When the primary connection or the latch table cannot be written
     */
    public static function close(string $environment, string $node, int $closedAt): void
    {
        $callerIndex = Database::getCurrentIndex();
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::sql(
                'INSERT INTO `' . self::TABLE . '` (`id`, `environment`, `node`, `closed_at`) VALUES (?, ?, ?, ?)',
                [self::ROW_ID, $environment, $node, $closedAt],
            );
        } catch (DuplicateEntryException | DeadlockDetectedException) {
            // Another node closed the mode between this start's read and its write.
        } finally {
            Database::useConnection($callerIndex);
        }
    }
}
