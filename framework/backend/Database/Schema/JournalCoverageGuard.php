<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\InvalidMountedCollectionException;
use Hilos\Database\Exception\UnplacedJournalColumnException;

/** Refuses startup when a mounted journaled table has an unplaced live column. */
final class JournalCoverageGuard
{
    /**
     * Reads each mounted journaled Entity's schema once before the daemon manager is built.
     *
     * A missing optional table has no live columns to judge. The journal connection is the
     * activation switch, independent of whether the installation offers backup.
     *
     * @throws UnplacedJournalColumnException When declarations or live columns cannot be placed
     * @throws InvalidMountedCollectionException When a mounted collection has no valid Entity
     * @throws InvalidArgumentException When a placement cannot carry its reason
     * @throws DatabaseException When the caller's database connection cannot be restored
     */
    public static function assertMountedTablesPlaced(): void
    {
        if (!in_array(ChangeLogDatabase::CONNECTION_INDEX, Database::getConfiguredIndices(), true)) {
            return;
        }

        $mounted = JournaledTables::mounted();
        if ($mounted === []) {
            return;
        }

        $callerIndex = Database::getCurrentIndex();
        $schemasByIndex = [];
        try {
            foreach ($mounted as $entityClass) {
                $index = $entityClass::getDatabase()->schemaIndex;
                if (isset($schemasByIndex[$index])) {
                    continue;
                }
                Database::useConnection($index);
                if (!Database::isConnected($index)) {
                    Database::connect($index);
                }
                $schemasByIndex[$index] = LiveSchemaReader::read($index);
            }
        } catch (DatabaseException $failure) {
            throw new UnplacedJournalColumnException([
                'journaled schema: cannot read live tables: ' . $failure->getMessage(),
            ]);
        } finally {
            Database::useConnection($callerIndex);
        }

        try {
            $pii = PiiRegistry::collect();
        } catch (AnonymizationConfigException $failure) {
            throw new UnplacedJournalColumnException([
                'journal PII verdict: ' . $failure->getMessage(),
            ]);
        }

        $problems = [];
        foreach ($mounted as $entityClass) {
            $schema = $schemasByIndex[$entityClass::getDatabase()->schemaIndex][$entityClass::_table] ?? null;
            if ($schema === null) {
                continue;
            }

            try {
                JournalColumnPolicy::forTable($entityClass, $schema, $pii);
            } catch (UnplacedJournalColumnException $failure) {
                array_push($problems, ...$failure->problems);
            }
        }

        if ($problems !== []) {
            throw new UnplacedJournalColumnException($problems);
        }
    }
}
