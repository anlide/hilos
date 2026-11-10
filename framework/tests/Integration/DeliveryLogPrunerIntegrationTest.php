<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use DateTimeImmutable;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\DeliveryLogPruner;

/**
 * Integration tests for pruning the durable notification-delivery journal (HIL-490).
 *
 * A live database alone exercises the DELETE LIMIT loop and its affected-row result: the SQL
 * comparison must leave a row exactly on the cutoff and every pending row intact, then continue
 * after a full batch. A fixture or a mocked Database cannot establish either property.
 */
final class DeliveryLogPrunerIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Framework tables this case raises, in dependency order. */
    private const array TABLES = ['hilos_notification_delivery'];

    /** Retention window passed to the pruner, in days. */
    private const int RETENTION_DAYS = 90;

    /** Fixed clock supplied to the pruner. */
    private const string NOW = '2026-09-24 12:00:00.123 UTC';

    /** Epoch milliseconds exactly on the retention boundary. */
    private const int CUTOFF = 1782475200123;

    /** Epoch milliseconds one millisecond before the retention boundary. */
    private const int BEFORE_CUTOFF = 1782475200122;

    /** Epoch milliseconds one day before the fixed clock. */
    private const int RECENT = 1790164800123;

    /** Synthetic notification id; the delivery journal deliberately has no notification FK. */
    private const int NOTIFICATION_ID = 490;

    /** Channel value carried by all fixtures. */
    private const string CHANNEL = 'email';

    /** Terminal rows inserted to make the pruner cross two 1,000-row batches. */
    private const int TERMINAL_ROWS = 2500;

    /** Rows written in each fixture INSERT statement. */
    private const int ROWS_PER_INSERT = 500;

    /**
     * Raises the one table the pruner reads.
     *
     * @throws EnvException When env variables are missing or invalid
     * @throws DatabaseConnectionException When connect fails
     * @throws CantConnectToMysqlServerException When connect retries are exhausted
     * @throws DatabaseException When the delivery table cannot be raised
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);
    }

    /**
     * Drops the delivery table before the parent closes its connection.
     *
     * @throws DatabaseException When the delivery table cannot be dropped
     */
    protected function tearDown(): void
    {
        self::runStubs(down: true);

        parent::tearDown();
    }

    public function testOnlyTerminalRowsOlderThanTheWindowAreDeleted(): void
    {
        $oldSent = $this->insertDelivery(DeliveryStatus::SENT, self::BEFORE_CUTOFF);
        $oldFailed = $this->insertDelivery(DeliveryStatus::FAILED, self::BEFORE_CUTOFF);
        $oldPending = $this->insertDelivery(DeliveryStatus::PENDING, 1782388800123);
        $atCutoff = $this->insertDelivery(DeliveryStatus::SENT, self::CUTOFF);
        $recentSent = $this->insertDelivery(DeliveryStatus::SENT, self::RECENT);

        self::assertSame(
            2,
            (new DeliveryLogPruner())->prune(self::RETENTION_DAYS, new DateTimeImmutable(self::NOW)),
        );
        self::assertSame([$oldPending, $atCutoff, $recentSent], $this->deliveryIds());
        self::assertNotContains($oldSent, $this->deliveryIds());
        self::assertNotContains($oldFailed, $this->deliveryIds());
    }

    public function testDeletionContinuesPastTheFirstBatch(): void
    {
        $this->insertTerminalRows();
        $pending = $this->insertDelivery(DeliveryStatus::PENDING, 1782388800123);

        self::assertSame(
            self::TERMINAL_ROWS,
            (new DeliveryLogPruner())->prune(self::RETENTION_DAYS, new DateTimeImmutable(self::NOW)),
        );
        self::assertSame([$pending], $this->deliveryIds());
    }

    /**
     * Writes one delivery row and returns its database-generated id.
     *
     * @param string $status Delivery lifecycle status
     * @param int $createdAt Epoch milliseconds when the delivery was created
     * @return int Delivery id assigned by MySQL
     * @throws DatabaseException When the row cannot be written or read back
     */
    private function insertDelivery(string $status, int $createdAt): int
    {
        Database::sql(
            'INSERT INTO `hilos_notification_delivery` (`notification_id`, `channel`, `status`, `created_at`, `updated_at`) '
            . 'VALUES (?, ?, ?, ?, 1767225600123)',
            [self::NOTIFICATION_ID, self::CHANNEL, $status, $createdAt],
        );

        return (int) Database::sql('SELECT MAX(`id`) AS id FROM `hilos_notification_delivery`')->firstRow()['id'];
    }

    /**
     * Writes terminal fixtures in five multi-row statements instead of 2,500 individual requests.
     *
     * @throws DatabaseException When a fixture batch cannot be written
     */
    private function insertTerminalRows(): void
    {
        $values = implode(', ', array_fill(0, self::ROWS_PER_INSERT, '(?, ?, ?, ?, 1767225600123)'));
        for ($offset = 0; $offset < self::TERMINAL_ROWS; $offset += self::ROWS_PER_INSERT) {
            $params = [];
            for ($row = 0; $row < self::ROWS_PER_INSERT; $row++) {
                $status = ($offset + $row) % 2 === 0 ? DeliveryStatus::SENT : DeliveryStatus::FAILED;
                array_push($params, self::NOTIFICATION_ID, self::CHANNEL, $status, self::BEFORE_CUTOFF);
            }
            Database::sql(
                'INSERT INTO `hilos_notification_delivery` (`notification_id`, `channel`, `status`, `created_at`, `updated_at`) '
                . 'VALUES ' . $values,
                $params,
            );
        }
    }

    /**
     * Returns remaining delivery ids in database order, rather than from an in-memory collection.
     *
     * @return list<int> Remaining delivery ids
     * @throws DatabaseException When the journal cannot be read
     */
    private function deliveryIds(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            Database::sql('SELECT `id` FROM `hilos_notification_delivery` ORDER BY `id`')->rows(),
        );
    }

    /**
     * Creates or drops the framework tables the pruner reads.
     *
     * @param bool $down Whether to run the teardown half of each stub
     * @throws DatabaseException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        $tables = $down ? array_reverse(self::TABLES) : self::TABLES;
        foreach ($tables as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string) file_get_contents($stub));
        }
    }
}
