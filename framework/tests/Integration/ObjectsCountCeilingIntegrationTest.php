<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use Hilos\Environment\Exception\EnvException;

/**
 * Integration test: a windowed count stops at its ceiling instead of walking the whole set (HIL-788).
 *
 * Only the server can answer this. The rows a window returns look the same either way — the claim
 * is about what the database was made to read to answer it — so the test reads the engine's own
 * counters, the way the neighbouring keyset test does. The claim is put as a comparison rather
 * than as an absolute number on purpose: the counters also count the rows of the temporary table
 * the capped subquery is materialized into, so what a single window "should" read is an accounting
 * question, while "reading twice the rows must not cost more" is the property itself.
 *
 * The other half is the plain one: under the ceiling the number is the size of the set and says so.
 */
final class ObjectsCountCeilingIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Scratch table the windows are taken from. */
    private const string TABLE = 'hilos_fw_test_count_ceiling';

    /** Rows the scratch table starts with: twice the ceiling, so the count is already stopping. */
    private const int ROW_COUNT = TableConstants::COUNT_CEILING * 2;

    /** Rows left behind for the exact-count case, comfortably under the ceiling. */
    private const int SMALL_SET = 120;

    /** Page size every window in this test asks for. */
    private const int PAGE_SIZE = 10;

    /** Rows per INSERT while the scratch table is raised, so a large set costs few statements. */
    private const int INSERT_BATCH = 200;

    /**
     * How much more a window over the doubled set may read before the two stop being equal.
     *
     * The counters move a little on their own as they are read, so an exact equality would be
     * measuring the meter. The bound is far below the difference walking the set would make —
     * that one is the whole second half of it, a thousand rows.
     */
    private const int READ_TOLERANCE = 50;

    /**
     * Raises the scratch table with an unbroken run of rows.
     *
     * @throws EnvException When env variables are missing or invalid
     * @throws DatabaseConnectionException When connect fails
     * @throws CantConnectToMysqlServerException When connect retries are exhausted
     * @throws DatabaseException When the scratch schema cannot be raised
     */
    protected function setUp(): void
    {
        parent::setUp();

        Database::sql('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        Database::sql(
            'CREATE TABLE `' . self::TABLE . '` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . '`label` VARCHAR(32) NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        $this->addRows(self::ROW_COUNT);
    }

    /**
     * Drops the scratch table before the connection is closed by the parent.
     *
     * @throws DatabaseException When the scratch table cannot be dropped
     */
    protected function tearDown(): void
    {
        if (Database::isConnected()) {
            Database::sql('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        }

        parent::tearDown();
    }

    /**
     * @throws DatabaseException When a window query or the second fill fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testDoublingTheSetDoesNotMakeItsWindowReadMore(): void
    {
        $overCeiling = $this->readsWhileTakingWindow();
        $this->addRows(self::ROW_COUNT);
        $farOverCeiling = $this->readsWhileTakingWindow();

        self::assertLessThanOrEqual(
            $overCeiling + self::READ_TOLERANCE,
            $farOverCeiling,
            'The window read its way through the whole set instead of stopping at the ceiling',
        );
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testASetLargerThanTheCeilingReportsTheCeilingAndSaysItIsNotExact(): void
    {
        $page = $this->window();

        self::assertSame(TableConstants::COUNT_CEILING, $page[TableConstants::RESULT_KEY_TOTAL_COUNT]);
        self::assertFalse($page[TableConstants::RESULT_KEY_TOTAL_EXACT]);
    }

    /**
     * @throws DatabaseException When a window query or the trim fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testASetSmallerThanTheCeilingIsCountedExactly(): void
    {
        Database::sql('DELETE FROM `' . self::TABLE . '` WHERE `id` > ?', [self::SMALL_SET]);

        $page = $this->window();

        self::assertSame(self::SMALL_SET, $page[TableConstants::RESULT_KEY_TOTAL_COUNT]);
        self::assertTrue($page[TableConstants::RESULT_KEY_TOTAL_EXACT]);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testAQueryWithNoWindowCountsTheWholeSet(): void
    {
        $page = CountCeilingTestObjects::initEmpty()->queryPage(new TableQueryDTO());

        // Reading every row is what an unbounded query does anyway, so there is nothing the
        // ceiling would save here — and reporting it would understate a number already in hand.
        self::assertSame(self::ROW_COUNT, $page[TableConstants::RESULT_KEY_TOTAL_COUNT]);
        self::assertTrue($page[TableConstants::RESULT_KEY_TOTAL_EXACT]);
    }

    /**
     * Appends rows to the scratch table, in batches so a large set costs few statements.
     *
     * @param int $count Rows to append
     * @throws DatabaseException When an insert fails
     */
    private function addRows(int $count): void
    {
        for ($added = 0; $added < $count; $added += self::INSERT_BATCH) {
            $batch = min(self::INSERT_BATCH, $count - $added);
            $values = implode(', ', array_fill(0, $batch, '(?)'));
            $labels = [];
            for ($index = 0; $index < $batch; $index++) {
                $labels[] = 'row-' . ($added + $index);
            }
            Database::sql('INSERT INTO `' . self::TABLE . '` (`label`) VALUES ' . $values, $labels);
        }
    }

    /**
     * Takes the first window of the scratch table.
     *
     * @return array<string, mixed> Window result, with its rows and the count that came with them
     * @throws DatabaseException When the window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    private function window(): array
    {
        return CountCeilingTestObjects::initEmpty()->queryPage(new TableQueryDTO(limit: self::PAGE_SIZE));
    }

    /**
     * Counts the rows the engine reports having touched while one window was taken.
     *
     * @return int Rows read through the handler while the window was built
     * @throws DatabaseException When the window or the status query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    private function readsWhileTakingWindow(): int
    {
        $before = $this->handlerReads();
        $this->window();

        return $this->handlerReads() - $before;
    }

    /**
     * Sum of the engine's row-reading counters for this session.
     *
     * @return int Rows read through the handler since the session opened
     * @throws DatabaseException When the status query fails
     */
    private function handlerReads(): int
    {
        $total = 0;
        foreach (Database::sql("SHOW SESSION STATUS LIKE 'Handler_read_%'")->rows() as $row) {
            $total += (int) $row['Value'];
        }

        return $total;
    }
}

/**
 * Entity bound to the scratch table this test raises.
 */
final class CountCeilingTestRow extends Entity
{
    public const string id = 'id';
    public const string label = 'label';

    public const string _table = 'hilos_fw_test_count_ceiling';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::label,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::label => PhpType::STRING->value,
    ];

    public ?int $id = null;
    public string $label = '';
}

/**
 * Minimal stored row over that entity: the window query only ever reads it.
 */
final class CountCeilingTestObject extends Object_
{
    public const string ENTITY_CLASS = CountCeilingTestRow::class;
}

/**
 * @extends Objects<CountCeilingTestObject>
 */
final class CountCeilingTestObjects extends Objects
{
    public const string OBJECT_CLASS = CountCeilingTestObject::class;
}
