<?php

declare(strict_types=1);

namespace Hilos\Tests\Fixtures;

use Hilos\AdminViewMode\WireField;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableProgressScope;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;

final class WireTestAdminPage extends AbstractPage
{
    public const string PAGE = 'admin_view_mode_wire_page';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;
}

final class WireTestHilos extends Hilos
{
    public const array PAGES = [WireTestAdminPage::PAGE => WireTestAdminPage::class];

    /**
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new WireTestDbContext();
    }
}

final class WireTestDbContext extends HilosDbContext
{
}

/**
 * A browser context whose connection is an admin the gate lets through, and whose one question is told.
 */
final class WireTestBrowser extends BrowserContext
{
    public const string PAGE = WireTestAdminPage::PAGE;

    public const string SIGNAL = 'admin_view_mode_wire_signal';

    /**
     * @param bool $viewer What the one question answers
     */
    public function __construct(private readonly bool $viewer)
    {
        parent::__construct();
    }

    /**
     * Answers as the test was told; a yes is a connection the gate lets look as a viewer.
     *
     * @param string $pageClass Class of the page the frame belongs to
     * @param string $acceptKey Connection the frame goes to
     * @return bool The answer the test was built with
     */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }

    /**
     * Returns an admin behind every connection, so the page gate lets the window through.
     *
     * @param string $acceptKey Acting connection accept key (unused in the fixture)
     * @return ConnectionIdentity Settled identity of user 7
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(7);
    }

    /**
     * @param int $userId Authenticated durable user id (unused in the fixture)
     * @return bool Always an admin
     */
    public function isAdmin(int $userId): bool
    {
        return true;
    }

    /**
     * @return ?PiiRegistry The person's name is personal; the id and the last activity are not
     */
    protected function viewerPiiRegistry(): ?PiiRegistry
    {
        return new PiiRegistry(
            [0 => ['hilos_user' => ['name' => AnonymizationStrategy::FAKE_NAME]]],
            [0 => ['hilos_user' => ['id', 'last_activity']]],
        );
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return $page === self::PAGE ? BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => self::SIGNAL]) : null;
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return $page === self::PAGE ? BrowserPageBindings::fromArray([WireTestTable::TABLE => []]) : BrowserPageBindings::empty();
    }
}

final class WireTestTableContext extends TableContext
{
    public function configure(): void
    {
        $this->register(WireTestTable::TABLE, new WireTestTable());
    }
}

final class WireTestTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'adminViewModeWireTable';
    public const string SOURCE_KEY = 'adminViewModeWireSource';
    public const string PROGRESS_SOURCE_KEY = 'adminViewModeWireProgress';
    public const string SLOT_PERSON = 'person';
    public const string SLOT_PRESENCE = 'presence';
    public const string SLOT_HISTORY = 'history';

    /** @var array<string, WireTestRow> Rows the table holds, by key, in arrival order */
    public static array $rows = [];

    /** @var array<string, string> Fields the table declares the search over */
    public static array $searchable = [];

    /** Whether this case treats the name as a row-dependent setting value */
    public static bool $settingName = false;

    /**
     * @return array<string, WireField> The last activity from its column, the name from its, presence computed
     */
    public function wireFields(): array
    {
        return [
            'seen' => WireField::column(HilosDbContext::users, 'lastActivity'),
            'name' => self::$settingName
                ? WireField::settingFrom('key')
                : WireField::column(HilosDbContext::users, 'name'),
            'online' => WireField::notPersonal(),
            'members' => WireField::each(['online' => WireField::notPersonal()]),
        ];
    }

    /**
     * @return ?TableSortOrderDTO First window ordered by the person's name
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO('name'));
    }

    /**
     * @return int Two rows per first window
     */
    public function windowSize(): int
    {
        return 2;
    }

    /**
     * @return list<TableProgressDTO> One bar running on the whole table
     */
    public function progressSnapshot(): array
    {
        return [self::bar()];
    }

    /**
     * @param SourceChange $change Source change that may report work
     * @return ?TableProgressDTO The bar, for the progress source
     */
    public function buildProgressForSourceEvent(SourceChange $change): ?TableProgressDTO
    {
        return $change->sourceKey === self::PROGRESS_SOURCE_KEY ? self::bar() : null;
    }

    /**
     * @param string|int $rowKey Row key to place against the set
     * @param TableQueryDTO $query Window query
     * @return ?bool Whether the table holds the row
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return isset(self::$rows[(string)$rowKey]);
    }

    /**
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Row mutation, or null for another source
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== self::SOURCE_KEY) {
            return null;
        }

        return $this->mutation($change->mutationType, (string)$change->sourceId, self::$rows[(string)$change->sourceId] ?? null);
    }

    /**
     * @param AbstractTableRow $row Table row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => self::slotsOf($row),
        ];
    }

    /**
     * Splits a row into its slots: an object, an object of its own, a lone value and a list of objects.
     *
     * @param AbstractTableRow $row Table row
     * @return array<string, mixed> Slots as an admin receives them
     */
    public static function slotsOf(AbstractTableRow $row): array
    {
        $fields = $row->toArray();

        return [
            self::SLOT_PERSON => [
                'key' => $fields['key'],
                'seen' => $fields['seen'],
                'name' => $fields['name'],
                'members' => [['name' => 'Taras', 'online' => false]],
            ],
            self::SLOT_PRESENCE => ['online' => true],
            'note' => 'called on Monday',
            // The key field of a related entity, not of this row.
            self::SLOT_HISTORY => [['key' => 'visit-1', 'seen' => '2026-08-01', 'name' => 'Olena'], 'stray'],
        ];
    }

    /**
     * @return array<string, string> Sortable fields, the payload keys themselves
     */
    protected function sortableFields(): array
    {
        return ['key' => 'key', 'seen' => 'seen', 'name' => 'name'];
    }

    /**
     * @return array<string, string> Searched fields, as the test set them
     */
    protected function searchableFields(): array
    {
        return self::$searchable;
    }

    protected function init(): void
    {
        $this->setRowClass(WireTestRow::class);
    }

    /**
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory(
            array_values(array_map(static fn(WireTestRow $row): array => $row->toArray(), self::$rows)),
            $query,
        );
    }

    /**
     * @return TableProgressDTO A bar whose detail is the project's payload
     */
    private static function bar(): TableProgressDTO
    {
        return new TableProgressDTO(TableProgressScope::Table, 'run', null, 3, 10, false, ['file' => 'olena.csv', 'phase' => 'read']);
    }
}

final class WireTestRow extends AbstractTableRow
{
    private const array SEEN = ['alpha' => '2026-09-01', 'beta' => '2026-09-02', 'gamma' => '2026-09-03'];

    private const array NAMES = ['alpha' => 'Olena', 'beta' => 'Anna', 'gamma' => 'Petro'];

    public function __construct(
        public readonly string $key,
        public readonly string $seen,
        public readonly string $name,
    ) {
    }

    /**
     * @param string $key Sample row key
     * @param ?string $seen Last activity, or null for the sample's own
     * @param ?string $name Name, or null for the sample's own
     * @return self The sample row
     */
    public static function sample(string $key, ?string $seen = null, ?string $name = null): self
    {
        return new self($key, $seen ?? self::SEEN[$key], $name ?? self::NAMES[$key]);
    }

    public function getRowKey(): string
    {
        return $this->key;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return 'key';
    }

    /**
     * @return array<string, mixed> Row fields
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'seen' => $this->seen, 'name' => $this->name];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Row instance
     */
    public static function fromArray(array $data): static
    {
        return new static((string)$data['key'], (string)$data['seen'], (string)$data['name']);
    }
}
