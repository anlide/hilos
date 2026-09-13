<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Environment\Exception\EnvException;

/**
 * Integration test: the count beside an option stops at the ceiling, and says so (HIL-240).
 *
 * Only the server can answer this one: the ceiling is a LIMIT inside a subquery, and whether the
 * count stops there and still tells the ceiling apart from a set of exactly that size is a question
 * about the SQL, not about the PHP around it.
 */
final class TableFacetCappedCountIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Scratch table the counts are taken over. */
    private const string TABLE = 'hilos_fw_test_facet_count';

    /** Filter key the scratch rows are counted by. */
    private const string KIND = 'kind';

    /** Option carried by more rows than the ceiling lets a count read. */
    private const string OVER_CEILING = 'bulk';

    /** Option carried by exactly as many rows as the ceiling, the last count that is still exact. */
    private const string AT_CEILING = 'edge';

    /** Option carried by a handful of rows. */
    private const string FEW = 'rare';

    /** Option no row carries. */
    private const string NONE = 'none';

    /** Rows past the ceiling the over-ceiling option carries. */
    private const int ROWS_PAST_CEILING = 20;

    /** Rows the few-rows option carries. */
    private const int FEW_ROWS = 3;

    /**
     * Raises the scratch table with rows of three kinds.
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
            . '`kind` VARCHAR(16) NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        $this->insert(self::OVER_CEILING, TableConstants::COUNT_CEILING + self::ROWS_PAST_CEILING);
        $this->insert(self::AT_CEILING, TableConstants::COUNT_CEILING);
        $this->insert(self::FEW, self::FEW_ROWS);
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
     * @throws DatabaseException When a count query fails
     */
    public function testAnOptionPastTheCeilingAnswersTheCeilingAndSaysItStoppedThere(): void
    {
        $options = $this->countKinds()[self::KIND][TableConstants::FACET_KEY_OPTIONS];

        self::assertSame(TableConstants::COUNT_CEILING, $options[self::OVER_CEILING]->count);
        self::assertFalse($options[self::OVER_CEILING]->exact);
    }

    /**
     * @throws DatabaseException When a count query fails
     */
    public function testAnOptionAtOrBelowTheCeilingAnswersItsExactSize(): void
    {
        $options = $this->countKinds()[self::KIND][TableConstants::FACET_KEY_OPTIONS];

        self::assertSame(TableConstants::COUNT_CEILING, $options[self::AT_CEILING]->count);
        self::assertTrue($options[self::AT_CEILING]->exact);
        self::assertSame(self::FEW_ROWS, $options[self::FEW]->count);
        self::assertTrue($options[self::FEW]->exact);
        self::assertSame(0, $options[self::NONE]->count);
        self::assertTrue($options[self::NONE]->exact);
    }

    /**
     * @throws DatabaseException When a count query fails
     */
    public function testTheAnyCountIsTheWholeSetUnderTheSameCeiling(): void
    {
        $any = $this->countKinds()[self::KIND][TableConstants::FACET_KEY_ANY];

        self::assertSame(TableConstants::COUNT_CEILING, $any->count);
        self::assertFalse($any->exact);
    }

    /**
     * Counts every kind of scratch row the way a SQL table counts the options of its filter.
     *
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Counts by filter key
     * @throws DatabaseException When a count query fails
     */
    private function countKinds(): array
    {
        return TableFacetTally::forFilters(
            new TableQueryDTO(),
            [self::KIND => [self::OVER_CEILING, self::AT_CEILING, self::FEW, self::NONE]],
            static function (TableQueryDTO $set): TableFacetCountDTO {
                $kind = $set->filter[self::KIND] ?? null;

                return $kind === null
                    ? TableFacetTally::cappedSqlCount('`' . self::TABLE . '`', '', [])
                    : TableFacetTally::cappedSqlCount('`' . self::TABLE . '`', ' WHERE `kind` = ?', [$kind]);
            },
        );
    }

    /**
     * Inserts rows of one kind in a single statement.
     *
     * @param string $kind Kind the rows carry
     * @param int $count Rows to insert
     * @throws DatabaseException When the insert fails
     */
    private function insert(string $kind, int $count): void
    {
        Database::sql(
            'INSERT INTO `' . self::TABLE . '` (`kind`) VALUES ' . implode(',', array_fill(0, $count, '(?)')),
            array_fill(0, $count, $kind),
        );
    }
}
