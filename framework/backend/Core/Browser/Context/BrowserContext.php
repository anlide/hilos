<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Context;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserPageBinding;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceKind;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeSet;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableViewportAppendDTO;
use Hilos\Core\Table\DTO\TableViewportCountDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Hilos;
use Throwable;

/**
 * Base browser-facing context.
 *
 * Project subclasses provide topology and computed-field hooks when needed.
 */
abstract class BrowserContext
{
    protected SourceChangeSet $changes;

    /** @var class-string<Hilos> Active project facade class for topology registry reads. */
    private string $hilosClass = Hilos::class;

    /**
     * Starts with an empty worker-local browser source-change buffer.
     */
    public function __construct()
    {
        $this->changes = new SourceChangeSet();
    }

    /**
     * Binds this browser context to the active project facade.
     *
     * @param class-string<Hilos> $hilosClass Active project facade class
     */
    final public function bindHilosFacade(string $hilosClass): void
    {
        $this->hilosClass = $hilosClass;
    }

    /**
     * Records a DB/RT sync fact in the worker-local browser buffer.
     *
     * @param SourceChange $change Source change to dispatch on the next browser flush
     */
    public function record(SourceChange $change): void
    {
        $this->changes->add($change);
    }

    /**
     * Reports whether any browser source changes are buffered.
     *
     * @return bool Whether the browser context has source changes waiting for flush
     */
    public function hasChanges(): bool
    {
        return !$this->changes->isEmpty();
    }

    /**
     * Sends a full browser snapshot for one page subscription.
     *
     * The snapshot uses the same page/table browser config as incremental
     * source-change delivery, addressed directly to the subscribing accept key.
     *
     * @param string $page Page name from the subscription request
     * @param string $acceptKey Subscribing WebSocket accept key
     * @param PageRouteParams $params Route params for this page subscription
     * @throws PageSubscriptionException When browser params or guards reject the subscription
     */
    public function subscribeSnapshot(string $page, string $acceptKey, PageRouteParams $params): void
    {
        if (Hilos::$sr === null) {
            return;
        }

        $pageConfig = $this->pageConfig($page);
        if ($pageConfig === null) {
            return;
        }

        $signalName = $pageConfig->signalName;
        if ($signalName === '') {
            return;
        }

        $this->validateParams($pageConfig->paramConfigs(), $params);
        $pageParams = $params->toArray();
        $this->assertPageGuards($pageConfig, $acceptKey, $pageParams);

        $tables = [];
        foreach ($this->pageBindings($page) as $pageBinding) {
            $tableKey = $pageBinding->tableKey;

            if ($this->viewportTable($tableKey) !== null) {
                // Viewport tables deliver their rows through the table_viewport /
                // table_window cycle, not the subscription snapshot.
                continue;
            }

            $browserConfig = $this->browserConfig($tableKey);
            if ($browserConfig === null || $browserConfig->isEmpty()) {
                continue;
            }

            $browserParams = $this->browserParams($pageBinding, $acceptKey, $pageParams);
            $tables[$tableKey] = [
                BrowserPageSignalData::rows => $this->buildBrowserSnapshotRows(
                    tableKey: $tableKey,
                    browserConfig: $browserConfig,
                    acceptKey: $acceptKey,
                    pageParams: $pageParams,
                    browserParams: $browserParams,
                ),
            ];
        }

        $payload = $this->pagePayloadFromBrowser($tables);
        if ($payload->isEmpty()) {
            return;
        }

        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(SignalTypeConstants::PAGE_RESPONSE),
            signalData: new WebSocketSignalData(
                data: new PageResponseSignalData($page, $payload),
                targetAcceptKey: $acceptKey,
            ),
        );
    }

    /**
     * Builds and sends one table's window snapshot to a subscribing connection.
     *
     * Runs the table's windowed query for the viewport descriptor, serializes the
     * window rows, replies a table_window signal addressed to the accept key, and
     * records the delivered row-id keys on the viewport so live deltas can be
     * scoped to them. Any viewport table is served here — a self-snapshot table
     * (settings) or a source-fanned one (the Hilos users table) alike.
     *
     * @param string $page Page the table belongs to
     * @param string $acceptKey Subscribing WebSocket accept key
     * @param TableViewportSubscription $viewport Window descriptor; its delivered row-id set is updated
     */
    public function sendTableWindow(string $page, string $acceptKey, TableViewportSubscription $viewport): void
    {
        if (Hilos::$sr === null) {
            return;
        }

        $table = Hilos::$table?->get($viewport->tableKey);
        if (!$table instanceof ViewportTable) {
            return;
        }

        try {
            $snapshot = $table->getPage($this->viewportQuery($viewport));
        } catch (Throwable) {
            return;
        }

        $rows = [];
        $rowIds = [];
        foreach ($snapshot->rows as $row) {
            if (!$row instanceof AbstractTableRow) {
                continue;
            }
            $browserRow = $table->browserRow($row);
            $rows[] = $this->browserRowToWire($browserRow);
            $rowIds[] = (string) $browserRow[BrowserPageSignalData::rowKey];
        }

        $viewport->recordWindow($rowIds, $snapshot->totalCount);

        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(SignalTypeConstants::TABLE_WINDOW),
            signalData: new WebSocketSignalData(
                data: new TableWindowSignalData(
                    page: $page,
                    tableKey: $viewport->tableKey,
                    rows: $rows,
                    totalCount: $snapshot->totalCount,
                    offset: $snapshot->offset,
                    limit: $snapshot->limit,
                ),
                targetAcceptKey: $acceptKey,
            ),
        );
    }

    /**
     * Builds the table query for a viewport descriptor.
     *
     * Generic filter resolution: the `search` filter-map key maps to the query
     * search term. A table with custom filters resolves them through its own hook
     * in a later step.
     *
     * @param TableViewportSubscription $viewport Window descriptor
     * @return TableQueryDTO Table query for the window
     */
    private function viewportQuery(TableViewportSubscription $viewport): TableQueryDTO
    {
        $search = $viewport->filter[TableConstants::FILTER_KEY_SEARCH] ?? '';

        return new TableQueryDTO(
            search: is_string($search) ? $search : '',
            orderBy: $viewport->sortField,
            orderDirection: $viewport->sortDirection,
            offset: $viewport->offset,
            limit: $viewport->limit,
        );
    }

    /**
     * Converts an internal browser row to its wire form.
     *
     * The internal envelope keys the source fragments under `sources`; the wire
     * row the frontend normalizer ingests keys them under `slots`, the same shape
     * page_response table rows use. table_window and table_viewport_delta rows go
     * through here so every table row reaches the client in one shape.
     *
     * @param array{rowKey: int|string, sources: array<string, mixed>} $browserRow Internal browser row
     * @return array{rowKey: int|string, slots: array<string, mixed>} Wire row
     */
    private function browserRowToWire(array $browserRow): array
    {
        return [
            PagePayload::rowKey => $browserRow[BrowserPageSignalData::rowKey],
            PagePayload::slots => $browserRow[BrowserPageSignalData::sources],
        ];
    }

    /**
     * Drains browser source changes at the end of the worker tick.
     */
    public function flushToSignalRouter(): void
    {
        if ($this->changes->isEmpty()) {
            return;
        }

        $this->groupSourceChanges();
        $this->emitBrowserSignals();

        $this->changes = new SourceChangeSet();
    }

    /**
     * Groups source changes before browser signal work by modifying $this->changes.
     *
     * Multiple changes for the same DB/RT source item collapse into one change
     * with later row fields taking precedence.
     */
    protected function groupSourceChanges(): void
    {
        if ($this->changes->isEmpty()) {
            return;
        }

        /** @var array<string, SourceChange> $groupedChanges */
        $groupedChanges = [];
        /** @var list<string> $groupedChangeKeys */
        $groupedChangeKeys = [];

        foreach ($this->changes->all() as $change) {
            $groupKey = $change->kind . "\0" . $change->sourceKey . "\0" . $change->sourceId;
            if (!isset($groupedChanges[$groupKey])) {
                $groupedChanges[$groupKey] = $change;
                $groupedChangeKeys[] = $groupKey;
                continue;
            }

            $groupedChanges[$groupKey] = $this->mergeSourceChange($groupedChanges[$groupKey], $change);
        }

        $this->changes = new SourceChangeSet();
        foreach ($groupedChangeKeys as $groupKey) {
            $this->changes->add($groupedChanges[$groupKey]);
        }
    }

    /**
     * Merges two source changes from the same source item.
     *
     * @param SourceChange $current Earlier grouped source change
     * @param SourceChange $next Later source change to fold in
     * @return SourceChange Collapsed source change
     */
    private function mergeSourceChange(SourceChange $current, SourceChange $next): SourceChange
    {
        $row = $next->mutationType === TableMutationType::Create
            && $current->mutationType !== TableMutationType::Create
            ? $next->row
            : array_replace($current->row, $next->row);

        return new SourceChange(
            kind: $current->kind,
            sourceKey: $current->sourceKey,
            sourceId: $current->sourceId,
            mutationType: $this->mergeMutationType($current->mutationType, $next->mutationType),
            row: $row,
        );
    }

    /**
     * Collapses tick-local source lifecycle into one browser-visible mutation.
     *
     * @param TableMutationType $current Earlier grouped mutation type
     * @param TableMutationType $next Later mutation type to fold in
     * @return TableMutationType Browser-visible mutation type
     */
    private function mergeMutationType(TableMutationType $current, TableMutationType $next): TableMutationType
    {
        if ($next === TableMutationType::Clear || $current === TableMutationType::Clear) {
            return TableMutationType::Clear;
        }

        if ($next === TableMutationType::Delete) {
            return TableMutationType::Delete;
        }

        if ($current === TableMutationType::Create) {
            return TableMutationType::Create;
        }

        return TableMutationType::Update;
    }

    /**
     * Emits browser signals produced from grouped DB/RT source changes in $this->changes.
     */
    protected function emitBrowserSignals(): void
    {
        if (Hilos::$sr === null) {
            return;
        }

        /** @var array<string, array<string, array<string, array<string, mixed>>>> $signalTables */
        $signalTables = [];
        foreach ($this->changes->all() as $change) {
            foreach (Hilos::$sr->getPageSubscriptions() as $acceptKey => $subscription) {
                $page = $subscription[SignalPayloadConstants::SUBSCRIPTION_PAGE_KEY];
                $pageConfig = $this->pageConfig($page);
                if ($pageConfig === null) {
                    continue;
                }

                $signalName = $pageConfig->signalName;
                if ($signalName === '') {
                    continue;
                }

                $pageParams = $subscription[SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY];
                foreach ($this->pageBindings($page) as $pageBinding) {
                    $tableKey = $pageBinding->tableKey;

                    $viewportTable = $this->viewportTable($tableKey);
                    if ($viewportTable !== null) {
                        $viewport = Hilos::$sr->getTableViewport($acceptKey, $tableKey);
                        if ($viewport !== null) {
                            $this->emitViewportDelta($viewportTable, $viewport, $change, $acceptKey, $page, $tableKey);
                        }
                        // A viewport table is delivered only through its window and
                        // deltas; with or without an active viewport it never uses the
                        // page_response table fan-out. Lists and data are not viewport
                        // tables and fall through to the declarative path below.
                        continue;
                    }

                    $browserConfig = $this->browserConfig($tableKey);
                    if ($browserConfig === null || !$this->browserObservesChange($browserConfig, $change)) {
                        continue;
                    }

                    if ($change->mutationType === TableMutationType::Clear) {
                        $this->addBrowserClear($signalTables, $acceptKey, $page, $tableKey);
                        continue;
                    }

                    $browserParams = $this->browserParams($pageBinding, $acceptKey, $pageParams);
                    $rowKey = $this->rowKeyForChange($browserConfig, $change, $browserParams);
                    if ($rowKey === null) {
                        continue;
                    }

                    $row = $this->buildBrowserRow(
                        tableKey: $tableKey,
                        browserConfig: $browserConfig,
                        rowKey: $rowKey,
                        acceptKey: $acceptKey,
                        pageParams: $pageParams,
                        browserParams: $browserParams,
                    );

                    if ($row === null) {
                        $this->addBrowserDelete($signalTables, $acceptKey, $page, $tableKey, $rowKey);
                        continue;
                    }

                    $this->addBrowserRow($signalTables, $acceptKey, $page, $tableKey, $rowKey, $row);
                }
            }
        }

        foreach ($this->buildBrowserPayloads($signalTables) as $acceptKey => $pages) {
            foreach ($pages as $page => $tables) {
                $payload = $this->pagePayloadFromBrowser($tables);
                if ($payload->isEmpty()) {
                    continue;
                }

                Hilos::$sr->queueSignal(
                    signalSource: new SignalSource(SignalSource::WORKER),
                    signalType: new SignalType(SignalTypeConstants::WS_USER),
                    signalName: new SignalName(SignalTypeConstants::PAGE_RESPONSE),
                    signalData: new WebSocketSignalData(
                        data: new PageResponseSignalData((string) $page, $payload),
                        targetAcceptKey: $acceptKey,
                    ),
                );
            }
        }
    }

    /**
     * Computes a declared browser field for a logical table row.
     *
     * Project browser contexts override this for computed names listed in
     * browser table configs. Unknown computed fields resolve to null.
     *
     * @param string $tableKey Browser table key
     * @param string $field Computed field name
     * @param int|string $rowKey Logical browser table row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params for this page subscription
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return mixed Computed browser field value, or null when the field is unknown
     */
    protected function computeBrowserField(
        string $tableKey,
        string $field,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
        array $sources,
    ): mixed {
        return null;
    }

    /**
     * Resolves browser metadata for one page.
     *
     * Reads page metadata from the active project topology registry.
     * Page-table bindings are resolved separately.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Browser page metadata, or null when absent
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        $hilosClass = $this->hilosClass;
        $pageClass = $hilosClass::PAGES[$page] ?? null;
        if (!is_string($pageClass)) {
            return null;
        }

        /** @var array<string, mixed> $config */
        $config = $pageClass::BROWSER;

        return BrowserPageConfig::fromArray($config);
    }

    /**
     * Resolves page table bindings from project topology.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        $hilosClass = $this->hilosClass;
        $tables = $hilosClass::PAGE_TABLES[$page] ?? [];

        return BrowserPageBindings::fromArray(is_array($tables) ? $tables : []);
    }

    /**
     * Resolves a browser-only table config.
     *
     * Returning null lets the generic table context fallback resolve ordinary
     * registered table metadata without requiring it for browser-only topology.
     *
     * @param string $tableKey Browser table key
     * @return ?BrowserSourceConfig Browser-only table config, or null when absent
     */
    protected function resolveBrowserOnlyConfig(string $tableKey): ?BrowserSourceConfig
    {
        $hilosClass = $this->hilosClass;
        $tableClass = $hilosClass::BROWSER_TABLES[$tableKey] ?? null;
        if (!is_string($tableClass)) {
            return null;
        }

        /** @var array<string, mixed> $config */
        $config = $tableClass::BROWSER;

        return BrowserSourceConfig::fromArray($config);
    }

    /**
     * Returns browser metadata for one page.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Browser page metadata
     */
    private function pageConfig(string $page): ?BrowserPageConfig
    {
        return $this->resolveBrowserPageConfig($page);
    }

    /**
     * Returns page table bindings from project topology.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Page table bindings
     */
    private function pageBindings(string $page): BrowserPageBindings
    {
        return $this->resolveBrowserPageBindings($page);
    }

    /**
     * Returns a browser table config from browser-only or registered table metadata.
     *
     * @param string $tableKey Browser or table context key
     * @return ?BrowserSourceConfig Browser source config
     */
    private function browserConfig(string $tableKey): ?BrowserSourceConfig
    {
        $browserConfig = $this->resolveBrowserOnlyConfig($tableKey);
        if ($browserConfig !== null) {
            return $browserConfig;
        }

        if (Hilos::$table === null) {
            return null;
        }

        $table = Hilos::$table->get($tableKey);
        if ($table === null) {
            return null;
        }

        /** @var array<string, mixed> $tableBrowserConfig */
        $tableBrowserConfig = $table::BROWSER;

        return BrowserSourceConfig::fromArray($tableBrowserConfig);
    }

    /**
     * Resolves the kind of a page-bound source, deciding its payload section.
     *
     * A source with no resolvable class — a project hook that returns an inline
     * config — is a table.
     *
     * @param string $tableKey Browser source key
     * @return string One of the BrowserSourceKind constants
     */
    private function browserKind(string $tableKey): string
    {
        $class = $this->resolveSourceClass($tableKey);

        return $class !== null ? $this->sourceKind($class) : BrowserSourceKind::TABLE;
    }

    /**
     * Resolves the declaring class of a page-bound source, when registered.
     *
     * @param string $tableKey Browser source key
     * @return ?string Source class name, or null when resolved without a class
     */
    private function resolveSourceClass(string $tableKey): ?string
    {
        $tableClass = $this->hilosClass::BROWSER_TABLES[$tableKey] ?? null;
        if (is_string($tableClass)) {
            return $tableClass;
        }

        $table = Hilos::$table?->get($tableKey);

        return $table !== null ? $table::class : null;
    }

    /**
     * Reads the source kind a source class declares through its key constant.
     *
     * The constant name carries the kind: a `LIST` source feeds the lists
     * section, a `DATA` source the data section, and any other source is a
     * table.
     *
     * @param string $class Browser source class name
     * @return string One of the BrowserSourceKind constants
     */
    private function sourceKind(string $class): string
    {
        foreach ([BrowserSourceKind::LIST, BrowserSourceKind::DATA] as $kind) {
            if (defined("{$class}::" . strtoupper($kind))) {
                return $kind;
            }
        }

        return BrowserSourceKind::TABLE;
    }

    /**
     * Resolves table params for one page subscription.
     *
     * @param BrowserPageBinding $pageBinding Page table binding
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @return array<string, mixed> Resolved table params
     */
    private function browserParams(BrowserPageBinding $pageBinding, string $acceptKey, array $pageParams): array
    {
        $params = [];
        foreach ($pageBinding->paramRefs() as $paramKey => $ref) {
            if (!is_string($paramKey)) {
                continue;
            }
            $params[$paramKey] = $this->resolveReference($ref, $acceptKey, $pageParams, []);
        }

        return $params;
    }

    /**
     * Checks whether any row source in the table observes the source change.
     *
     * @param BrowserSourceConfig $browserConfig Browser source config
     * @param SourceChange $change Grouped DB/RT source change
     * @return bool True when this table has a row source for the change
     */
    private function browserObservesChange(BrowserSourceConfig $browserConfig, SourceChange $change): bool
    {
        foreach ($this->rowConfigs($browserConfig) as $rowConfig) {
            if ($this->rowConfigMatchesChange($rowConfig, $change)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns row source configs for one browser table.
     *
     * @param BrowserSourceConfig $browserConfig Browser source config
     * @return list<array<string, mixed>> Row source configs
     */
    private function rowConfigs(BrowserSourceConfig $browserConfig): array
    {
        return $browserConfig->rowConfigs();
    }

    /**
     * Checks whether a row source config matches the source change.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param SourceChange $change Grouped DB/RT source change
     * @return bool True when the row source observes this change
     */
    private function rowConfigMatchesChange(array $rowConfig, SourceChange $change): bool
    {
        $source = $rowConfig[BrowserFieldKey::SOURCE] ?? [];

        return is_array($source)
            && $this->sourceType($source) === $change->kind
            && $this->sourceKey($source) === $change->sourceKey
            && $this->rowConfigTriggersOnChange($rowConfig, $change);
    }

    /**
     * Checks whether a row source should react to the changed fields.
     *
     * Create and delete changes always invalidate the row. Update changes may
     * opt into a narrow trigger field list to avoid emitting browser rows for
     * backend-only source fields.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param SourceChange $change Grouped DB/RT source change
     * @return bool True when this row source should be rebuilt for the change
     */
    private function rowConfigTriggersOnChange(array $rowConfig, SourceChange $change): bool
    {
        if ($change->mutationType !== TableMutationType::Update) {
            return true;
        }

        $triggers = $rowConfig[BrowserFieldKey::TRIGGERS] ?? [];
        if (!is_array($triggers) || $triggers === []) {
            return true;
        }

        foreach ($triggers as $field) {
            if (is_string($field) && array_key_exists($field, $change->row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves the logical browser row key affected by a source change.
     *
     * @param BrowserSourceConfig $browserConfig Browser source config
     * @param SourceChange $change Grouped DB/RT source change
     * @param array<string, mixed> $browserParams Resolved table params
     * @return int|string|null Browser row key, or null when no matching row source can resolve it
     */
    private function rowKeyForChange(BrowserSourceConfig $browserConfig, SourceChange $change, array $browserParams): int|string|null
    {
        foreach ($this->rowConfigs($browserConfig) as $rowConfig) {
            if (!$this->rowConfigMatchesChange($rowConfig, $change)) {
                continue;
            }

            $rowKey = $this->rowKeyValue($rowConfig, $change, $browserParams);
            if ($rowKey !== null) {
                return $rowKey;
            }
        }

        return null;
    }

    /**
     * Resolves a row key value from the source change, current source row, or table params.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param SourceChange $change Grouped DB/RT source change
     * @param array<string, mixed> $browserParams Resolved table params
     * @return int|string|null Browser row key
     */
    private function rowKeyValue(array $rowConfig, SourceChange $change, array $browserParams): int|string|null
    {
        $rowKey = $rowConfig[BrowserFieldKey::ROW_KEY] ?? null;
        if (is_array($rowKey)) {
            return $this->normalizeKey($this->resolveReference($rowKey, '', [], $browserParams));
        }

        if (!is_string($rowKey) || $rowKey === '') {
            return $this->normalizeKey($change->sourceId);
        }

        if (array_key_exists($rowKey, $change->row)) {
            return $this->normalizeKey($change->row[$rowKey]);
        }

        $source = $rowConfig[BrowserFieldKey::SOURCE] ?? [];
        if (is_array($source)) {
            $sourceItem = $this->sourceItemById($source, $change->sourceId);
            if ($sourceItem !== null) {
                return $this->normalizeKey($this->fieldValue($sourceItem, $rowKey));
            }
        }

        return $this->normalizeKey($change->sourceId);
    }

    /**
     * Builds the page-shaped browser row for one logical row key.
     *
     * @param string $tableKey Browser table key
     * @param BrowserSourceConfig $browserConfig Browser source config
     * @param int|string $rowKey Logical row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params
     * @return ?array{rowKey: int|string, sources: array<string, mixed>} Browser row payload, or null when row is absent
     */
    private function buildBrowserRow(
        string $tableKey,
        BrowserSourceConfig $browserConfig,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
    ): ?array {
        $sources = [];
        $anchorChecked = false;
        $anchorFound = false;
        foreach ($this->rowConfigs($browserConfig) as $rowConfig) {
            $source = $rowConfig[BrowserFieldKey::SOURCE] ?? [];
            if (!is_array($source)) {
                continue;
            }

            $sourceKey = $this->sourceKey($source);
            if ($sourceKey === '') {
                continue;
            }

            $isMany = ($rowConfig[BrowserFieldKey::MANY] ?? false) === true;
            $isAnchor = !$anchorChecked && !$isMany;
            if ($isAnchor) {
                $anchorChecked = true;
            }

            $items = $this->sourceItemsForRow($rowConfig, $rowKey, $acceptKey, $pageParams, $browserParams, $sources);
            if ($isMany) {
                $sources[$sourceKey] = array_map(
                    fn(mixed $item): array => $this->projectSourceItem(
                        tableKey: $tableKey,
                        rowConfig: $rowConfig,
                        item: $item,
                        rowKey: $rowKey,
                        acceptKey: $acceptKey,
                        pageParams: $pageParams,
                        browserParams: $browserParams,
                        sources: $sources,
                    ),
                    $items,
                );
                continue;
            }

            if ($items === []) {
                if ($isAnchor) {
                    return null;
                }
                continue;
            }

            if ($isAnchor) {
                $anchorFound = true;
            }

            $sources[$sourceKey] = $this->projectSourceItem(
                tableKey: $tableKey,
                rowConfig: $rowConfig,
                item: $items[0],
                rowKey: $rowKey,
                acceptKey: $acceptKey,
                pageParams: $pageParams,
                browserParams: $browserParams,
                sources: $sources,
            );
        }

        if ($sources === [] || ($anchorChecked && !$anchorFound)) {
            return null;
        }

        return [
            BrowserPageSignalData::rowKey => $rowKey,
            BrowserPageSignalData::sources => $sources,
        ];
    }

    /**
     * Builds all current browser rows for one page-bound table.
     *
     * @param string $tableKey Browser table key
     * @param BrowserSourceConfig $browserConfig Browser source config
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params for this page subscription
     * @return list<array{rowKey: int|string, sources: array<string, mixed>}> Current browser rows
     */
    private function buildBrowserSnapshotRows(
        string $tableKey,
        BrowserSourceConfig $browserConfig,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
    ): array {
        $rows = [];
        foreach ($this->snapshotRowKeys($browserConfig, $acceptKey, $pageParams, $browserParams) as $rowKey) {
            $row = $this->buildBrowserRow(
                tableKey: $tableKey,
                browserConfig: $browserConfig,
                rowKey: $rowKey,
                acceptKey: $acceptKey,
                pageParams: $pageParams,
                browserParams: $browserParams,
            );
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Resolves a page-bound table as a viewport table, when it is one.
     *
     * @param string $tableKey Browser table key
     * @return ?ViewportTable Viewport table, or null when absent or not windowed
     */
    private function viewportTable(string $tableKey): ?ViewportTable
    {
        $table = Hilos::$table?->get($tableKey);

        return $table instanceof ViewportTable ? $table : null;
    }

    /**
     * Emits live viewport signals for a source change scoped to a connection's window.
     *
     * A change yields one or two addressed signals. A create whose row lands on the
     * last page with room is appended live (table_viewport_append, counts included)
     * and nothing else is sent. Otherwise a live table_viewport_count carries any
     * total shift (navigation metadata the frontend applies at once), and a pending
     * table_viewport_delta carries an in-window row edit or removal.
     *
     * The signals are also queued to the connection whose own action caused the
     * change: the fanout runs off grouped source changes and does not carry the
     * originating action's id, so the originator is not distinguished here. The
     * frontend instead auto-applies its own row change by row-key correlation
     * (TableViewportController::expectOwnChange) while other connections keep the
     * pending gate. KNOWN RACE: a concurrent change to the SAME row between an edit
     * and its echo can cross the frontend marks and leave one change pending; the
     * precise fix is to tag the originating connection here.
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param TableViewportSubscription $viewport Connection's window; its row-id set and total are updated in place
     * @param SourceChange $change Grouped DB/RT source change
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $tableKey Browser table key
     */
    private function emitViewportDelta(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        SourceChange $change,
        string $acceptKey,
        string $page,
        string $tableKey,
    ): void {
        try {
            $mutation = $table->buildMutationForSourceEvent($change);
        } catch (Throwable) {
            return;
        }

        if ($mutation === null) {
            return;
        }

        if ($this->tryEmitViewportAppend($table, $viewport, $mutation, $acceptKey, $page, $tableKey)) {
            return;
        }

        $this->emitViewportCount($table, $viewport, $mutation, $acceptKey, $page, $tableKey);

        $delta = $this->rowDeltaForMutation($viewport, $table, $mutation, $page, $tableKey);
        if ($delta !== null) {
            $this->queueAddressedTableSignal(SignalTypeConstants::TABLE_VIEWPORT_DELTA, $delta, $acceptKey);
        }
    }

    /**
     * Appends a created row to a last-page-with-room window, or returns false.
     *
     * The frozen-viewport rule: a new row is appended at the END of the window
     * regardless of sort — only when the window reaches the dataset end and has a
     * free slot — so nothing already shown shifts. The append carries the new total
     * and page count, so no separate count signal is sent. A filtered window falls
     * through to the count path (the new row may not match the search).
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param TableViewportSubscription $viewport Connection's window; its row-id set and total are updated in place
     * @param TableRowMutationDTO $mutation Mutation the table built for the change
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $tableKey Browser table key
     * @return bool Whether the row was appended (and no further signal is needed)
     */
    private function tryEmitViewportAppend(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        TableRowMutationDTO $mutation,
        string $acceptKey,
        string $page,
        string $tableKey,
    ): bool {
        if ($mutation->type !== TableMutationType::Create || $mutation->row === null) {
            return false;
        }
        if ($viewport->hasRow((string) $mutation->rowKey)) {
            return false;
        }
        if ($this->viewportQuery($viewport)->search !== '' || !$this->viewportIsLastPageWithRoom($viewport)) {
            return false;
        }

        $newTotal = $viewport->totalCount() + 1;
        $viewport->recordWindow([...$viewport->rowIds(), (string) $mutation->rowKey], $newTotal);

        $this->queueAddressedTableSignal(
            SignalTypeConstants::TABLE_VIEWPORT_APPEND,
            new TableViewportAppendDTO(
                $page,
                $tableKey,
                $this->browserRowToWire($table->browserRow($mutation->row)),
                $newTotal,
                $this->pageCount($newTotal, $viewport->limit),
            ),
            $acceptKey,
        );

        return true;
    }

    /**
     * Whether the window reaches the dataset end and has a free slot for one row.
     *
     * A non-paginated window (no limit) always has room; otherwise the window must
     * hold fewer than its limit and end at the total, so a new tail row neither
     * pushes a row out nor belongs to a later page.
     *
     * @param TableViewportSubscription $viewport Connection's window
     * @return bool Whether a tail row fits without shifting the window
     */
    private function viewportIsLastPageWithRoom(TableViewportSubscription $viewport): bool
    {
        $windowSize = count($viewport->rowIds());
        $hasRoom = $viewport->limit === TableConstants::NO_LIMIT || $windowSize < $viewport->limit;

        return $hasRoom && $viewport->offset + $windowSize >= $viewport->totalCount();
    }

    /**
     * Emits the live count signal when a mutation shifts the filtered total.
     *
     * The count is navigation metadata, not row content, so it is delivered live
     * and never gated as pending. Unfiltered, the total moves by the mutation's
     * row-level type (create +1, delete -1, update none) with no re-query — the
     * type is row-level faithful because each table builds it that way (a presence
     * or other secondary-source change is always an update). With a filter active,
     * the total is recomputed via getPage, the costlier but transient path.
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param TableViewportSubscription $viewport Connection's window; its total is updated in place
     * @param TableRowMutationDTO $mutation Mutation the table built for the change
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $tableKey Browser table key
     */
    private function emitViewportCount(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        TableRowMutationDTO $mutation,
        string $acceptKey,
        string $page,
        string $tableKey,
    ): void {
        $newTotal = $this->viewportTotalAfterMutation($table, $viewport, $mutation);
        if ($newTotal === null || $newTotal === $viewport->totalCount()) {
            return;
        }

        $viewport->recordWindow($viewport->rowIds(), $newTotal);

        $this->queueAddressedTableSignal(
            SignalTypeConstants::TABLE_VIEWPORT_COUNT,
            new TableViewportCountDTO($page, $tableKey, $newTotal, $this->pageCount($newTotal, $viewport->limit)),
            $acceptKey,
        );
    }

    /**
     * Resolves the filtered total after a mutation, or null to leave it unchanged.
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param TableViewportSubscription $viewport Connection's window
     * @param TableRowMutationDTO $mutation Mutation the table built for the change
     * @return ?int New filtered total, or null when the count does not change
     */
    private function viewportTotalAfterMutation(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        TableRowMutationDTO $mutation,
    ): ?int {
        if ($this->viewportQuery($viewport)->search !== '') {
            return $this->viewportFilteredTotal($table, $viewport);
        }

        return match ($mutation->type) {
            TableMutationType::Create => $viewport->totalCount() + 1,
            TableMutationType::Delete => max(0, $viewport->totalCount() - 1),
            TableMutationType::Update => null,
            default => $this->viewportFilteredTotal($table, $viewport),
        };
    }

    /**
     * Recomputes the filtered total via a windowed query, or null on failure.
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param TableViewportSubscription $viewport Connection's window
     * @return ?int Filtered total, or null when the query fails
     */
    private function viewportFilteredTotal(ViewportTable $table, TableViewportSubscription $viewport): ?int
    {
        try {
            return $table->getPage($this->viewportQuery($viewport))->totalCount;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Maps an in-window mutation to its pending row delta, or null otherwise.
     *
     * Out-of-window changes carry no row here — their effect is the live count (and,
     * for an inbound last-page row, a later append). An in-window delete drops the
     * row from the delivered set and removes it; an in-window update re-sends it.
     *
     * @param TableViewportSubscription $viewport Connection's window; its row-id set is updated in place
     * @param ViewportTable $table Viewport table the window is on
     * @param TableRowMutationDTO $mutation Mutation the table built for the change
     * @param string $page Subscribed page key
     * @param string $tableKey Browser table key
     * @return ?TableViewportDeltaDTO Pending row delta, or null when no row in the window changed
     */
    private function rowDeltaForMutation(
        TableViewportSubscription $viewport,
        ViewportTable $table,
        TableRowMutationDTO $mutation,
        string $page,
        string $tableKey,
    ): ?TableViewportDeltaDTO {
        $rowKey = (string) $mutation->rowKey;
        if (!$viewport->hasRow($rowKey)) {
            return null;
        }

        if ($mutation->type === TableMutationType::Delete) {
            $viewport->forgetRow($rowKey);

            return TableViewportDeltaDTO::rowRemoved($page, $tableKey, $mutation->rowKey, TableViewportDeltaDTO::REASON_DELETED);
        }

        if ($mutation->row === null) {
            return null;
        }

        return TableViewportDeltaDTO::rowUpdated(
            $page,
            $tableKey,
            $mutation->rowKey,
            $this->browserRowToWire($table->browserRow($mutation->row)),
        );
    }

    /**
     * Queues an addressed worker-to-client table signal for one accept key.
     *
     * @param string $signalName Signal type name
     * @param SignalDataInterface $data Signal payload
     * @param string $acceptKey Target accept key
     */
    private function queueAddressedTableSignal(string $signalName, SignalDataInterface $data, string $acceptKey): void
    {
        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, targetAcceptKey: $acceptKey),
        );
    }

    /**
     * Page count under a window size; at least one, and one when unpaginated.
     *
     * @param int $totalCount Total rows matching the filter
     * @param int $limit Window size (TableConstants::NO_LIMIT = all rows)
     * @return int Page count
     */
    private function pageCount(int $totalCount, int $limit): int
    {
        if ($limit <= TableConstants::NO_LIMIT) {
            return 1;
        }

        return max(1, (int) ceil($totalCount / $limit));
    }

    /**
     * Collects logical row keys visible in a full browser table snapshot.
     *
     * @param BrowserSourceConfig $browserConfig Browser source config
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params for this page subscription
     * @return list<int|string> Logical row keys for current source items
     */
    private function snapshotRowKeys(
        BrowserSourceConfig $browserConfig,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
    ): array {
        $rowKeys = [];
        $seen = [];
        foreach ($this->anchorRowConfigs($browserConfig) as $rowConfig) {
            if (($rowConfig[BrowserFieldKey::MANY] ?? false) === true) {
                continue;
            }

            $source = $rowConfig[BrowserFieldKey::SOURCE] ?? [];
            if (!is_array($source)) {
                continue;
            }

            $sourceItems = $this->sourceItemsForSnapshot($source);
            if ($sourceItems === []) {
                continue;
            }

            foreach ($sourceItems as $sourceItem) {
                if (!$this->sourceItemMatchesWhere($rowConfig, $sourceItem, $acceptKey, $pageParams, $browserParams)) {
                    continue;
                }

                $rowKey = $this->rowKeyForSourceItem($rowConfig, $sourceItem, $acceptKey, $pageParams, $browserParams);
                if ($rowKey === null || isset($seen[(string) $rowKey])) {
                    continue;
                }

                $seen[(string) $rowKey] = true;
                $rowKeys[] = $rowKey;
            }
        }

        return $rowKeys;
    }

    /**
     * Returns the row configs that own full-snapshot row keys.
     *
     * Only the first non-many source is the row anchor. Joined sources enrich
     * that row and must not add their own keys to the full browser snapshot.
     *
     * @param BrowserSourceConfig $browserConfig Browser source config
     * @return list<array<string, mixed>> Anchor row config or an empty list
     */
    private function anchorRowConfigs(BrowserSourceConfig $browserConfig): array
    {
        foreach ($this->rowConfigs($browserConfig) as $rowConfig) {
            if (($rowConfig[BrowserFieldKey::MANY] ?? false) === true) {
                continue;
            }

            return [$rowConfig];
        }

        return [];
    }

    /**
     * Loads source items used for a full browser snapshot.
     *
     * DB-backed anchors must use a fresh full query so lazy key-only
     * collections do not shrink list pages to already-cached rows.
     *
     * @param array<string, mixed> $source Browser source declaration
     * @return list<mixed> Snapshot source items
     */
    private function sourceItemsForSnapshot(array $source): array
    {
        $collection = $this->sourceCollection($source);
        if ($collection instanceof DbCollection && $this->sourceType($source) === BrowserSourceType::DB) {
            try {
                $result = $collection->queryPageItems(new TableQueryDTO());
                $rows = $result[TableConstants::RESULT_KEY_ROWS] ?? [];

                return is_array($rows) ? array_values($rows) : [];
            } catch (Throwable) {
                return [];
            }
        }

        if (!is_iterable($collection)) {
            return [];
        }

        return is_array($collection) ? array_values($collection) : iterator_to_array($collection, false);
    }

    /**
     * Finds current source items that contribute to a logical browser row.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param int|string $rowKey Logical row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return list<mixed> Current source items matching this row source
     */
    private function sourceItemsForRow(
        array $rowConfig,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
        array $sources,
    ): array {
        $source = $rowConfig[BrowserFieldKey::SOURCE] ?? [];
        if (!is_array($source)) {
            return [];
        }

        $collection = $this->sourceCollection($source);
        if (!is_iterable($collection)) {
            return [];
        }

        $items = [];
        foreach ($collection as $sourceItem) {
            if (!$this->sourceItemMatchesRowKey($rowConfig, $sourceItem, $rowKey, $acceptKey, $pageParams, $browserParams)) {
                continue;
            }
            if (!$this->sourceItemMatchesWhere($rowConfig, $sourceItem, $acceptKey, $pageParams, $browserParams)) {
                continue;
            }
            if (!$this->sourceItemMatchesVia($rowConfig, $sourceItem, $sources)) {
                continue;
            }

            $items[] = $sourceItem;
        }

        return $items;
    }

    /**
     * Resolves a logical row key from a current source item.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param mixed $sourceItem Current source item
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params for this page subscription
     * @return int|string|null Browser row key
     */
    private function rowKeyForSourceItem(
        array $rowConfig,
        mixed $sourceItem,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
    ): int|string|null {
        $rowKey = $rowConfig[BrowserFieldKey::ROW_KEY] ?? null;
        if (is_array($rowKey)) {
            return $this->normalizeKey($this->resolveReference($rowKey, $acceptKey, $pageParams, $browserParams));
        }

        if (is_string($rowKey) && $rowKey !== '') {
            return $this->normalizeKey($this->fieldValue($sourceItem, $rowKey));
        }

        return $this->normalizeKey($this->fieldValue($sourceItem, 'id'));
    }

    /**
     * Checks a current source item against the row-key declaration.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param mixed $sourceItem Current source item
     * @param int|string $rowKey Logical row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params
     * @return bool True when the item belongs to the logical row
     */
    private function sourceItemMatchesRowKey(
        array $rowConfig,
        mixed $sourceItem,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
    ): bool {
        $declaredRowKey = $rowConfig[BrowserFieldKey::ROW_KEY] ?? null;
        if (is_array($declaredRowKey)) {
            return $this->sameValue($this->resolveReference($declaredRowKey, $acceptKey, $pageParams, $browserParams), $rowKey);
        }

        if (!is_string($declaredRowKey) || $declaredRowKey === '') {
            return true;
        }

        return $this->sameValue($this->fieldValue($sourceItem, $declaredRowKey), $rowKey);
    }

    /**
     * Checks WHERE predicates for a current source item.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param mixed $sourceItem Current source item
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params
     * @return bool True when all predicates match
     */
    private function sourceItemMatchesWhere(
        array $rowConfig,
        mixed $sourceItem,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
    ): bool {
        $where = $rowConfig[BrowserFieldKey::WHERE] ?? [];
        if (!is_array($where)) {
            return true;
        }

        foreach ($where as $field => $expected) {
            if (!is_string($field)) {
                continue;
            }
            if (!$this->sameValue(
                $this->fieldValue($sourceItem, $field),
                $this->resolveReference($expected, $acceptKey, $pageParams, $browserParams),
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks VIA predicates against source fragments already built for the row.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param mixed $sourceItem Current source item
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return bool True when every VIA predicate matches an earlier source value
     */
    private function sourceItemMatchesVia(array $rowConfig, mixed $sourceItem, array $sources): bool
    {
        $via = $rowConfig[BrowserFieldKey::VIA] ?? [];
        if (!is_array($via)) {
            return true;
        }

        foreach ($via as $sourceField => $rowField) {
            if (!is_string($sourceField) || !is_string($rowField)) {
                continue;
            }
            if (!$this->sameValue($this->fieldValue($sourceItem, $sourceField), $this->sourceFragmentValue($sources, $rowField))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Projects configured source and computed fields from one current item.
     *
     * @param string $tableKey Browser table key
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param mixed $item Current source item
     * @param int|string $rowKey Logical row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return array<string, mixed> Projected source fragment
     */
    private function projectSourceItem(
        string $tableKey,
        array $rowConfig,
        mixed $item,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
        array $sources,
    ): array {
        $payload = [];
        $fields = $rowConfig[BrowserFieldKey::FIELDS] ?? [];
        if (is_array($fields)) {
            foreach ($fields as $sourceField => $targetField) {
                if (is_int($sourceField)) {
                    $sourceField = $targetField;
                }
                if (!is_string($sourceField) || !is_string($targetField)) {
                    continue;
                }
                $payload[$targetField] = $this->fieldValue($item, $sourceField);
            }
        }

        $computed = $rowConfig[BrowserFieldKey::COMPUTED] ?? [];
        if (is_array($computed)) {
            foreach ($computed as $field) {
                if (!is_string($field)) {
                    continue;
                }
                $payload[$field] = $this->computeBrowserField(
                    tableKey: $tableKey,
                    field: $field,
                    rowKey: $rowKey,
                    acceptKey: $acceptKey,
                    pageParams: $pageParams,
                    browserParams: $browserParams,
                    sources: $sources,
                );
            }
        }

        return $payload;
    }

    /**
     * Reads a source collection from Hilos::$db or Hilos::$rt.
     *
     * @param array<string, mixed> $source Browser source declaration
     * @return ?iterable Current source collection, or null when unavailable
     */
    private function sourceCollection(array $source): ?iterable
    {
        $sourceKey = $this->sourceKey($source);
        if ($sourceKey === '') {
            return null;
        }

        try {
            return match ($this->sourceType($source)) {
                BrowserSourceType::DB => Hilos::$db?->{$sourceKey},
                BrowserSourceType::RT => Hilos::$rt?->{$sourceKey},
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Loads one source item by its source id.
     *
     * @param array<string, mixed> $source Browser source declaration
     * @param string $sourceId Source id from the DB/RT sync fact
     * @return mixed Source item or null
     */
    private function sourceItemById(array $source, string $sourceId): mixed
    {
        $collection = $this->sourceCollection($source);
        if (!$collection instanceof \ArrayAccess) {
            return null;
        }

        try {
            $item = $collection[$sourceId] ?? null;
            if ($item !== null || !ctype_digit($sourceId)) {
                return $item;
            }

            return $collection[(int) $sourceId] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Reads one field from an array or magic item.
     *
     * @param mixed $item Source item or row
     * @param string $field Field name
     * @return mixed Field value or null when unavailable
     */
    private function fieldValue(mixed $item, string $field): mixed
    {
        if (is_array($item)) {
            return $item[$field] ?? null;
        }

        try {
            return $item->{$field};
        } catch (Throwable) {
            if (is_object($item) && method_exists($item, 'toArray')) {
                $payload = $item->toArray();
                if (is_array($payload) && array_key_exists($field, $payload)) {
                    return $payload[$field];
                }
            }
        }

        return null;
    }

    /**
     * Resolves a browser config reference or returns a literal value.
     *
     * @param mixed $value Literal value or reference declaration
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params
     * @return mixed Resolved value
     */
    private function resolveReference(mixed $value, string $acceptKey, array $pageParams, array $browserParams): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $type = $value[BrowserRefKey::TYPE] ?? '';
        $key = $value[BrowserRefKey::KEY] ?? '';

        return match ($type) {
            BrowserRefType::ACCEPT_KEY => $acceptKey,
            BrowserRefType::PAGE_PARAM => is_string($key) ? ($pageParams[$key] ?? null) : null,
            BrowserRefType::TABLE_PARAM => is_string($key) ? ($browserParams[$key] ?? null) : null,
            default => null,
        };
    }

    /**
     * Returns one field value from already built source fragments.
     *
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @param string $field Field name to find
     * @return mixed Field value or null
     */
    private function sourceFragmentValue(array $sources, string $field): mixed
    {
        foreach ($sources as $sourcePayload) {
            if (is_array($sourcePayload) && array_key_exists($field, $sourcePayload)) {
                return $sourcePayload[$field];
            }
        }

        return null;
    }

    /**
     * Reads a source declaration type.
     *
     * @param array<string, mixed> $source Browser source declaration
     * @return string Source type
     */
    private function sourceType(array $source): string
    {
        $type = $source[BrowserSourceKey::TYPE] ?? '';

        return is_string($type) ? $type : '';
    }

    /**
     * Reads a source declaration key.
     *
     * @param array<string, mixed> $source Browser source declaration
     * @return string Source collection key
     */
    private function sourceKey(array $source): string
    {
        $key = $source[BrowserSourceKey::KEY] ?? '';

        return is_string($key) ? $key : '';
    }

    /**
     * Normalizes a row key to the browser-supported scalar contract.
     *
     * @param mixed $value Raw key value
     * @return int|string|null Normalized key
     */
    private function normalizeKey(mixed $value): int|string|null
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Compares config and source values with numeric-string tolerance.
     */
    private function sameValue(mixed $left, mixed $right): bool
    {
        if ((is_int($left) || is_string($left)) && (is_int($right) || is_string($right))) {
            return (string) $left === (string) $right;
        }

        return $left === $right;
    }

    /**
     * Validates route params declared by a browser page config.
     *
     * @param mixed $paramConfigs Browser param declarations
     * @param PageRouteParams $params Route params from the subscription request
     * @throws PageSubscriptionException When a required param is missing or malformed
     */
    private function validateParams(mixed $paramConfigs, PageRouteParams $params): void
    {
        if (!is_array($paramConfigs)) {
            return;
        }

        foreach ($paramConfigs as $paramKey => $paramConfig) {
            if (!is_string($paramKey) || !is_array($paramConfig)) {
                continue;
            }

            $isRequired = ($paramConfig[BrowserParamKey::REQUIRED] ?? false) === true;
            if (($paramConfig[BrowserParamKey::TYPE] ?? BrowserParamType::STRING) === BrowserParamType::POSITIVE_INT) {
                if ($isRequired) {
                    $params->requirePositiveInt($paramKey);
                } else {
                    $params->getPositiveInt($paramKey);
                }
                continue;
            }

            if ($isRequired) {
                $params->requireString($paramKey);
            } else {
                $params->getString($paramKey);
            }
        }
    }

    /**
     * Enforces page-level browser guards.
     *
     * @param BrowserPageConfig $pageConfig Browser page config
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @throws PageSubscriptionException When a guard rejects the subscription or declares an unsupported type
     */
    private function assertPageGuards(BrowserPageConfig $pageConfig, string $acceptKey, array $pageParams): void
    {
        foreach ($pageConfig->guardConfigs() as $guard) {
            if (($guard[BrowserGuardKey::TYPE] ?? '') !== BrowserGuardType::DB_EXISTS) {
                throw new PageInternalErrorException('Unsupported browser guard type');
            }

            $this->assertDbExistsGuard($guard, $acceptKey, $pageParams);
        }
    }

    /**
     * Enforces a DB-exists browser guard.
     *
     * @param array<string, mixed> $guard Browser guard config
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @throws PageSubscriptionException When the guarded DB item is absent
     */
    private function assertDbExistsGuard(array $guard, string $acceptKey, array $pageParams): void
    {
        $source = $guard[BrowserGuardKey::SOURCE] ?? [];
        if (!is_array($source)) {
            return;
        }

        $key = $this->normalizeKey(
            $this->resolveReference($guard[BrowserGuardKey::KEY] ?? null, $acceptKey, $pageParams, []),
        );
        if ($key === null || $this->sourceItemById($source, (string) $key) !== null) {
            return;
        }

        if (($guard[BrowserGuardKey::ERROR] ?? null) === BrowserSubscriptionError::FORBIDDEN) {
            throw new PageForbiddenException('Access forbidden');
        }

        throw new PageResourceNotFoundException("Resource #{$key} not found");
    }

    /**
     * Adds or replaces one browser row in the tick-local signal accumulator.
     *
     * @param array<string, array<string, array<string, array<string, mixed>>>> $signalTables Tick-local table accumulator
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $tableKey Browser table key
     * @param int|string $rowKey Logical row key
     * @param array{rowKey: int|string, sources: array<string, mixed>} $row Browser row payload
     */
    private function addBrowserRow(
        array &$signalTables,
        string $acceptKey,
        string $page,
        string $tableKey,
        int|string $rowKey,
        array $row,
    ): void {
        unset($signalTables[$acceptKey][$page][$tableKey][BrowserPageSignalData::deleted][(string) $rowKey]);
        $signalTables[$acceptKey][$page][$tableKey][BrowserPageSignalData::rows][(string) $rowKey] = $row;
    }

    /**
     * Adds one browser row delete to the tick-local signal accumulator.
     *
     * @param array<string, array<string, array<string, array<string, mixed>>>> $signalTables Tick-local table accumulator
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $tableKey Browser table key
     * @param int|string $rowKey Logical row key
     */
    private function addBrowserDelete(
        array &$signalTables,
        string $acceptKey,
        string $page,
        string $tableKey,
        int|string $rowKey,
    ): void {
        unset($signalTables[$acceptKey][$page][$tableKey][BrowserPageSignalData::rows][(string) $rowKey]);
        $signalTables[$acceptKey][$page][$tableKey][BrowserPageSignalData::deleted][(string) $rowKey] = $rowKey;
    }

    /**
     * Marks one page-bound table as fully cleared in the tick-local accumulator.
     *
     * Drops any rows/deletes already accumulated for the table this tick: a
     * truncate supersedes them. Rows created later in the same tick (for example
     * a follow-up marker event) are added after the clear and ride alongside it,
     * so the frontend truncates first and then applies them.
     *
     * @param array<string, array<string, array<string, array<string, mixed>>>> $signalTables Tick-local table accumulator
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $tableKey Browser table key
     */
    private function addBrowserClear(
        array &$signalTables,
        string $acceptKey,
        string $page,
        string $tableKey,
    ): void {
        unset(
            $signalTables[$acceptKey][$page][$tableKey][BrowserPageSignalData::rows],
            $signalTables[$acceptKey][$page][$tableKey][BrowserPageSignalData::deleted],
        );
        $signalTables[$acceptKey][$page][$tableKey][BrowserPageSignalData::cleared] = true;
    }

    /**
     * Compacts the tick-local accumulator to per-table row/delete payloads.
     *
     * @param array<string, array<string, array<string, array<string, mixed>>>> $signalTables Tick-local table accumulator
     * @return array<string, array<string, array<string, array<string, mixed>>>> Tables grouped by accept key and page key
     */
    private function buildBrowserPayloads(array $signalTables): array
    {
        $payloads = [];
        foreach ($signalTables as $acceptKey => $pages) {
            foreach ($pages as $page => $tables) {
                foreach ($tables as $tableKey => $changes) {
                    $rows = $changes[BrowserPageSignalData::rows] ?? [];
                    $deleted = $changes[BrowserPageSignalData::deleted] ?? [];
                    $cleared = ($changes[BrowserPageSignalData::cleared] ?? false) === true;
                    $payload = [];
                    if ($cleared) {
                        $payload[BrowserPageSignalData::cleared] = true;
                    }
                    if ($rows !== []) {
                        $payload[BrowserPageSignalData::rows] = array_values($rows);
                    }
                    if ($deleted !== []) {
                        $payload[BrowserPageSignalData::deleted] = array_values($deleted);
                    }
                    if ($payload !== []) {
                        $payloads[$acceptKey][$page][$tableKey] = $payload;
                    }
                }
            }
        }

        return $payloads;
    }

    /**
     * Routes per-table rows into a kind-classified page payload.
     *
     * Each source's kind decides its section: a list source becomes an ordered
     * item collection, a data source collapses to a single page-data blob, and a
     * table source keeps the row-collection shape. The row field bag is renamed
     * from `sources` to `slots` on the wire.
     *
     * @param array<string, array<string, mixed>> $browserByKey Per-table rows and deletes keyed by table key
     * @return PagePayload Page payload split by section
     */
    private function pagePayloadFromBrowser(array $browserByKey): PagePayload
    {
        $lists = [];
        $tables = [];
        $data = [];
        foreach ($browserByKey as $tableKey => $table) {
            $rows = $table[BrowserPageSignalData::rows] ?? [];
            $rows = is_array($rows) ? $rows : [];
            $deleted = $table[BrowserPageSignalData::deleted] ?? [];
            $deleted = is_array($deleted) ? $deleted : [];
            $cleared = ($table[BrowserPageSignalData::cleared] ?? false) === true;

            switch ($this->browserKind($tableKey)) {
                case BrowserSourceKind::LIST:
                    $section = [];
                    if ($cleared) {
                        $section[PagePayload::cleared] = true;
                    }
                    if ($rows !== []) {
                        $section[PagePayload::items] = $this->renameRowSlots($rows, PagePayload::itemKey);
                    }
                    if ($deleted !== []) {
                        $section[PagePayload::deleted] = array_values($deleted);
                    }
                    $lists[$tableKey] = $section;

                    break;

                case BrowserSourceKind::DATA:
                    $fragments = $rows === []
                        ? []
                        : array_values(array_filter(
                            $rows[array_key_first($rows)][BrowserPageSignalData::sources] ?? [],
                            'is_array',
                        ));
                    $data[$tableKey] = $fragments === [] ? [] : array_merge(...$fragments);

                    break;

                default:
                    $section = [];
                    if ($cleared) {
                        $section[PagePayload::cleared] = true;
                    }
                    if ($rows !== []) {
                        $section[PagePayload::rows] = $this->renameRowSlots($rows, PagePayload::rowKey);
                    }
                    if ($deleted !== []) {
                        $section[PagePayload::deleted] = array_values($deleted);
                    }
                    $tables[$tableKey] = $section;
            }
        }

        return new PagePayload(data: $data, lists: $lists, tables: $tables);
    }

    /**
     * Renames each browser row's `sources` bag to `slots` under the given key.
     *
     * @param list<array{rowKey: int|string, sources: array<string, mixed>}> $rows Browser rows
     * @param string $keyName Identity key name for the section (rowKey or itemKey)
     * @return list<array<string, mixed>> Rows reshaped for the page_response section
     */
    private function renameRowSlots(array $rows, string $keyName): array
    {
        return array_values(array_map(
            static fn(array $row): array => [
                $keyName => $row[BrowserPageSignalData::rowKey],
                PagePayload::slots => $row[BrowserPageSignalData::sources],
            ],
            $rows,
        ));
    }
}
