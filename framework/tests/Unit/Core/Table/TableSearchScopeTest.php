<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\HilosException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the search a table declares: what it reaches, and what it refuses (HIL-821).
 *
 * The declaration is one map read from both ends, so the cases below ask both: that it travels
 * into the query the row source is run with, which is what the database path reads, and that the
 * in-memory filter compares the fields it names and no others. The refusals are here for the same
 * reason they exist - a search nobody declared and a field nobody carries are both mistakes that
 * would otherwise show up as a window quietly holding fewer rows.
 */
final class TableSearchScopeTest extends TestCase
{
    /** Term the searching cases look for, carried by the label of one row only. */
    private const string TERM = 'beta';

    /**
     * @throws HilosException When the fixture table cannot serve its window
     */
    public function testTheDeclarationTravelsIntoTheQueryTheRowSourceIsRunWith(): void
    {
        $table = new SearchScopeUnitTable([SearchScopeUnitRow::LABEL => 'row_label']);

        $table->getPage(new TableQueryDTO(search: self::TERM));

        self::assertNotNull($table->received);
        self::assertSame([SearchScopeUnitRow::LABEL => 'row_label'], $table->received->searchableFields);
    }

    /**
     * @throws HilosException When the fixture table cannot serve its window
     */
    public function testAWindowThatSearchesNothingCarriesNoDeclaration(): void
    {
        $table = new SearchScopeUnitTable([SearchScopeUnitRow::LABEL => 'row_label']);

        $table->getPage(new TableQueryDTO());

        self::assertNotNull($table->received);
        self::assertSame([], $table->received->searchableFields);
    }

    /**
     * @throws HilosException When the fixture table cannot serve its window
     */
    public function testATableThatDeclaresNothingRefusesASearch(): void
    {
        $this->expectException(TableSearchNotSupportedException::class);

        (new SearchScopeUnitTable())->getPage(new TableQueryDTO(search: self::TERM));
    }

    /**
     * @throws HilosException When the fixture table cannot serve its window
     */
    public function testATermOfNothingButSpacesIsNoSearchAndIsNotRefused(): void
    {
        $table = new SearchScopeUnitTable();

        $snapshot = $table->getPage(new TableQueryDTO(search: '   '));

        self::assertCount(3, $snapshot->rows);
    }

    /**
     * @throws HilosException When the fixture table cannot serve its window
     */
    public function testTheSearchReadsTheDeclaredFieldAndNotTheOnesBesideIt(): void
    {
        $table = new SearchScopeUnitTable([SearchScopeUnitRow::LABEL => SearchScopeUnitRow::LABEL]);

        $snapshot = $table->getPage(new TableQueryDTO(search: self::TERM));

        // The third row carries the term in its note, which this table does not declare: the
        // window holds the one row whose declared field answers, and not the other.
        self::assertSame(['second'], self::keysOf($snapshot));
    }

    /**
     * @throws HilosException When the fixture table cannot serve its window
     */
    public function testADeclaredFieldNoRowOfTheSetCarriesIsRefused(): void
    {
        $table = new SearchScopeUnitTable(['nickname' => 'nickname']);

        $this->expectException(TableSearchFieldUnknownException::class);

        $table->getPage(new TableQueryDTO(search: self::TERM));
    }

    /**
     * @throws HilosException When the fixture table cannot serve its window
     */
    public function testATypedWildcardIsLookedForLiterally(): void
    {
        $table = new SearchScopeUnitTable([SearchScopeUnitRow::NOTE => SearchScopeUnitRow::NOTE]);

        $snapshot = $table->getPage(new TableQueryDTO(search: '%'));

        // A percent sign stands for a percent sign here as it does in the database, so it finds
        // the one note that carries it rather than every row of the set.
        self::assertSame(['first'], self::keysOf($snapshot));
    }

    /**
     * @throws HilosException When the fixture table cannot serve its window
     */
    public function testTheEdgesOfATermAreTrimmedAndItsInnerSpaceIsNot(): void
    {
        $table = new SearchScopeUnitTable([SearchScopeUnitRow::NOTE => SearchScopeUnitRow::NOTE]);

        self::assertSame(['third'], self::keysOf($table->getPage(new TableQueryDTO(search: "  {$this->phrase()}  "))));
        self::assertSame([], self::keysOf($table->getPage(new TableQueryDTO(search: str_replace(' ', '', $this->phrase())))));
    }

    /**
     * The two words the third row's note carries, with the space between them.
     *
     * @return string Phrase to search for
     */
    private function phrase(): string
    {
        return 'beta note';
    }

    /**
     * Reads the row keys out of a window, in the order it delivered them.
     *
     * @param TableSnapshotDTO $snapshot Window the fixture table served
     * @return list<string> Row keys of the window
     */
    private static function keysOf(TableSnapshotDTO $snapshot): array
    {
        return array_map(static fn(AbstractTableRow $row): string => (string) $row->getRowKey(), $snapshot->rows);
    }
}

/**
 * A table of three rows in memory whose searched fields the test declares.
 */
final class SearchScopeUnitTable extends TableDefinition
{
    /** Query the concrete table was handed, or null while getPage() has not run. */
    public ?TableQueryDTO $received = null;

    /** @var array<string, string> Searched fields this table declares */
    private array $declaredSearchableFields;

    /**
     * @param array<string, string> $declaredSearchableFields Searched fields the table declares
     */
    public function __construct(array $declaredSearchableFields = [])
    {
        $this->declaredSearchableFields = $declaredSearchableFields;

        parent::__construct();
    }

    /**
     * Configures the row class so makeRows() can rebuild typed rows.
     */
    protected function init(): void
    {
        $this->setRowClass(SearchScopeUnitRow::class);
    }

    /**
     * @return array<string, string> Searched fields injected by the test
     */
    protected function searchableFields(): array
    {
        return $this->declaredSearchableFields;
    }

    /**
     * Records the query the gate let through and serves the fixture rows out of memory.
     *
     * @param TableQueryDTO $query Window query, as the gate handed it over
     * @return TableSnapshotDTO Window over the fixture rows
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $this->received = $query;

        return $this->filterInMemory([
            ['key' => 'first', SearchScopeUnitRow::LABEL => 'alpha', SearchScopeUnitRow::NOTE => 'up 50% today'],
            ['key' => 'second', SearchScopeUnitRow::LABEL => 'beta', SearchScopeUnitRow::NOTE => 'nothing here'],
            ['key' => 'third', SearchScopeUnitRow::LABEL => 'gamma', SearchScopeUnitRow::NOTE => 'a beta note'],
        ], $query);
    }
}

/**
 * Row of the fixture table: a key, a field the table may declare, and one beside it.
 */
final class SearchScopeUnitRow extends AbstractTableRow
{
    /** Row field: the stable row key. */
    public const string KEY = 'key';

    /** Row field: the one the test usually declares searchable. */
    public const string LABEL = 'label';

    /** Row field: the one carrying text the declaration usually leaves out. */
    public const string NOTE = 'note';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $note,
    ) {
    }

    /**
     * @return string Stable row key
     */
    public function getRowKey(): string
    {
        return $this->key;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return self::KEY;
    }

    /**
     * @return array<string, mixed> Row fields
     */
    public function toArray(): array
    {
        return [
            self::KEY => $this->key,
            self::LABEL => $this->label,
            self::NOTE => $this->note,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Row instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            (string) $data[self::KEY],
            (string) $data[self::LABEL],
            (string) $data[self::NOTE],
        );
    }
}
