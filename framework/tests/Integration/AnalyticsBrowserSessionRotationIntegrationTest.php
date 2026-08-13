<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseException;

/**
 * Integration coverage for the browser session surviving a token rotation (HIL-582).
 *
 * Analytics names a browser session by the session token, so the login rotation that
 * renames the application session's secret would otherwise leave the whole visit before
 * the login - its page views, WebSocket connections and user-agent history - hanging off
 * a token nobody presents again, while the identify that follows opened a second session
 * for the same person. Joining a visitor to the account they just created is the one thing
 * this table exists for, so the rename is played here end to end against the real schema.
 *
 * The analytics schema ships as a stub for projects to copy, so this test builds it from
 * that very file rather than from a second copy of the DDL.
 */
final class AnalyticsBrowserSessionRotationIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string OLD_TOKEN = '0123456789abcdef0123456789abcdef';

    private const string NEW_TOKEN = 'fedcba9876543210fedcba9876543210';

    private const int USER_ID = 7;

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
     * @throws DatabaseException When reading back the recorded sessions fails
     */
    public function testTheVisitBeforeTheLoginFollowsTheSessionOntoItsNewToken(): void
    {
        $collector = new AnalyticsCollector();
        $opened = $collector->ensureBrowserSession(self::OLD_TOKEN, 'UA/1.0', 'en');
        $this->assertNotNull($opened);

        $collector->renameBrowserSession(self::OLD_TOKEN, self::NEW_TOKEN);
        $collector->identifyBrowserSessionUser(self::NEW_TOKEN, self::USER_ID);

        // One row, still the one the visit accumulated on, now named by the new token and
        // carrying the user it turned out to belong to.
        $this->assertSame([$opened], $this->browserSessionIds());
        $this->assertSame(self::NEW_TOKEN, $this->tokenOf($opened));
        $this->assertSame((string)self::USER_ID, $this->identityValueOf($opened));
    }

    /**
     * @throws DatabaseException When reading back the recorded sessions fails
     */
    public function testTheRenameFindsTheSessionAgainAfterTheCacheIsGone(): void
    {
        $opening = new AnalyticsCollector();
        $opened = $opening->ensureBrowserSession(self::OLD_TOKEN, null, null);
        $this->assertNotNull($opened);

        // The login is served by a worker that never saw this visitor's handshake, so the
        // rename has only the table to go on.
        new AnalyticsCollector()->renameBrowserSession(self::OLD_TOKEN, self::NEW_TOKEN);

        $this->assertSame(self::NEW_TOKEN, $this->tokenOf($opened));
    }

    /**
     * @throws DatabaseException When reading back the recorded sessions fails
     */
    public function testRenamingATokenThatCollectedNothingOpensNoSession(): void
    {
        new AnalyticsCollector()->renameBrowserSession(self::OLD_TOKEN, self::NEW_TOKEN);

        $this->assertSame([], $this->browserSessionIds());
    }

    /**
     * @return list<int> Ids of the recorded browser sessions, ordered by id
     * @throws DatabaseException When the query fails
     */
    private function browserSessionIds(): array
    {
        Database::sql('SELECT `id` FROM `hilos_analytics_browser_session` ORDER BY `id`');

        $ids = [];
        while (($row = Database::row()) !== null) {
            $ids[] = (int)$row['id'];
        }

        return $ids;
    }

    /**
     * @param int $id Browser session id
     * @return string Token the session answers to
     * @throws DatabaseException When the query fails
     */
    private function tokenOf(int $id): string
    {
        Database::sql('SELECT `session_token` FROM `hilos_analytics_browser_session` WHERE `id` = ?', [$id]);
        $row = Database::row();
        $this->assertNotNull($row);

        return (string)$row['session_token'];
    }

    /**
     * @param int $id Browser session id
     * @return string Identity value recorded for the session
     * @throws DatabaseException When the query fails
     */
    private function identityValueOf(int $id): string
    {
        Database::sql('SELECT `user_identity_value` FROM `hilos_analytics_browser_session` WHERE `id` = ?', [$id]);
        $row = Database::row();
        $this->assertNotNull($row);

        return (string)$row['user_identity_value'];
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
