<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableSearchField;
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
 * Integration test: a field declared as a mask is searched the same way in the database (HIL-1099).
 *
 * No table served from the database declares a mask today, the one that does holds its rows in
 * memory, so without this case the SQL half of the one declaration would be read by nobody. Only
 * the server can answer it: the pattern is handed to `LIKE`, and whether a star became `%` while a
 * typed percent sign stayed a sign is decided by the engine, not by the string built for it.
 */
final class ObjectsSearchMaskIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Scratch table the windows are taken from. */
    private const string TABLE = 'hilos_fw_test_search_mask';

    /** Labels the scratch table holds, keyed by the id each one is inserted under. */
    private const array LABELS = [
        1 => 'daemon-raw.log',
        2 => 'daemon.log',
        3 => 'agent-x.log',
    ];

    /**
     * Raises the scratch table with one row per label.
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
        foreach (self::LABELS as $id => $label) {
            Database::sql('INSERT INTO `' . self::TABLE . '` (`id`, `label`) VALUES (?, ?)', [$id, $label]);
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
     * @throws TableSearchNotSupportedException When the scratch declaration is empty, which it is not
     * @throws TableSearchFieldUnknownException When the scratch declaration names no column, which it does
     */
    public function testAStarredTermIsMatchedAgainstTheWholeValue(): void
    {
        self::assertSame([1], $this->idsFound('*-raw.log'));
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     * @throws TableSearchNotSupportedException When the scratch declaration is empty, which it is not
     * @throws TableSearchFieldUnknownException When the scratch declaration names no column, which it does
     */
    public function testATermWithNoStarIsStillAPieceOfTheValue(): void
    {
        self::assertSame([1], $this->idsFound('raw'));
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     * @throws TableSearchNotSupportedException When the scratch declaration is empty, which it is not
     * @throws TableSearchFieldUnknownException When the scratch declaration names no column, which it does
     */
    public function testATypedPercentSignStillStandsForItself(): void
    {
        self::assertSame([], $this->idsFound('%'));
    }

    /**
     * @throws DatabaseException When a window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     * @throws TableSearchNotSupportedException When the scratch declaration is empty, which it is not
     * @throws TableSearchFieldUnknownException When the scratch declaration names no column, which it does
     */
    public function testAStarAloneMatchesEveryValue(): void
    {
        self::assertSame(array_keys(self::LABELS), $this->idsFound('*'));
    }

    /**
     * Takes the whole set searched by one term over the label declared as a mask.
     *
     * @param string $search Term the window carries
     * @return list<int> Ids of the rows found, in key order
     * @throws DatabaseException When the window query fails
     * @throws InvalidArgumentException When an order direction is rejected
     * @throws TableSearchNotSupportedException When the declaration is empty
     * @throws TableSearchFieldUnknownException When the declaration names no column of the entity
     */
    private function idsFound(string $search): array
    {
        $page = SearchMaskTestObjects::initEmpty()->queryPage(new TableQueryDTO(
            search: $search,
            searchableFields: [SearchMaskTestRow::label => TableSearchField::mask(SearchMaskTestRow::label)],
        ));

        return array_map(
            static fn(int|string $key): int => (int) $key,
            array_keys($page[TableConstants::RESULT_KEY_OBJECTS]),
        );
    }
}

/**
 * Entity bound to the scratch table this test raises.
 */
final class SearchMaskTestRow extends Entity
{
    public const string id = 'id';
    public const string label = 'label';

    public const string _table = 'hilos_fw_test_search_mask';
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
final class SearchMaskTestObject extends Object_
{
    public const string ENTITY_CLASS = SearchMaskTestRow::class;
}

/**
 * @extends Objects<SearchMaskTestObject>
 */
final class SearchMaskTestObjects extends Objects
{
    public const string OBJECT_CLASS = SearchMaskTestObject::class;
}
