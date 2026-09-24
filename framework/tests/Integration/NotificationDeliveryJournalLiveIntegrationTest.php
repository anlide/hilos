<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Communications\HilosNotificationDeliveryTableRow;

/**
 * Integration test: the delivery journal reads a changed row again, and places it, against a live database (HIL-1049).
 *
 * Two things here only the server can answer. The row a mutation carries is read by the journal's
 * own join, so the title and type of the notification ride with it although the change named the
 * delivery alone. And the boundaries of a window come back in whatever types the driver hands the
 * columns over in, which is what the table compares a new row against - a comparison that is right
 * against the types of a hand-written fixture proves nothing about these.
 */
final class NotificationDeliveryJournalLiveIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @var list<string> Framework tables the journal reads, in the order they are raised. */
    private const array TABLES = ['hilos_notification', 'hilos_notification_delivery'];

    /** Moment the older delivery was made. */
    private const string EARLIER = '2026-09-23 10:00:00';

    /** Moment the newer deliveries were made, the same second for both. */
    private const string LATER = '2026-09-23 10:00:01';

    /** Notification every delivery of these cases carries. */
    private int $notificationId = 0;

    /**
     * Raises the journal's tables with one notification behind every delivery.
     *
     * @throws EnvException When env variables are missing or invalid
     * @throws DatabaseConnectionException When connect fails
     * @throws CantConnectToMysqlServerException When connect retries are exhausted
     * @throws DatabaseException When the schema cannot be raised or seeded
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);
        Database::sql(
            'INSERT INTO `hilos_notification` (`user_id`, `type`, `title`) VALUES (?, ?, ?)',
            [3, 'backup.completed', 'Backup is ready'],
        );
        $this->notificationId = (int) Database::sql('SELECT MAX(`id`) AS id FROM `hilos_notification`')->firstRow()['id'];
    }

    /**
     * Drops the journal's tables before the connection is closed by the parent.
     *
     * @throws DatabaseException When a table cannot be dropped
     */
    protected function tearDown(): void
    {
        self::runStubs(down: true);

        parent::tearDown();
    }

    public function testACreatedDeliveryTravelsWithTheNotificationItCarries(): void
    {
        $id = $this->insertDelivery(self::EARLIER);

        $mutation = (new HilosNotificationDeliveriesTable())->buildMutationForSourceEvent(
            SourceChange::dbCreated(HilosDbContext::notificationDeliveries, (string) $id, []),
        );

        $this->assertNotNull($mutation);
        $this->assertSame(TableMutationType::Create, $mutation->type);
        $row = $mutation->row;
        $this->assertInstanceOf(HilosNotificationDeliveryTableRow::class, $row);
        $this->assertSame($id, $row->rowKey);
        $this->assertSame('pending', $row->status);
        $this->assertSame('Backup is ready', $row->notificationTitle);
        $this->assertSame('backup.completed', $row->notificationType);
        $this->assertSame(3, $row->userId);
    }

    public function testAnUpdatedDeliveryCarriesItsNewStatus(): void
    {
        $id = $this->insertDelivery(self::EARLIER);
        Database::sql(
            'UPDATE `hilos_notification_delivery` SET `status` = ?, `attempts` = 1, `delivered_at` = ? WHERE `id` = ?',
            ['sent', self::LATER, $id],
        );

        $mutation = (new HilosNotificationDeliveriesTable())->buildMutationForSourceEvent(
            SourceChange::dbUpdated(HilosDbContext::notificationDeliveries, (string) $id, ['status' => 'sent']),
        );

        $this->assertNotNull($mutation);
        $this->assertSame(TableMutationType::Update, $mutation->type);
        $row = $mutation->row;
        $this->assertInstanceOf(HilosNotificationDeliveryTableRow::class, $row);
        $this->assertSame('sent', $row->status);
        $this->assertSame(self::LATER, $row->deliveredAt);
    }

    public function testADeliveryThatIsNotThereBuildsNoMutation(): void
    {
        $this->assertNull((new HilosNotificationDeliveriesTable())->buildMutationForSourceEvent(
            SourceChange::dbUpdated(HilosDbContext::notificationDeliveries, '999999', []),
        ));
    }

    /**
     * The first boundary of a newest-first window comes from the server; a delivery made in the same
     * second after it is settled by its id against that boundary as the driver typed it, and stands above.
     */
    public function testANewerDeliveryStandsAboveTheBoundaryTheServerWrote(): void
    {
        $this->insertDelivery(self::EARLIER);
        $this->insertDelivery(self::LATER);
        $table = new HilosNotificationDeliveriesTable();
        $query = new TableQueryDTO(sort: $table->defaultSort(), limit: $table->windowSize());

        $snapshot = $table->getPage($query);
        $newest = $this->insertDelivery(self::LATER);
        $row = $table->buildMutationForSourceEvent(
            SourceChange::dbCreated(HilosDbContext::notificationDeliveries, (string) $newest, []),
        )?->row;

        $this->assertNotNull($snapshot->firstAnchor);
        $this->assertInstanceOf(HilosNotificationDeliveryTableRow::class, $row);
        $place = $table->placeRowAgainst($row, $snapshot->firstAnchor, $query);
        $this->assertNotNull($place);
        $this->assertLessThan(0, $place);
    }

    /**
     * Writes one pending email delivery of the seeded notification.
     *
     * @param string $createdAt Moment the delivery was made
     * @return int Id of the new delivery
     * @throws DatabaseException When the row cannot be written
     */
    private function insertDelivery(string $createdAt): int
    {
        Database::sql(
            'INSERT INTO `hilos_notification_delivery` (`notification_id`, `channel`, `status`, `created_at`) VALUES (?, ?, ?, ?)',
            [$this->notificationId, 'email', 'pending', $createdAt],
        );

        return (int) Database::sql('SELECT MAX(`id`) AS id FROM `hilos_notification_delivery`')->firstRow()['id'];
    }

    /**
     * Creates or drops the framework tables the journal reads.
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
