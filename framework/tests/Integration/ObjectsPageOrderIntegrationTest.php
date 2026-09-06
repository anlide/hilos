<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
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
 * Integration test: a page order settled by the primary key never shows a row twice (HIL-786).
 *
 * The claim under test is about two statements, not about one: page N and page N+1 are separate
 * queries, and only a server can say whether the order held between them. A sorted column with
 * repeats is where it does not hold on its own — the rows inside one repeated value may come
 * back in any order the engine likes, so a row can land on both pages and another on neither.
 * That is why the walk below is asked of a live MariaDB and not of a sorted PHP array.
 */
final class ObjectsPageOrderIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Scratch table the page order is asked of. */
    private const string TABLE = 'hilos_fw_test_page_order';

    /** Page size the walk uses, chosen so the six rows take three pages. */
    private const int PAGE_SIZE = 2;

    /**
     * @var list<string> States the six scratch rows carry, in id order: each value repeats three
     *     times, and the two values interleave so that ordering by state alone has to move rows.
     */
    private const array STATES = ['live', 'idle', 'live', 'idle', 'live', 'idle'];

    /**
     * Raises the scratch table with six rows over two repeated states.
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
            . '`state` VARCHAR(32) NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        foreach (self::STATES as $state) {
            Database::sql('INSERT INTO `' . self::TABLE . '` (`state`) VALUES (?)', [$state]);
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
     * @throws DatabaseException When a page query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testWalkingEveryPageOfARepeatedColumnShowsEachRowExactlyOnce(): void
    {
        $keys = $this->walkPages(new TableSortDTO(PageOrderTestRow::state, TableConstants::ORDER_ASC));

        self::assertSame([2, 4, 6, 1, 3, 5], $keys);
    }

    /**
     * The descending walk is the one that fails without the tie-breaker: with `state` alone in
     * ORDER BY the engine is free to hand back each repeated value in ascending id order, which
     * looks like a stable answer right up to the page boundary that splits a repeated value.
     *
     * @throws DatabaseException When a page query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testTheTieBreakerFollowsTheDirectionOfTheSortedColumn(): void
    {
        $keys = $this->walkPages(new TableSortDTO(PageOrderTestRow::state, TableConstants::ORDER_DESC));

        self::assertSame([5, 3, 1, 6, 4, 2], $keys);
    }

    /**
     * @throws DatabaseException When a page query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    public function testAWindowThatAsksForNoOrderStillGetsOne(): void
    {
        $first = $this->walkPages(null);
        $second = $this->walkPages(null);

        self::assertSame([1, 2, 3, 4, 5, 6], $first);
        self::assertSame($first, $second);
    }

    /**
     * Walks every page of the scratch table and collects the row keys in the order they arrived.
     *
     * Each page is asked for from the boundary of the one before it, which is how a client pages
     * now: two statements again, and the order has to hold between them for the same reason.
     *
     * @param ?TableSortDTO $sort Ordering to ask each page for, or null to ask for none
     * @return list<int> Row keys across all pages, in the order the pages delivered them
     * @throws DatabaseException When a page query fails
     * @throws InvalidArgumentException When an order direction is rejected
     */
    private function walkPages(?TableSortDTO $sort): array
    {
        $objects = PageOrderTestObjects::initEmpty();

        $keys = [];
        $anchor = null;
        do {
            $page = $objects->queryPage(new TableQueryDTO(
                sort: $sort,
                limit: self::PAGE_SIZE,
                anchor: $anchor,
            ));
            $delivered = array_keys($page[TableConstants::RESULT_KEY_OBJECTS]);
            foreach ($delivered as $key) {
                $keys[] = (int) $key;
            }
            $anchor = $page[TableConstants::RESULT_KEY_LAST_ANCHOR];
        } while (count($delivered) === self::PAGE_SIZE);

        return $keys;
    }
}

/**
 * Entity bound to the scratch table this test raises.
 */
final class PageOrderTestRow extends Entity
{
    public const string id = 'id';
    public const string state = 'state';

    public const string _table = 'hilos_fw_test_page_order';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::state,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::state => PhpType::STRING->value,
    ];

    public ?int $id = null;
    public string $state = '';
}

/**
 * Minimal stored row over that entity: the page query only ever reads it.
 */
final class PageOrderTestObject extends Object_
{
    public const string ENTITY_CLASS = PageOrderTestRow::class;
}

/**
 * @extends Objects<PageOrderTestObject>
 */
final class PageOrderTestObjects extends Objects
{
    public const string OBJECT_CLASS = PageOrderTestObject::class;
}
