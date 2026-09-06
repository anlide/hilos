<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Table\DTO\TableAnchorDTO;
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
 * Integration test: a window taken by anchor costs the same at any depth and does not drift (HIL-787).
 *
 * Both claims are about the server and only the server can answer them. What a deep window costs
 * is not visible from the rows it returns — the same ten rows come back either way — so the test
 * reads what the engine says it touched. And a window that drifts does so between two statements
 * with a delete in between, which a sorted PHP array has no way to reproduce.
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
