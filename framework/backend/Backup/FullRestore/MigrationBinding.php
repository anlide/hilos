<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;

/** A journal follows the primary history and execution schema, never its own migrator. */
final readonly class MigrationBinding
{
    private const int ADDRESS_PARTS = 4;
    private const int SCHEMA_POSITION = 3;
    private const int SERVER_PARTS = 3;

    /**
     * @param PreparedTarget $target Destination whose objects will be planned
     * @param PreparedTarget $source Destination carrying the actual migration history
     */
    private function __construct(public PreparedTarget $target, public PreparedTarget $source)
    {
    }

    /**
     * Uses the prepared server-reported addresses, not client host aliases, to check journal placement.
     * FullRestorePlan must recheck these addresses before any write.
     *
     * @param PreparedTarget $target Destination being bound
     * @param list<PreparedTarget> $targets All prepared destinations
     * @return self Actual history source and SQL execution schema
     * @throws DatabaseException When a configured connection cannot be read
     * @throws RestoreFailedException When a journal has no correctly placed primary source
     */
    public static function resolve(PreparedTarget $target, array $targets): self
    {
        if (Database::getConnectionConfig($target->connection->index) != $target->config) {
            throw new RestoreFailedException('The prepared migration target configuration changed');
        }
        if (!ChangeLogDatabase::isJournalConnection($target->connection->index, $target->config->database)) {
            return new self($target, $target);
        }
        foreach ($targets as $source) {
            if ($source->connection->index !== DatabaseConnectionDefaults::PRIMARY_INDEX) {
                continue;
            }
            $primaryAddress = json_decode($source->address, true);
            $journalAddress = json_decode($target->address, true);
            if (Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX) != $source->config
                || $target->config->database !== ChangeLogDatabase::configuredName()
                || !is_array($primaryAddress) || !array_is_list($primaryAddress) || count($primaryAddress) !== self::ADDRESS_PARTS
                || !is_array($journalAddress) || !array_is_list($journalAddress) || count($journalAddress) !== self::ADDRESS_PARTS
                || $primaryAddress[self::SCHEMA_POSITION] !== $source->config->database
                || $journalAddress[self::SCHEMA_POSITION] !== $target->config->database
                || array_slice($primaryAddress, 0, self::SERVER_PARTS) !== array_slice($journalAddress, 0, self::SERVER_PARTS)) {
                throw new RestoreFailedException('The journal target does not match the primary UP track physical schemas');
            }
            return new self($target, $source);
        }
        throw new RestoreFailedException('The journal target has no primary dump migration history');
    }
}
