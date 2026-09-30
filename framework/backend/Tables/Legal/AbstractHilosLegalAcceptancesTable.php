<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Core\Table\TableSearchTerm;
use Hilos\Core\Table\TableWindowPlan;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Entity\Item\LegalAcceptance as EntityLegalAcceptance;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\Object\Item\LegalAcceptance as ObjectLegalAcceptance;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Database\SqlSortDirection;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;

/**
 * Immutable acceptance history served as SQL windows, with project-owned person names.
 */
abstract class AbstractHilosLegalAcceptancesTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosLegalAcceptances';
    public const string FILTER_DOCUMENT = 'document';
    public const string FILTER_REVISION = 'revision';
    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [self::DB_SOURCE],
        BrowserTableConfigKey::ROWS => [[
            BrowserTableFieldKey::SOURCE => self::DB_SOURCE,
            BrowserTableFieldKey::ROW_KEY => EntityLegalAcceptance::id,
        ]],
    ];

    private const string ROW_SLOT = 'acceptance';
    private const array DB_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::legalAcceptances,
    ];
    private const array SORT_COLUMNS = [HilosLegalAcceptanceTableRow::acceptedAt => EntityLegalAcceptance::accepted_at];
    private const array DEFAULT_ORDER = [
        EntityLegalAcceptance::accepted_at => SqlSortDirection::DESC,
        EntityLegalAcceptance::id => SqlSortDirection::DESC,
    ];
    private const int DEFAULT_LIMIT = 50;
    private const array FACETED_FILTERS = [self::FILTER_DOCUMENT, self::FILTER_REVISION];

    /** @return int Number of rows in the initial window */
    public function windowSize(): int
    {
        return 25;
    }

    /** @return ?TableSortOrderDTO Newest acceptances first */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosLegalAcceptanceTableRow::acceptedAt, TableConstants::ORDER_DESC));
    }

    /**
     * @param SourceChange $change DB source event
     * @return ?TableRowMutationDTO Acceptance mutation, or null for another source or a clear
     * @throws HilosException When the row or its person's identity cannot be read
     * @throws InvalidArgumentException When the identity query has an invalid order
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->kind !== SourceChange::KIND_DB || $change->sourceKey !== HilosDbContext::legalAcceptances) {
            return null;
        }
        $id = (int) $change->sourceId;
        if ($id <= 0) {
            return null;
        }
        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $id);
        }
        if ($change->mutationType !== TableMutationType::Create && $change->mutationType !== TableMutationType::Update) {
            return null;
        }
        $row = $this->readRow($id);

        return $row === null ? null : $this->mutation($change->mutationType, $id, $row);
    }

    /**
     * @param AbstractTableRow $row Acceptance row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Browser row envelope
     * @throws TableRowKeyMissingException When the row carries no identity
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::ROW_SLOT => $row->toArray()],
        ];
    }

    /**
     * Declares where each field of the row comes from, for a viewer of the admin view mode (HIL-1250).
     *
     * Acceptance columns are non-personal; name and email are declared as columns and hidden by their column
     * verdicts (FAKE_NAME on user name and FAKE_EMAIL on identity identifier) rather than omission in the map;
     * all three demos resolve names from users.name, while a project with a different source overrides the map;
     * declared is computed from the code catalog.
     *
     * @return array<string, WireField>
     */
    public function wireFields(): array
    {
        return [
            HilosLegalAcceptanceTableRow::rowKey => WireField::column(
                HilosDbContext::legalAcceptances,
                ObjectLegalAcceptance::id,
            ),
            HilosLegalAcceptanceTableRow::userId => WireField::column(
                HilosDbContext::legalAcceptances,
                ObjectLegalAcceptance::userId,
            ),
            HilosLegalAcceptanceTableRow::document => WireField::column(
                HilosDbContext::legalAcceptances,
                ObjectLegalAcceptance::document,
            ),
            HilosLegalAcceptanceTableRow::revisionId => WireField::column(
                HilosDbContext::legalAcceptances,
                ObjectLegalAcceptance::revisionId,
            ),
            HilosLegalAcceptanceTableRow::acceptedAt => WireField::column(
                HilosDbContext::legalAcceptances,
                ObjectLegalAcceptance::acceptedAt,
            ),
            HilosLegalAcceptanceTableRow::name => WireField::column(
                HilosDbContext::users,
                ObjectUser::name,
            ),
            HilosLegalAcceptanceTableRow::email => WireField::column(
                HilosDbContext::identities,
                ObjectIdentity::identifier,
            ),
            HilosLegalAcceptanceTableRow::declared => WireField::notPersonal(),
        ];
    }

    /**
     * @param TableQueryDTO $query Scoped window query
     * @param array<string, list<int|float|string|bool>> $wanted Options requested by the client
     * @return ?array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Counts per requested option
     * @throws HilosException When a count or name search fails
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): ?array
    {
        return TableFacetTally::forFilters(
            $query,
            array_intersect_key($wanted, array_flip(self::FACETED_FILTERS)),
            $this->countSet(...),
        );
    }

    /**
     * @param string|int $rowKey Acceptance id
     * @param TableQueryDTO $query Scoped window query
     * @return ?bool Whether the acceptance belongs to this filtered set
     * @throws HilosException When the lookup or name search fails
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        [$where, $params] = $this->buildWhere($query);
        $condition = EntityLegalAcceptance::id . ' = ?';
        $where = $where === '' ? " WHERE {$condition}" : "{$where} AND {$condition}";
        $params[] = $rowKey;

        return Database::sql('SELECT 1 FROM ' . EntityLegalAcceptance::_table . $where . ' LIMIT 1', $params)->firstRow() !== null;
    }

    /**
     * @param AbstractTableRow $row Acceptance row
     * @param TableQueryDTO $query Window order
     * @return ?TableAnchorDTO Position in source columns, or null when no order is resolved
     */
    public function anchorForRow(AbstractTableRow $row, TableQueryDTO $query): ?TableAnchorDTO
    {
        if ($query->sort === null) {
            return null;
        }
        $fields = $row->toArray();
        $values = [];
        foreach (InMemoryTableFilter::anchorFields($query->sort, HilosLegalAcceptanceTableRow::keyField()) as $field) {
            $column = self::placeColumn($field);
            if ($column === null || !array_key_exists($field, $fields)) {
                return null;
            }
            $values[$column] = $fields[$field];
        }

        return new TableAnchorDTO($values);
    }

    /**
     * @param AbstractTableRow $row Acceptance row
     * @param TableAnchorDTO $anchor Boundary in source columns
     * @param TableQueryDTO $query Window order
     * @return ?int Position relative to the boundary, or null when it cannot be compared
     */
    public function placeRowAgainst(AbstractTableRow $row, TableAnchorDTO $anchor, TableQueryDTO $query): ?int
    {
        if ($query->sort === null) {
            return null;
        }
        $keyField = HilosLegalAcceptanceTableRow::keyField();
        $against = [];
        foreach (InMemoryTableFilter::anchorFields($query->sort, $keyField) as $field) {
            $column = self::placeColumn($field);
            if ($column === null || !array_key_exists($column, $anchor->values)) {
                return null;
            }
            $value = $anchor->values[$column];
            $against[$field] = $field === $keyField && is_string($value) && ctype_digit($value) ? (int) $value : $value;
        }

        return InMemoryTableFilter::compare($row->toArray(), $against, $query->sort, $keyField);
    }

    /** Configures the immutable row payload. */
    protected function init(): void
    {
        $this->setRowClass(HilosLegalAcceptanceTableRow::class);
    }

    /** @return array<string, string> Only the indexed acceptance timestamp is sortable */
    protected function sortableFields(): array
    {
        return self::SORT_COLUMNS;
    }

    /** @return array<string, string> Search vocabulary; the name and identity predicates are assembled together */
    protected function searchableFields(): array
    {
        return [
            HilosLegalAcceptanceTableRow::name => EntityLegalAcceptance::user_id,
            HilosLegalAcceptanceTableRow::email => EntityIdentity::identifier,
        ];
    }

    /**
     * @param list<int> $userIds People present in one SQL window
     * @return array<int, string> Project display names keyed by person id
     * @throws HilosException When the project cannot read names
     */
    abstract protected function displayNamesOf(array $userIds): array;

    /**
     * @param string $term Normalized name substring
     * @return list<int> People matching the name in the project
     * @throws HilosException When the project cannot search names
     */
    abstract protected function userIdsNamed(string $term): array;

    /**
     * @param TableQueryDTO $query Scoped window query
     * @return TableSnapshotDTO SQL window and its count, frame and source-column boundaries
     * @throws HilosException When a window, count, identity or name read fails
     * @throws InvalidArgumentException When the identity query has an invalid order
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        [$where, $params] = $this->buildWhere($query);
        $limit = $query->limit === TableConstants::NO_LIMIT ? self::DEFAULT_LIMIT : $query->limit;
        $counted = TableFacetTally::cappedSqlCount(EntityLegalAcceptance::_table, $where, $params);
        $order = $this->orderColumns($query);
        $plan = TableWindowPlan::forQuery($query->withLimit($limit), $order, $counted->count, $counted->exact);
        if ($plan === null) {
            return new TableSnapshotDTO(rows: [], totalCount: $counted->count, totalExact: $counted->exact, limit: $limit);
        }
        $anchorColumns = array_keys($order);
        if ($plan->keyset !== null) {
            $condition = $plan->keyset->toSql(EntityLegalAcceptance::_table);
            $where = $where === '' ? " WHERE {$condition}" : "{$where} AND {$condition}";
            $params = [...$params, ...$plan->keyset->getParams()];
        }
        $orderParts = [];
        foreach ($plan->orderBy as $column => $direction) {
            $orderParts[] = $column . ' ' . $direction;
        }
        [$rows, $frame] = $plan->cut(
            Database::sql(
                'SELECT * FROM ' . EntityLegalAcceptance::_table . $where . ' ORDER BY ' . implode(', ', $orderParts)
                . " LIMIT {$plan->limit} OFFSET {$plan->offset}",
                $params,
            )->rows(),
            static fn (array $row): TableAnchorDTO => TableAnchorDTO::fromRow($row, $anchorColumns),
        );
        $rows = array_values($rows);
        $names = $this->displayNamesOf(array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row[EntityLegalAcceptance::user_id],
            $rows,
        ))));

        return new TableSnapshotDTO(
            rows: array_map(fn (array $row): HilosLegalAcceptanceTableRow => $this->rowFromSql($row, $names), $rows),
            totalCount: $counted->count,
            totalExact: $counted->exact,
            limit: $limit,
            firstAnchor: $rows === [] ? null : TableAnchorDTO::fromRow($rows[0], $anchorColumns),
            lastAnchor: $rows === [] ? null : TableAnchorDTO::fromRow($rows[array_key_last($rows)], $anchorColumns),
            frame: $frame,
        );
    }

    /**
     * @param TableQueryDTO $query Scoped window query
     * @return TableFacetCountDTO Count capped at the table ceiling
     * @throws HilosException When the count or name search fails
     */
    protected function countSet(TableQueryDTO $query): TableFacetCountDTO
    {
        [$where, $params] = $this->buildWhere($query);

        return TableFacetTally::cappedSqlCount(EntityLegalAcceptance::_table, $where, $params);
    }

    /**
     * @param int $id Acceptance id
     * @return ?HilosLegalAcceptanceTableRow Current row, or null if it was erased
     * @throws HilosException When the row, identity or name cannot be read
     * @throws InvalidArgumentException When the identity query has an invalid order
     */
    protected function readRow(int $id): ?HilosLegalAcceptanceTableRow
    {
        $row = Database::sql('SELECT * FROM ' . EntityLegalAcceptance::_table . ' WHERE id = ? LIMIT 1', [$id])->firstRow();

        return $row === null ? null : $this->rowFromSql($row, $this->displayNamesOf([(int) $row[EntityLegalAcceptance::user_id]]));
    }

    /**
     * @param TableQueryDTO $query Scoped window query
     * @return array{0: string, 1: list<mixed>} SQL WHERE clause and bound values
     * @throws HilosException When the project name search fails
     */
    protected function buildWhere(TableQueryDTO $query): array
    {
        $conditions = [];
        $params = [];
        foreach ([
            self::FILTER_DOCUMENT => EntityLegalAcceptance::document,
            self::FILTER_REVISION => EntityLegalAcceptance::revision_id,
        ] as $key => $column) {
            $value = $query->filter[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $conditions[] = $column . ' = ?';
                $params[] = $value;
            }
        }
        $search = TableSearchTerm::normalize($query->search);
        if ($search !== null && $query->searchableFields !== []) {
            $searchConditions = [];
            if (isset($query->searchableFields[HilosLegalAcceptanceTableRow::email])) {
                $searchConditions[] = EntityLegalAcceptance::user_id . ' IN (SELECT ' . EntityIdentity::user_id
                    . ' FROM ' . EntityIdentity::_table . ' WHERE ' . EntityIdentity::identifier . ' ' . TableSearchTerm::LIKE_COMPARISON . ')';
                $params[] = TableSearchTerm::likePattern($search);
            }
            if (isset($query->searchableFields[HilosLegalAcceptanceTableRow::name])) {
                $ids = $this->userIdsNamed($search);
                if ($ids !== []) {
                    $searchConditions[] = EntityLegalAcceptance::user_id . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
                    $params = [...$params, ...$ids];
                }
            }
            $conditions[] = $searchConditions === [] ? '1 = 0' : '(' . implode(' OR ', $searchConditions) . ')';
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * @param TableQueryDTO $query Resolved window order
     * @return array<string, string> Source columns in index order, with id in the same direction
     */
    protected function orderColumns(TableQueryDTO $query): array
    {
        if ($query->sort === null) {
            return self::DEFAULT_ORDER;
        }
        $order = [];
        foreach ($query->sort->components as $component) {
            $column = $component->column === null ? null : self::SORT_COLUMNS[$component->field] ?? null;
            if ($column === null) {
                return self::DEFAULT_ORDER;
            }
            $order[$column] = $component->direction === TableConstants::ORDER_ASC ? SqlSortDirection::ASC : SqlSortDirection::DESC;
        }
        $order[EntityLegalAcceptance::id] = $query->sort->last()->direction === TableConstants::ORDER_ASC
            ? SqlSortDirection::ASC : SqlSortDirection::DESC;

        return $order;
    }

    /**
     * @param array<string, mixed> $row Raw SQL acceptance
     * @param array<int, string> $names Project names for the window
     * @return HilosLegalAcceptanceTableRow Row with nullable catalog membership
     * @throws DatabaseException When the person's verified email cannot be read
     * @throws InvalidArgumentException When the identity query has an invalid order
     */
    protected function rowFromSql(array $row, array $names): HilosLegalAcceptanceTableRow
    {
        $document = (string) $row[EntityLegalAcceptance::document];
        $revisionId = (string) $row[EntityLegalAcceptance::revision_id];
        $userId = (int) $row[EntityLegalAcceptance::user_id];
        try {
            LegalCatalogResolver::documents();
            $kind = LegalDocument::tryFrom($document);
            $declared = $kind !== null && in_array($revisionId, array_column(LegalCatalogResolver::revisions($kind), 'id'), true);
        } catch (LegalException) {
            $declared = null;
        }

        return new HilosLegalAcceptanceTableRow(
            rowKey: (int) $row[EntityLegalAcceptance::id],
            userId: $userId,
            name: $names[$userId] ?? (string) $userId,
            email: Hilos::$db->identities->findVerifiedEmailByUser($userId),
            document: $document,
            revisionId: $revisionId,
            declared: $declared,
            acceptedAt: (string) $row[EntityLegalAcceptance::accepted_at],
        );
    }

    /**
     * @param string $field Wire row field
     * @return ?string Source column used by SQL anchors
     */
    private static function placeColumn(string $field): ?string
    {
        return self::SORT_COLUMNS[$field]
            ?? ($field === HilosLegalAcceptanceTableRow::keyField() ? EntityLegalAcceptance::id : null);
    }
}
