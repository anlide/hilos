<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Analytics\AnalyticsJournalLoader;
use Hilos\Core\Analytics\AnalyticsJournalLoadOutcome;
use Hilos\Core\Analytics\AnalyticsStore;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Base class for integration tests that write into the analytics tables.
 *
 * The analytics schema ships as a stub for projects to copy, so the tables are built
 * from that very file rather than from a second copy of the DDL: a test that carried
 * its own CREATE TABLE would keep passing after the shipped stub drifted away from it,
 * and the drift is exactly what these tests are here to catch.
 *
 * Each test method gets the schema empty and leaves nothing behind.
 *
 * A worker's events reach the tables through the journal (HIL-1154), so a case that records them
 * plays the whole way with {@see self::loadJournal()}: the batches the collector queued are taken
 * off a router of the case's own and loaded the way the writer loads a file.
 */
abstract class AnalyticsSchemaIntegrationTestCase extends FrameworkIntegrationTestCase
{
    private ?SignalRouter $previousRouter = null;

    private int $journalFiles = 0;

    /**
     * @throws DatabaseException When the stub schema cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->rebuildAnalyticsSchema();
    }

    /**
     * @throws DatabaseException When the stub schema cannot be dropped
     */
    protected function tearDown(): void
    {
        $this->dropAnalyticsSchema();
        Hilos::$sr = $this->previousRouter;

        parent::tearDown();
    }

    /**
     * Sends what the collector gathered and loads every batch queued so far as one journal file.
     *
     * @param AnalyticsCollector $collector Collector whose batch is due
     * @param ?AnalyticsJournalLoader $loader Writer's loader, kept across files; a fresh one when null
     * @return AnalyticsJournalLoadOutcome What the load did
     * @throws HilosException When the load fails
     */
    protected function loadJournal(AnalyticsCollector $collector, ?AnalyticsJournalLoader $loader = null): AnalyticsJournalLoadOutcome
    {
        $collector->flush();
        $this->journalFiles++;

        return ($loader ?? new AnalyticsJournalLoader(new AnalyticsStore()))->load(
            '',
            sprintf('%012d-0000000000000000.jsonl', $this->journalFiles),
            $this->queuedJournalLines(),
        );
    }

    /**
     * Takes every batch queued for the journal off the router.
     *
     * @return list<string> Their lines, in the order the batches were queued
     */
    protected function queuedJournalLines(): array
    {
        $lines = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $batch = $signal->data instanceof AgentSignalData ? $signal->data->data : null;
            if (
                $signal->signalName->getName() === HilosSignalConstants::ANALYTICS_JOURNAL_APPEND
                && $batch instanceof AnalyticsJournalAppendSignalData
            ) {
                array_push($lines, ...$batch->lines);
            }
        }

        return $lines;
    }

    /**
     * Drops the stub tables and builds them again, empty.
     *
     * This is what a restore does to these tables, so a case can replace the database
     * under a collector halfway through (HIL-910).
     *
     * @throws DatabaseException When a drop or a create fails
     */
    protected function rebuildAnalyticsSchema(): void
    {
        $this->dropAnalyticsSchema();
        foreach ($this->analyticsSchemaStatements() as $statement) {
            Database::sql($statement);
        }
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
