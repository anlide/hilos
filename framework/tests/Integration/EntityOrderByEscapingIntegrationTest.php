<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\PhpType;
use Hilos\Database\SqlSortDirection;
use Hilos\Environment\Exception\EnvException;

/**
 * Integration test: a column name in ORDER BY stays one column name (HIL-561).
 *
 * The whitelists above the ORM decide which names may be ordered by; this is the
 * backstop under them, and only a real server can say whether it held. A name carrying
 * its own backtick is asked of a live MariaDB: the answer must be that no such column
 * exists — that is, the tail after the quote was read as part of the name and never ran
 * as SQL. A missing escape would make the same statement parse and succeed, which is why
 * the assertion is on the failure.
 */
final class EntityOrderByEscapingIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Scratch table the ordering is asked of. */
    private const string TABLE = 'hilos_fw_test_order_by';

    /** A field name that tries to close the quoted identifier and continue the statement. */
    private const string INJECTED_FIELD = 'name` DESC, (SELECT 1)';

    /**
     * Raises the scratch table with two rows the ordering is visible on.
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
            . '`name` VARCHAR(32) NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        Database::sql('INSERT INTO `' . self::TABLE . '` (`name`) VALUES (?), (?)', ['alpha', 'beta']);
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
     * @throws DatabaseException When the ordered query fails
     */
    public function testAnOrdinaryColumnStillOrdersTheRows(): void
    {
        $entities = EntityOrderByEscapingTestRow::get(
            orderBy: [EntityOrderByEscapingTestRow::name => SqlSortDirection::DESC],
        );

        $names = [];
        foreach ($entities as $entity) {
            $names[] = $entity->name;
        }

        self::assertSame(['beta', 'alpha'], $names);
    }

    /**
     * @throws DatabaseException When the ordered query fails for a reason other than the unknown column
     */
    public function testAnInjectedTailIsQuotedIntoTheColumnNameAndNeverRuns(): void
    {
        $this->expectException(DatabaseException::class);
        // The server reports the whole payload as one missing column: it reached the
        // parser as a name, not as `ORDER BY name DESC, (SELECT 1)`.
        $this->expectExceptionMessage('Unknown column');

        EntityOrderByEscapingTestRow::get(orderBy: [self::INJECTED_FIELD => SqlSortDirection::ASC]);
    }

    /**
     * @throws DatabaseException When the ordered query fails
     */
    public function testADirectionOutsideTheTwoKeywordsIsTheCallersError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EntityOrderByEscapingTestRow::get(
            orderBy: [EntityOrderByEscapingTestRow::name => 'ASC, (SELECT 1)'],
        );
    }
}

/**
 * Entity bound to the scratch table this test raises.
 *
 * It declares no typed collection of its own: the base `get()` and its `EntityCollection`
 * are exactly what is under test here.
 */
final class EntityOrderByEscapingTestRow extends Entity
{
    public const string id = 'id';
    public const string name = 'name';

    public const string _table = 'hilos_fw_test_order_by';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::name,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
    ];

    public ?int $id = null;
    public string $name = '';
}
