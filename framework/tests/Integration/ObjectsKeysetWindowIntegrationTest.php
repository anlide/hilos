<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableWindowFrameDTO;
use Hilos\Core\Table\TableAnchorDirection;
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
 * Integration test: a window taken by anchor costs the same at any depth, does not drift (HIL-787),
 * says where in the set it sits (HIL-1093), and knows the places framing it (HIL-1037).
 *
 * All three claims are about the server and only the server can answer them. What a deep window
 * costs is not visible from the rows it returns — the same ten rows come back either way — so the
 * test reads what the engine says it touched. A window that drifts does so between two statements
 * with a delete in between, which a sorted PHP array has no way to reproduce. And the place a
 * window sits at is the one answer here that is a second query: it is counted against the same
 * set the window was cut from, so the set has to be a real one.
 *
 * The frame is taken by the same query as the window, one row further on each side it has to
 * be read for, so the frame cases also hold that those extra rows never leak into the window:
 * its rows, its boundary anchors and its place stay what they were before the frame existed.
 */
final class ObjectsKeysetWindowIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Scratch table the windows are taken from. */
    private const string TABLE = 'hilos_fw_test_keyset_window';

    /** Rows the scratch table holds, deep enough that a skipped prefix would dwarf a page. */
    private const int ROW_COUNT = 500;

    /** Page size every window in this test asks for. */
    private const int PAGE_SIZE = 10;

    /** Row the deep window is anchored at: far enough in that a skip to it would be unmissable. */
    private const int DEEP_ANCHOR_ID = 450;

    /** Row the shallow window is anchored at, the deep window's control. */
    private const int SHALLOW_ANCHOR_ID = 10;

    /**
     * How much more the deep window may read than the shallow one before the two stop being equal.
     *
     * Both windows pay for the same COUNT(*) over the whole set, and the status counters move a
     * little on their own as they are read, so an exact equality would be measuring the meter.
     * The bound is far below the difference a skipped prefix would make — that one is the whole
     * distance between the two anchors.
     */
    private const int READ_TOLERANCE = 50;

    /**
     * Label of the one row that puts the set past the ceiling its count stops at.
     *
     * The claim that a window costs the same at any depth is measured past that ceiling on
     * purpose. Under an exact count the window also pays for its own place in the set (HIL-1093),
     * and that price is a scan up to the window: bounded by the ceiling, but not flat in depth.
     * Past the ceiling there is no place to report and the window pays for nothing but itself,
     * which is the regime the claim was written for — it is deep sets a skipped prefix ruins.
     *
     * That the place costs nothing past the ceiling is what this measurement proves; that it is
     * paid for under an exact count is left to the value tests, because a set that small has no
     * price worth metering and a bound written as a number would only pin the meter.
     */
    private const string ROW_PAST_CEILING = 'row-past-ceiling';

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
        for ($id = 1; $id <= self::ROW_COUNT; $id++) {
            Database::sql('INSERT INTO `' . self::TABLE . '` (`label`) VALUES (?)', ["row-{$id}"]);
        }
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
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testADeepWindowReadsNoMoreThanAShallowOne(): void
    {
        $this->pushSetPastTheCountCeiling();

        $shallowReads = $this->readsWhileTaking(self::SHALLOW_ANCHOR_ID);
        $deepReads = $this->readsWhileTaking(self::DEEP_ANCHOR_ID);

        self::assertLessThanOrEqual(
            $shallowReads + self::READ_TOLERANCE,
            $deepReads,
            'A deep window read like it had walked to its place rather than been pointed at it',
        );
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testDeletingARowAboveTheWindowLeavesItsRowsWhereTheyWere(): void
    {
        $before = $this->windowFrom(self::DEEP_ANCHOR_ID);
        Database::sql('DELETE FROM `' . self::TABLE . '` WHERE `id` = ?', [self::DEEP_ANCHOR_ID - 1]);
        $after = $this->windowFrom(self::DEEP_ANCHOR_ID);

        self::assertSame(
            range(self::DEEP_ANCHOR_ID + 1, self::DEEP_ANCHOR_ID + self::PAGE_SIZE),
            $before,
            'The window did not start where its anchor pointed',
        );
        self::assertSame($before, $after);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testDeletingTheAnchorRowItselfLeavesTheWindowWhereItWas(): void
    {
        $before = $this->windowFrom(self::DEEP_ANCHOR_ID);
        Database::sql('DELETE FROM `' . self::TABLE . '` WHERE `id` = ?', [self::DEEP_ANCHOR_ID]);
        $after = $this->windowFrom(self::DEEP_ANCHOR_ID);

        self::assertSame($before, $after);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testAWindowTakenAfterAnAnchorSaysHowManyRowsStandBeforeIt(): void
    {
        $page = $this->pageFrom(self::DEEP_ANCHOR_ID);

        self::assertSame(self::DEEP_ANCHOR_ID, $page[TableConstants::RESULT_KEY_ROWS_BEFORE]);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testARowDeletedAboveTheWindowMovesThePlaceItReports(): void
    {
        Database::sql('DELETE FROM `' . self::TABLE . '` WHERE `id` = ?', [self::DEEP_ANCHOR_ID - 1]);

        $page = $this->pageFrom(self::DEEP_ANCHOR_ID);

        self::assertSame(self::DEEP_ANCHOR_ID - 1, $page[TableConstants::RESULT_KEY_ROWS_BEFORE]);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testTheFirstWindowOfTheSetStandsAtItsStartWithoutBeingCountedFor(): void
    {
        $page = KeysetWindowTestObjects::initEmpty()->queryPage(new TableQueryDTO(limit: self::PAGE_SIZE));

        self::assertSame(0, $page[TableConstants::RESULT_KEY_ROWS_BEFORE]);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testANumberedPageStandsWhereThePagesBeforeItEnd(): void
    {
        $page = KeysetWindowTestObjects::initEmpty()->queryPage(
            new TableQueryDTO(limit: self::PAGE_SIZE, pageIndex: 3),
        );

        self::assertSame(3 * self::PAGE_SIZE, $page[TableConstants::RESULT_KEY_ROWS_BEFORE]);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testAnEmptyWindowAskedForPastTheEndStandsBehindTheWholeSet(): void
    {
        $page = $this->pageFrom(self::ROW_COUNT);

        self::assertSame([], $page[TableConstants::RESULT_KEY_OBJECTS]);
        self::assertSame(self::ROW_COUNT, $page[TableConstants::RESULT_KEY_ROWS_BEFORE]);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testAnEmptyWindowAskedForBeforeTheStartStandsAtTheStart(): void
    {
        $page = KeysetWindowTestObjects::initEmpty()->queryPage(new TableQueryDTO(
            limit: self::PAGE_SIZE,
            anchor: new TableAnchorDTO([KeysetWindowTestRow::id => 1]),
            anchorDirection: TableAnchorDirection::Before,
        ));

        self::assertSame([], $page[TableConstants::RESULT_KEY_OBJECTS]);
        self::assertSame(0, $page[TableConstants::RESULT_KEY_ROWS_BEFORE]);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testASetPastTheCountCeilingReportsNoPlaceForItsWindow(): void
    {
        $this->pushSetPastTheCountCeiling();
        $page = $this->pageFrom(self::DEEP_ANCHOR_ID);

        self::assertFalse($page[TableConstants::RESULT_KEY_TOTAL_EXACT]);
        self::assertNull($page[TableConstants::RESULT_KEY_ROWS_BEFORE]);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testAWindowTakenAfterAnAnchorIsFramedBeforeByThatAnchor(): void
    {
        $anchor = new TableAnchorDTO([KeysetWindowTestRow::id => self::DEEP_ANCHOR_ID]);

        $page = KeysetWindowTestObjects::initEmpty()->queryPage(new TableQueryDTO(limit: self::PAGE_SIZE, anchor: $anchor));

        $this->assertWindow($page, self::DEEP_ANCHOR_ID + 1, self::DEEP_ANCHOR_ID + self::PAGE_SIZE);
        self::assertSame(self::DEEP_ANCHOR_ID, $page[TableConstants::RESULT_KEY_ROWS_BEFORE]);
        self::assertSame($anchor, $this->frameOf($page)->before);
        self::assertSame(self::DEEP_ANCHOR_ID + self::PAGE_SIZE + 1, $this->placeId($this->frameOf($page)->after));
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testAWindowTakenBackFromAnAnchorIsFramedAfterByThatAnchor(): void
    {
        $anchor = new TableAnchorDTO([KeysetWindowTestRow::id => self::DEEP_ANCHOR_ID + 1]);

        $page = KeysetWindowTestObjects::initEmpty()->queryPage(new TableQueryDTO(
            limit: self::PAGE_SIZE,
            anchor: $anchor,
            anchorDirection: TableAnchorDirection::Before,
        ));

        $this->assertWindow($page, self::DEEP_ANCHOR_ID - self::PAGE_SIZE + 1, self::DEEP_ANCHOR_ID);
        self::assertSame(self::DEEP_ANCHOR_ID - self::PAGE_SIZE, $page[TableConstants::RESULT_KEY_ROWS_BEFORE]);
        self::assertSame(self::DEEP_ANCHOR_ID - self::PAGE_SIZE, $this->placeId($this->frameOf($page)->before));
        self::assertSame($anchor, $this->frameOf($page)->after);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testANumberedPageInTheNearHalfIsFramedByTheRowsOnEitherSide(): void
    {
        $page = $this->numberedPage(3);

        $this->assertWindow($page, 3 * self::PAGE_SIZE + 1, 4 * self::PAGE_SIZE);
        self::assertSame(3 * self::PAGE_SIZE, $page[TableConstants::RESULT_KEY_ROWS_BEFORE]);
        $this->assertFrame(3 * self::PAGE_SIZE, 4 * self::PAGE_SIZE + 1, $page);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testANumberedPageCountedFromTheEndIsFramedByTheRowsOnEitherSide(): void
    {
        $page = $this->numberedPage(40);

        $this->assertWindow($page, 40 * self::PAGE_SIZE + 1, 41 * self::PAGE_SIZE);
        self::assertSame(40 * self::PAGE_SIZE, $page[TableConstants::RESULT_KEY_ROWS_BEFORE]);
        $this->assertFrame(40 * self::PAGE_SIZE, 41 * self::PAGE_SIZE + 1, $page);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testTheFirstNumberedPageHasNothingBeforeIt(): void
    {
        $page = $this->numberedPage(0);

        $this->assertWindow($page, 1, self::PAGE_SIZE);
        $this->assertFrame(null, self::PAGE_SIZE + 1, $page);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testTheLastNumberedPageHasNothingAfterIt(): void
    {
        $lastPage = intdiv(self::ROW_COUNT, self::PAGE_SIZE) - 1;

        $page = $this->numberedPage($lastPage);

        $this->assertWindow($page, self::ROW_COUNT - self::PAGE_SIZE + 1, self::ROW_COUNT);
        $this->assertFrame(self::ROW_COUNT - self::PAGE_SIZE, null, $page);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testAWindowWithoutALimitIsFramedByBothEdgesOfTheSet(): void
    {
        $page = KeysetWindowTestObjects::initEmpty()->queryPage(new TableQueryDTO());

        $this->assertWindow($page, 1, self::ROW_COUNT);
        $this->assertFrame(null, null, $page);
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testAnEmptyWindowReportsNoFrame(): void
    {
        self::assertNull($this->pageFrom(self::ROW_COUNT)[TableConstants::RESULT_KEY_FRAME]);
        self::assertNull($this->numberedPage(self::ROW_COUNT)[TableConstants::RESULT_KEY_FRAME]);
    }

    /**
     * Takes one numbered page of the set.
     *
     * @param int $pageIndex Zero-based page to jump to
     * @return array<string, mixed> Result of the page query, in the shape {@see Objects::queryPage()} answers
     * @throws DatabaseException When the window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    private function numberedPage(int $pageIndex): array
    {
        return KeysetWindowTestObjects::initEmpty()->queryPage(
            new TableQueryDTO(limit: self::PAGE_SIZE, pageIndex: $pageIndex),
        );
    }

    /**
     * Asserts that a window holds exactly one unbroken run of rows, and that its anchors stand on that run.
     *
     * @param array<string, mixed> $page Result of the page query
     * @param int $firstId Row the window has to start with
     * @param int $lastId Row the window has to end with
     */
    private function assertWindow(array $page, int $firstId, int $lastId): void
    {
        self::assertSame(
            range($firstId, $lastId),
            array_map(intval(...), array_keys($page[TableConstants::RESULT_KEY_OBJECTS])),
        );
        self::assertSame($firstId, $this->placeId($page[TableConstants::RESULT_KEY_FIRST_ANCHOR]));
        self::assertSame($lastId, $this->placeId($page[TableConstants::RESULT_KEY_LAST_ANCHOR]));
    }

    /**
     * Asserts the places framing a window, by the row each one stands at.
     *
     * @param ?int $beforeId Row standing right before the window, or null for the start of the set
     * @param ?int $afterId Row standing right after the window, or null for the end of the set
     * @param array<string, mixed> $page Result of the page query
     */
    private function assertFrame(?int $beforeId, ?int $afterId, array $page): void
    {
        $frame = $this->frameOf($page);

        self::assertSame($beforeId, $this->placeId($frame->before));
        self::assertSame($afterId, $this->placeId($frame->after));
    }

    /**
     * Reads the frame a page query reported, failing the test when it reported none.
     *
     * @param array<string, mixed> $page Result of the page query
     * @return TableWindowFrameDTO Places framing the window
     */
    private function frameOf(array $page): TableWindowFrameDTO
    {
        $frame = $page[TableConstants::RESULT_KEY_FRAME];
        self::assertInstanceOf(TableWindowFrameDTO::class, $frame);

        return $frame;
    }

    /**
     * Reads the row a place stands at in the id order.
     *
     * @param mixed $place Place reported for the window, or null
     * @return ?int Id of the row it names, or null when there is no place
     */
    private function placeId(mixed $place): ?int
    {
        if ($place === null) {
            return null;
        }
        self::assertInstanceOf(TableAnchorDTO::class, $place);

        return (int) $place->values[KeysetWindowTestRow::id];
    }

    /**
     * Adds the one row that takes the set past the ceiling its count stops at.
     *
     * @throws DatabaseException When the insert fails
     */
    private function pushSetPastTheCountCeiling(): void
    {
        Database::sql('INSERT INTO `' . self::TABLE . '` (`label`) VALUES (?)', [self::ROW_PAST_CEILING]);
    }

    /**
     * Takes one window anchored at the given row.
     *
     * @param int $anchorId Row the window is taken after
     * @return array<string, mixed> Result of the page query, in the shape {@see Objects::queryPage()} answers
     * @throws DatabaseException When the window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    private function pageFrom(int $anchorId): array
    {
        return KeysetWindowTestObjects::initEmpty()->queryPage(new TableQueryDTO(
            limit: self::PAGE_SIZE,
            anchor: new TableAnchorDTO([KeysetWindowTestRow::id => $anchorId]),
        ));
    }

    /**
     * Takes one window anchored at the given row and returns the keys it delivered.
     *
     * @param int $anchorId Row the window is taken after
     * @return list<int> Row keys of the window, in the order it delivered them
     * @throws DatabaseException When the window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    private function windowFrom(int $anchorId): array
    {
        $page = KeysetWindowTestObjects::initEmpty()->queryPage(new TableQueryDTO(
            limit: self::PAGE_SIZE,
            anchor: new TableAnchorDTO([KeysetWindowTestRow::id => $anchorId]),
        ));

        return array_map(intval(...), array_keys($page[TableConstants::RESULT_KEY_OBJECTS]));
    }

    /**
     * Counts the rows the engine reports having touched while one window was taken.
     *
     * @param int $anchorId Row the window is taken after
     * @return int Rows read through the handler while the window was built
     * @throws DatabaseException When the window or the status query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    private function readsWhileTaking(int $anchorId): int
    {
        $before = $this->handlerReads();
        $this->windowFrom($anchorId);

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
final class KeysetWindowTestRow extends Entity
{
    public const string id = 'id';
    public const string label = 'label';

    public const string _table = 'hilos_fw_test_keyset_window';
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
final class KeysetWindowTestObject extends Object_
{
    public const string ENTITY_CLASS = KeysetWindowTestRow::class;
}

/**
 * @extends Objects<KeysetWindowTestObject>
 */
final class KeysetWindowTestObjects extends Objects
{
    public const string OBJECT_CLASS = KeysetWindowTestObject::class;
}
