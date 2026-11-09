<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\Database;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Schema\Schema;

/**
 * What a node's start does before it takes the schema rollout claim (HIL-2086).
 *
 * Nodes starting together on one database cannot be raced inside one PHP process, so the cases
 * pin down what the race is decided by: the connect step of a node's start reads no schema, and
 * the start goes through that step. Another node's rollout may be dropping a table at that
 * moment, so reading the schema is left to the daemon, after the rollout.
 *
 * Not rollback-scoped on purpose: the connect step replaces the connection, which a rollback
 * scope refuses.
 */
final class NodeStartConnectStepTest extends IntegrationTestCase
{
    /**
     * A table dropped between SHOW TABLES and DESCRIBE ends the node, so the step must not read at all.
     */
    public function testTheConnectStepOfANodeStartLeavesTheSchemaUnread(): void
    {
        Schema::reset(DatabaseConnectionDefaults::PRIMARY_INDEX);

        try {
            Database::openConnections();

            self::assertFalse(Schema::isInitialized(DatabaseConnectionDefaults::PRIMARY_INDEX));
            self::assertTrue(Database::isConnected(DatabaseConnectionDefaults::PRIMARY_INDEX));
            self::assertTrue(Database::isConnected(ChangeLogDatabase::CONNECTION_INDEX));
            self::assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
        } finally {
            // The schema cache is shared by the whole suite process.
            Schema::initialize(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    /**
     * Reads the source, because what is defended is the choice of method at a call site.
     */
    public function testANodeStartConnectsThroughThatStep(): void
    {
        $bootstrap = file_get_contents(dirname(__DIR__, 2) . '/backend/Bootstrap/docker.php');

        self::assertNotFalse($bootstrap);
        self::assertStringContainsString('Database::openConnections(retryConnection: true)', $bootstrap);
    }
}
