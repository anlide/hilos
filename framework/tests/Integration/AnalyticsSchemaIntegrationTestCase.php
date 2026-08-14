<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseException;

/**
 * Base class for integration tests that write into the analytics tables.
 *
 * The analytics schema ships as a stub for projects to copy, so the tables are built
 * from that very file rather than from a second copy of the DDL: a test that carried
 * its own CREATE TABLE would keep passing after the shipped stub drifted away from it,
 * and the drift is exactly what these tests are here to catch.
 *
 * Each test method gets the schema empty and leaves nothing behind.
 */
abstract class AnalyticsSchemaIntegrationTestCase extends FrameworkIntegrationTestCase
{
    /**
     * @throws DatabaseException When the stub schema cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropAnalyticsSchema();
        foreach ($this->analyticsSchemaStatements() as $statement) {
            Database::sql($statement);
        }
    }

    /**
     * @throws DatabaseException When the stub schema cannot be dropped
     */
    protected function tearDown(): void
    {
        $this->dropAnalyticsSchema();

        parent::tearDown();
    }

    /**
     * Drops the stub tables child-first, so the foreign keys never block the drop.
     *
     * @throws DatabaseException When a drop fails
     */
    private function dropAnalyticsSchema(): void
    {
        $tables = [];
        foreach ($this->analyticsSchemaStatements() as $statement) {
            if (preg_match('/CREATE TABLE `(\w+)`/', $statement, $found) === 1) {
                $tables[] = $found[1];
            }
        }

        foreach (array_reverse($tables) as $table) {
            Database::sql("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    /**
     * Reads the project-facing stub migration and splits it into statements.
     *
     * @return list<string> CREATE TABLE statements, in file order
     */
    private function analyticsSchemaStatements(): array
    {
        $sql = (string)file_get_contents(
            dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_analytics.sql',
        );

        $statements = [];
        foreach (explode(';', $sql) as $statement) {
            $trimmed = trim($statement);
            if (str_contains($trimmed, 'CREATE TABLE')) {
                $statements[] = $trimmed;
            }
        }

        return $statements;
    }
}
