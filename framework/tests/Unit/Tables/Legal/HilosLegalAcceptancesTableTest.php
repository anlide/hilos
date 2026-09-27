<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Tables\Legal;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableSortWhitelist;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Tables\Legal\AbstractHilosLegalAcceptancesTable;
use Hilos\Tables\Legal\HilosLegalAcceptanceTableRow;
use PHPUnit\Framework\TestCase;

/** The SQL window's sort, filters and live-row positions share one set definition. */
final class HilosLegalAcceptancesTableTest extends TestCase
{
    public function testOnlyTheAcceptanceTimestampCanOrderTheWindow(): void
    {
        $table = new LegalAcceptancesTableProbe();
        self::assertSame(['acceptedAt' => 'accepted_at'], $table->columns());
        self::assertSame(['accepted_at' => 'DESC', 'id' => 'DESC'], $table->order(new TableQueryDTO()));
        self::assertSame('acceptedAt', $table->defaultSort()->components[0]->field);
        foreach ([TableConstants::ORDER_ASC => 'ASC', TableConstants::ORDER_DESC => 'DESC'] as $direction => $sql) {
            $sort = TableSortWhitelist::resolve(
                TableSortOrderDTO::of(new TableSortDTO('acceptedAt', $direction)),
                $table->columns(),
                $table::class,
            );
            self::assertSame(['accepted_at' => $sql, 'id' => $sql], $table->order(new TableQueryDTO(sort: $sort)));
        }
    }

    public function testDocumentAndRevisionAreBoundTogetherAndSearchEscapesLiteralWildcards(): void
    {
        $table = new LegalAcceptancesTableProbe();
        $table->namedIds = [41, 72];
        [$where, $params] = $table->where($table->scopeSearch(new TableQueryDTO(
            search: ' 50%_off ',
            filter: ['document' => 'terms', 'revision' => "r' OR 1=1"],
        )));
        self::assertStringContainsString('document = ? AND revision_id = ?', $where);
        self::assertStringContainsString('FROM hilos_identity WHERE identifier LIKE', $where);
        self::assertStringContainsString('OR user_id IN (?, ?)', $where);
        self::assertSame(['terms', "r' OR 1=1", '%50!%!_off%', 41, 72], $params);
        self::assertSame(['50%_off'], $table->searched);
        self::assertStringNotContainsString("r'", $where);
    }

    public function testLivePositionsUseSqlColumnsAndNumericIdTies(): void
    {
        $table = new LegalAcceptancesTableProbe();
        $row = new HilosLegalAcceptanceTableRow(10, 42, 'Person', null, 'terms', 'gone', null, '2026-09-27 12:00:00');
        $query = new TableQueryDTO(sort: TableSortOrderDTO::of(new TableSortDTO('acceptedAt', TableConstants::ORDER_DESC, 'accepted_at')));
        self::assertSame(['accepted_at' => '2026-09-27 12:00:00', 'id' => 10], $table->anchorForRow($row, $query)->values);
        self::assertLessThan(0, $table->placeRowAgainst($row, new TableAnchorDTO([
            'accepted_at' => '2026-09-27 12:00:00', 'id' => '9',
        ]), $query));
        self::assertSame(['acceptance' => $row->toArray()], $table->browserRow($row)['sources']);
        foreach ([true, false, null] as $declared) {
            $row->declared = $declared;
            self::assertSame($row->toArray(), HilosLegalAcceptanceTableRow::fromArray($row->toArray())->toArray());
        }
    }

    public function testDeletionNeedsNoSqlAndOtherSourcesAreIgnored(): void
    {
        $table = new LegalAcceptancesTableProbe();
        $mutation = $table->buildMutationForSourceEvent(new SourceChange(
            SourceChange::KIND_DB, HilosDbContext::legalAcceptances, '42', TableMutationType::Delete,
        ));
        self::assertSame(42, $mutation->rowKey);
        self::assertSame(TableMutationType::Delete, $mutation->type);
        self::assertNull($table->buildMutationForSourceEvent(new SourceChange(
            SourceChange::KIND_RT, HilosDbContext::legalAcceptances, '42', TableMutationType::Create,
        )));
        self::assertNull($table->buildMutationForSourceEvent(new SourceChange(
            SourceChange::KIND_DB, HilosDbContext::legalAcceptances, '42', TableMutationType::Clear,
        )));
    }
}

/** Exposes pure query assembly without replacing the SQL window tested in integration. */
final class LegalAcceptancesTableProbe extends AbstractHilosLegalAcceptancesTable
{
    public array $namedIds = [];
    public array $searched = [];

    /** @return array<string, string> Declared sortable columns */
    public function columns(): array
    {
        return $this->sortableFields();
    }

    /**
     * @param TableQueryDTO $query Query to assemble
     * @return array<string, string> Source-column order
     */
    public function order(TableQueryDTO $query): array
    {
        return $this->orderColumns($query);
    }

    /**
     * @param TableQueryDTO $query Query to assemble
     * @return array{0: string, 1: list<mixed>} Bound filter predicate
     */
    public function where(TableQueryDTO $query): array
    {
        return $this->buildWhere($query);
    }

    /**
     * @param array $userIds People to name, unused by these tests
     * @return array Empty name map
     */
    protected function displayNamesOf(array $userIds): array
    {
        return [];
    }

    /**
     * @param string $term Literal name substring
     * @return array Matching fixture ids
     */
    protected function userIdsNamed(string $term): array
    {
        $this->searched[] = $term;
        return $this->namedIds;
    }
}
