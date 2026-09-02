<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

use Hilos\Backup\BackupCreator;
use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Backup\Exception\UnclassifiedLiveSchemaException;
use Hilos\Core\Daemon\DaemonApplication;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;

/**
 * AnonymizationStartupGuard - the coverage question asked at the startup of a node.
 *
 * The third and earliest of the anonymization gates, and the only one that runs when no
 * restore is happening: a project declaring {@see HilosFeature::BACKUP} keeps copies of a
 * database it promises to be able to anonymize, and this asks whether that promise still
 * holds over the schema the migrations have actually left behind. The other two answer the
 * same question far later - the archive gate before a restore's first import, the
 * compatibility gate after its forward migrations - which is to say, on the day someone
 * needs the copy rather than on the day the gap appeared.
 *
 * The reader of a refusal is the author of the migration, not the operator who started the
 * node: the verdict is written in code, and there is nothing an operator could do with the
 * message. So the refusal names every unclassified table and column of every configured
 * connection at once, and one edit answers all of them.
 *
 * Runs from {@see DaemonApplication::run()}, once, before the manager is built - a
 * one-time bootstrap read of configuration, which is what the master process is allowed
 * before its loop. Nothing composes until it returns: no server binds, no port is taken,
 * no peer sees a node that is not going to come up.
 *
 * Only the daemon carries it. The worker inherits a decision the daemon already made, the
 * docker supervisor runs the migrations that create the gap, and the CLI is where the gap
 * gets fixed - a gate there would be a dead end with no way out of it.
 */
final class AnonymizationStartupGuard
{
    /**
     * Refuses the startup of a node whose live schema is not classified for anonymization.
     *
     * Silent for a project that declares no backup: three of the four demos start exactly as
     * they did. Silent as well over an empty or half-migrated database - there is nothing to
     * cover, and a fresh installation comes up.
     *
     * @throws UnclassifiedLiveSchemaException When a table or a column of the live schema
     *     carries no PII verdict, when a declared verdict cannot be collected, or when the
     *     schema of a configured connection cannot be read
     */
    public static function assertLiveSchemaClassified(): void
    {
        if (!Hilos::hasFeature(HilosFeature::BACKUP)) {
            return;
        }

        try {
            $registry = PiiRegistry::collect();
        } catch (AnonymizationConfigException $refusal) {
            throw new UnclassifiedLiveSchemaException(
                'This node carries backup and refuses to start on a schema it cannot anonymize: '
                . $refusal->getMessage(),
                0,
                $refusal,
            );
        }

        try {
            $schemasByConnection = self::readLiveSchemas();
        } catch (DatabaseException $failure) {
            throw new UnclassifiedLiveSchemaException(
                'This node carries backup and cannot read the schema it would anonymize: '
                . $failure->getMessage(),
                0,
                $failure,
            );
        }

        AnonymizationCoverageValidator::validateLiveSchema($registry, $schemasByConnection);
    }

    /**
     * Reads the live schema of every configured connection.
     *
     * The same connections a backup dumps and in the same way it reaches them
     * ({@see BackupCreator::dumpAllConnections()}), because the gate has to promise about
     * exactly what the copies would carry: a narrower walk would report a classified schema
     * and let a restore refuse over the connection it skipped.
     *
     * The connection the caller was on is restored on the way out, refusal included. The
     * walk moves {@see Database::useConnection()}, which writes a static current index, and a
     * daemon left pointing at the last connection of the walk would read the wrong database
     * for the rest of its life.
     *
     * @return array<int, array<string, LiveTableSchema>> Live tables by name, per connection index
     * @throws DatabaseException When a connection cannot be opened or its schema cannot be read
     */
    private static function readLiveSchemas(): array
    {
        $callerIndex = Database::getCurrentIndex();
        try {
            $schemasByConnection = [];
            foreach (Database::getConfiguredIndices() as $index) {
                Database::useConnection($index);
                if (!Database::isConnected($index)) {
                    Database::connect($index);
                }

                $schemasByConnection[$index] = LiveSchemaReader::read($index);
            }

            return $schemasByConnection;
        } finally {
            Database::useConnection($callerIndex);
        }
    }
}
