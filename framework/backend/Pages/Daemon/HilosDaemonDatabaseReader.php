<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Pages\Daemon\DTO\HilosDaemonDatabaseData;

/** Reads existing infrastructure metadata only while the worker builds a page answer. */
class HilosDaemonDatabaseReader
{
    private const string MAX_INDEX = 'max_index';

    /**
     * @return ?HilosDaemonDatabaseData Null without primary config; an unknown index on query failure
     * @throws DatabaseException When the previously active connection can no longer be restored
     */
    public function read(): ?HilosDaemonDatabaseData
    {
        try {
            $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        } catch (DatabaseException) {
            return null;
        }

        $previousIndex = Database::getCurrentIndex();
        $migrationIndex = null;
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $row = $this->query('SELECT MAX(`index`) AS max_index FROM `migration` WHERE `failed` = 0');
            if ($row !== null && array_key_exists(self::MAX_INDEX, $row)) {
                $migrationIndex = $row[self::MAX_INDEX] === null ? 0 : (int) $row[self::MAX_INDEX];
            }
        } catch (DatabaseException) {
            // Reading the existing history may fail; it must never initialize the migration tables.
        } finally {
            Database::useConnection($previousIndex);
        }

        return new HilosDaemonDatabaseData($config->host, $config->port, $config->database, $config->socket, $migrationIndex);
    }

    /**
     * @param string $sql Read-only infrastructure query
     * @return ?array<string, mixed> Raw driver row, null if no result was returned
     * @throws DatabaseException When the read fails, without reconnecting or retrying
     */
    protected function query(string $sql): ?array
    {
        Database::sql($sql, null, false);
        return Database::row();
    }
}
