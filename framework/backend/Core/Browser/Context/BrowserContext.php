<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Context;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeSet;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Hilos;
use Throwable;

/**
 * Base browser-facing context.
 *
 * Project subclasses register browser-facing state helpers during configuration.
 */
abstract class BrowserContext
{
    /** Browser page configs keyed by page name. */
    public const array PAGES = [];

    /** Browser-only table configs keyed by browser table name. */
    public const array TABLES = [];

    protected SourceChangeSet $changes;

    /**
     * Starts with an empty worker-local browser source-change buffer.
     */
    public function __construct()
    {
        $this->changes = new SourceChangeSet();
    }

    /**
     * Registers browser-facing state helpers.
     *
     * Called during Hilos::init().
     */
    abstract public function configure(): void;

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
        if ($pageConfig === []) {
            return;
        }

        $signalName = $this->pageSignalName($pageConfig);
        if ($signalName === '') {
            return;
        }

        $this->validateParams($pageConfig[BrowserConfigKey::PARAMS] ?? [], $params);
        $pageParams = $params->toArray();
        $this->assertPageGuards($pageConfig, $acceptKey, $pageParams);

        $tables = [];
        foreach ($this->pageTables($pageConfig) as $tableKey => $pageTableConfig) {
            $tableConfig = $this->tableConfig($tableKey);
            if ($tableConfig === []) {
                continue;
            }

            $tableParams = $this->tableParams($pageTableConfig, $acceptKey, $pageParams);
            $tables[$tableKey] = [
                BrowserPageSignalData::rows => $this->buildBrowserSnapshotRows(
                    tableKey: $tableKey,
                    tableConfig: $tableConfig,
                    acceptKey: $acceptKey,
                    pageParams: $pageParams,
                    tableParams: $tableParams,
                ),
            ];
        }

        if ($tables === []) {
            return;
        }

        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(
                data: new BrowserPageSignalData($tables),
                targetAcceptKey: $acceptKey,
            ),
        );
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
                if ($pageConfig === []) {
                    continue;
                }

                $signalName = $this->pageSignalName($pageConfig);
                if ($signalName === '') {
                    continue;
                }

                $pageParams = $subscription[SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY];
                foreach ($this->pageTables($pageConfig) as $tableKey => $pageTableConfig) {
                    $tableConfig = $this->tableConfig($tableKey);
                    if ($tableConfig === [] || !$this->tableObservesChange($tableConfig, $change)) {
                        continue;
                    }

                    $rowKey = $this->rowKeyForChange($tableConfig, $change, $this->tableParams(
                        $pageTableConfig,
                        $acceptKey,
                        $pageParams,
                    ));
                    if ($rowKey === null) {
                        continue;
                    }

                    $row = $this->buildBrowserRow(
                        tableKey: $tableKey,
                        tableConfig: $tableConfig,
                        rowKey: $rowKey,
                        acceptKey: $acceptKey,
                        pageParams: $pageParams,
                        tableParams: $this->tableParams($pageTableConfig, $acceptKey, $pageParams),
                    );

                    if ($row === null) {
                        $this->addBrowserDelete($signalTables, $acceptKey, $signalName, $tableKey, $rowKey);
                        continue;
                    }

                    $this->addBrowserRow($signalTables, $acceptKey, $signalName, $tableKey, $rowKey, $row);
                }
            }
        }

        foreach ($this->buildBrowserPayloads($signalTables) as $acceptKey => $signals) {
            foreach ($signals as $signalName => $tables) {
                Hilos::$sr->queueSignal(
                    signalSource: new SignalSource(SignalSource::WORKER),
                    signalType: new SignalType(SignalTypeConstants::WS_USER),
                    signalName: new SignalName($signalName),
                    signalData: new WebSocketSignalData(
                        data: new BrowserPageSignalData($tables),
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
     * @param array<string, mixed> $tableParams Resolved table params for this page subscription
     * @param array<string, mixed> $sources Source fragments already built for the row
     */
    protected function computeBrowserField(
        string $tableKey,
        string $field,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $tableParams,
        array $sources,
    ): mixed {
        return null;
    }

    /**
     * Resolves the browser config for one page.
     *
     * Project browser contexts may override this to read page topology from a
     * facade registry while keeping static::PAGES as the framework fallback.
     *
     * @param string $page Page name from the subscription mirror
     * @return array<string, mixed> Browser page config, or an empty array when absent
     */
    protected function resolveBrowserPageConfig(string $page): array
    {
        $config = static::PAGES[$page] ?? [];

        return is_array($config) ? $config : [];
    }

    /**
     * Resolves a browser-only table config.
     *
     * Returning null lets the generic table context fallback resolve ordinary
     * registered table metadata without requiring it for browser-only topology.
     *
     * @param string $tableKey Browser table key
     * @return ?array<string, mixed> Browser-only table config, or null when absent
     */
    protected function resolveBrowserOnlyTableConfig(string $tableKey): ?array
    {
        if (!array_key_exists($tableKey, static::TABLES)) {
            return null;
        }

        $config = static::TABLES[$tableKey];

        return is_array($config) ? $config : null;
    }

    /**
     * Returns the browser config for one page.
     *
     * @param string $page Page name from the subscription mirror
     * @return array<string, mixed> Browser page config
     */
    private function pageConfig(string $page): array
    {
        return $this->resolveBrowserPageConfig($page);
    }

    /**
     * Returns the page-specific wire signal name.
     *
     * @param array<string, mixed> $pageConfig Browser page config
     * @return string Signal name to emit for the subscribed page
     */
    private function pageSignalName(array $pageConfig): string
    {
        $signalName = $pageConfig[BrowserConfigKey::SIGNAL] ?? '';

        return is_string($signalName) ? $signalName : '';
    }

    /**
     * Returns page table bindings declared by a browser page config.
     *
     * @param array<string, mixed> $pageConfig Browser page config
     * @return array<string, array<string, mixed>> Page table configs keyed by table key
     */
    private function pageTables(array $pageConfig): array
    {
        $tables = $pageConfig[BrowserConfigKey::TABLES] ?? [];
        if (!is_array($tables)) {
            return [];
        }

        $result = [];
        foreach ($tables as $tableKey => $tableConfig) {
            if (!is_string($tableKey)) {
                continue;
            }
            $result[$tableKey] = is_array($tableConfig) ? $tableConfig : [];
        }

        return $result;
    }

    /**
     * Returns a browser table config from browser-only or registered table metadata.
     *
     * @param string $tableKey Browser or table context key
     * @return array<string, mixed> Browser table config
     */
    private function tableConfig(string $tableKey): array
    {
        $browserConfig = $this->resolveBrowserOnlyTableConfig($tableKey);
        if ($browserConfig !== null) {
            return $browserConfig;
        }

        if (Hilos::$table === null) {
            return [];
        }

        $table = Hilos::$table->get($tableKey);
        if ($table === null) {
            return [];
        }

        /** @var array<string, mixed> $tableBrowserConfig */
        $tableBrowserConfig = $table::BROWSER;

        return $tableBrowserConfig;
    }

    /**
     * Resolves table params for one page subscription.
     *
     * @param array<string, mixed> $pageTableConfig Page table binding config
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @return array<string, mixed> Resolved table params
     */
    private function tableParams(array $pageTableConfig, string $acceptKey, array $pageParams): array
    {
        $paramRefs = $pageTableConfig[BrowserParamKey::PARAMS] ?? [];
        if (!is_array($paramRefs)) {
            return [];
        }

        $params = [];
        foreach ($paramRefs as $paramKey => $ref) {
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
     * @param array<string, mixed> $tableConfig Browser table config
     * @param SourceChange $change Grouped DB/RT source change
     * @return bool True when this table has a row source for the change
     */
    private function tableObservesChange(array $tableConfig, SourceChange $change): bool
    {
        foreach ($this->rowConfigs($tableConfig) as $rowConfig) {
            if ($this->rowConfigMatchesChange($rowConfig, $change)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns row source configs for one browser table.
     *
     * @param array<string, mixed> $tableConfig Browser table config
     * @return list<array<string, mixed>> Row source configs
     */
    private function rowConfigs(array $tableConfig): array
    {
        $rows = $tableConfig[BrowserConfigKey::ROWS] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row)));
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
     * @param array<string, mixed> $tableConfig Browser table config
     * @param SourceChange $change Grouped DB/RT source change
     * @param array<string, mixed> $tableParams Resolved table params
     * @return int|string|null Browser row key, or null when no matching row source can resolve it
     */
    private function rowKeyForChange(array $tableConfig, SourceChange $change, array $tableParams): int|string|null
    {
        foreach ($this->rowConfigs($tableConfig) as $rowConfig) {
            if (!$this->rowConfigMatchesChange($rowConfig, $change)) {
                continue;
            }

            $rowKey = $this->rowKeyValue($rowConfig, $change, $tableParams);
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
     * @param array<string, mixed> $tableParams Resolved table params
     * @return int|string|null Browser row key
     */
    private function rowKeyValue(array $rowConfig, SourceChange $change, array $tableParams): int|string|null
    {
        $rowKey = $rowConfig[BrowserFieldKey::ROW_KEY] ?? null;
        if (is_array($rowKey)) {
            return $this->normalizeKey($this->resolveReference($rowKey, '', [], $tableParams));
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
     * @param array<string, mixed> $tableConfig Browser table config
     * @param int|string $rowKey Logical row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $tableParams Resolved table params
     * @return ?array{rowKey: int|string, sources: array<string, mixed>} Browser row payload, or null when row is absent
     */
    private function buildBrowserRow(
        string $tableKey,
        array $tableConfig,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $tableParams,
    ): ?array {
        $sources = [];
        $anchorChecked = false;
        $anchorFound = false;
        foreach ($this->rowConfigs($tableConfig) as $rowConfig) {
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

            $items = $this->sourceItemsForRow($rowConfig, $rowKey, $acceptKey, $pageParams, $tableParams, $sources);
            if ($isMany) {
                $sources[$sourceKey] = array_map(
                    fn(mixed $item): array => $this->projectSourceItem(
                        tableKey: $tableKey,
                        rowConfig: $rowConfig,
                        item: $item,
                        rowKey: $rowKey,
                        acceptKey: $acceptKey,
                        pageParams: $pageParams,
                        tableParams: $tableParams,
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
                tableParams: $tableParams,
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
     * @param array<string, mixed> $tableConfig Browser table config
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $tableParams Resolved table params for this page subscription
     * @return list<array{rowKey: int|string, sources: array<string, mixed>}> Current browser rows
     */
    private function buildBrowserSnapshotRows(
        string $tableKey,
        array $tableConfig,
        string $acceptKey,
        array $pageParams,
        array $tableParams,
    ): array {
        $rows = [];
        foreach ($this->snapshotRowKeys($tableConfig, $acceptKey, $pageParams, $tableParams) as $rowKey) {
            $row = $this->buildBrowserRow(
                tableKey: $tableKey,
                tableConfig: $tableConfig,
                rowKey: $rowKey,
                acceptKey: $acceptKey,
                pageParams: $pageParams,
                tableParams: $tableParams,
            );
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Collects logical row keys visible in a full browser table snapshot.
     *
     * @param array<string, mixed> $tableConfig Browser table config
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $tableParams Resolved table params for this page subscription
     * @return list<int|string> Logical row keys for current source items
     */
    private function snapshotRowKeys(
        array $tableConfig,
        string $acceptKey,
        array $pageParams,
        array $tableParams,
    ): array {
        $rowKeys = [];
        $seen = [];
        foreach ($this->anchorRowConfigs($tableConfig) as $rowConfig) {
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
                if (!$this->sourceItemMatchesWhere($rowConfig, $sourceItem, $acceptKey, $pageParams, $tableParams)) {
                    continue;
                }

                $rowKey = $this->rowKeyForSourceItem($rowConfig, $sourceItem, $acceptKey, $pageParams, $tableParams);
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
     * @param array<string, mixed> $tableConfig Browser table config
     * @return list<array<string, mixed>> Anchor row config or an empty list
     */
    private function anchorRowConfigs(array $tableConfig): array
    {
        foreach ($this->rowConfigs($tableConfig) as $rowConfig) {
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
     * @param array<string, mixed> $tableParams Resolved table params
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return list<mixed> Current source items matching this row source
     */
    private function sourceItemsForRow(
        array $rowConfig,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $tableParams,
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
            if (!$this->sourceItemMatchesRowKey($rowConfig, $sourceItem, $rowKey, $acceptKey, $pageParams, $tableParams)) {
                continue;
            }
            if (!$this->sourceItemMatchesWhere($rowConfig, $sourceItem, $acceptKey, $pageParams, $tableParams)) {
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
     * @param array<string, mixed> $tableParams Resolved table params for this page subscription
     * @return int|string|null Browser row key
     */
    private function rowKeyForSourceItem(
        array $rowConfig,
        mixed $sourceItem,
        string $acceptKey,
        array $pageParams,
        array $tableParams,
    ): int|string|null {
        $rowKey = $rowConfig[BrowserFieldKey::ROW_KEY] ?? null;
        if (is_array($rowKey)) {
            return $this->normalizeKey($this->resolveReference($rowKey, $acceptKey, $pageParams, $tableParams));
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
     * @param array<string, mixed> $tableParams Resolved table params
     * @return bool True when the item belongs to the logical row
     */
    private function sourceItemMatchesRowKey(
        array $rowConfig,
        mixed $sourceItem,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $tableParams,
    ): bool {
        $declaredRowKey = $rowConfig[BrowserFieldKey::ROW_KEY] ?? null;
        if (is_array($declaredRowKey)) {
            return $this->sameValue($this->resolveReference($declaredRowKey, $acceptKey, $pageParams, $tableParams), $rowKey);
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
     * @param array<string, mixed> $tableParams Resolved table params
     * @return bool True when all predicates match
     */
    private function sourceItemMatchesWhere(
        array $rowConfig,
        mixed $sourceItem,
        string $acceptKey,
        array $pageParams,
        array $tableParams,
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
                $this->resolveReference($expected, $acceptKey, $pageParams, $tableParams),
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
     * @param array<string, mixed> $tableParams Resolved table params
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
        array $tableParams,
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
                    tableParams: $tableParams,
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
     * @param array<string, mixed> $tableParams Resolved table params
     * @return mixed Resolved value
     */
    private function resolveReference(mixed $value, string $acceptKey, array $pageParams, array $tableParams): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $type = $value[BrowserRefKey::TYPE] ?? '';
        $key = $value[BrowserRefKey::KEY] ?? '';

        return match ($type) {
            BrowserRefType::ACCEPT_KEY => $acceptKey,
            BrowserRefType::PAGE_PARAM => is_string($key) ? ($pageParams[$key] ?? null) : null,
            BrowserRefType::TABLE_PARAM => is_string($key) ? ($tableParams[$key] ?? null) : null,
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
     * @param array<string, mixed> $pageConfig Browser page config
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @throws PageSubscriptionException When a guard rejects the subscription
     */
    private function assertPageGuards(array $pageConfig, string $acceptKey, array $pageParams): void
    {
        $guards = $pageConfig[BrowserConfigKey::GUARDS] ?? [];
        if (!is_array($guards)) {
            return;
        }

        foreach ($guards as $guard) {
            if (!is_array($guard)) {
                continue;
            }
            if (($guard[BrowserGuardKey::TYPE] ?? '') === BrowserGuardType::DB_EXISTS) {
                $this->assertDbExistsGuard($guard, $acceptKey, $pageParams);
            }
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
     * @param string $signalName Page signal name
     * @param string $tableKey Browser table key
     * @param int|string $rowKey Logical row key
     * @param array{rowKey: int|string, sources: array<string, mixed>} $row Browser row payload
     */
    private function addBrowserRow(
        array &$signalTables,
        string $acceptKey,
        string $signalName,
        string $tableKey,
        int|string $rowKey,
        array $row,
    ): void {
        unset($signalTables[$acceptKey][$signalName][$tableKey]['deleted'][(string) $rowKey]);
        $signalTables[$acceptKey][$signalName][$tableKey]['rows'][(string) $rowKey] = $row;
    }

    /**
     * Adds one browser row delete to the tick-local signal accumulator.
     *
     * @param array<string, array<string, array<string, array<string, mixed>>>> $signalTables Tick-local table accumulator
     * @param string $acceptKey Target accept key
     * @param string $signalName Page signal name
     * @param string $tableKey Browser table key
     * @param int|string $rowKey Logical row key
     */
    private function addBrowserDelete(
        array &$signalTables,
        string $acceptKey,
        string $signalName,
        string $tableKey,
        int|string $rowKey,
    ): void {
        unset($signalTables[$acceptKey][$signalName][$tableKey]['rows'][(string) $rowKey]);
        $signalTables[$acceptKey][$signalName][$tableKey]['deleted'][(string) $rowKey] = $rowKey;
    }

    /**
     * Converts the tick-local accumulator to BrowserPageSignalData constructor payloads.
     *
     * @param array<string, array<string, array<string, array<string, mixed>>>> $signalTables Tick-local table accumulator
     * @return array<string, array<string, array<string, array<string, mixed>>>> Tables grouped by accept key and signal name
     */
    private function buildBrowserPayloads(array $signalTables): array
    {
        $payloads = [];
        foreach ($signalTables as $acceptKey => $signals) {
            foreach ($signals as $signalName => $tables) {
                foreach ($tables as $tableKey => $changes) {
                    $rows = $changes['rows'] ?? [];
                    $deleted = $changes['deleted'] ?? [];
                    $payload = [];
                    if ($rows !== []) {
                        $payload[BrowserPageSignalData::rows] = array_values($rows);
                    }
                    if ($deleted !== []) {
                        $payload[BrowserPageSignalData::deleted] = array_values($deleted);
                    }
                    if ($payload !== []) {
                        $payloads[$acceptKey][$signalName][$tableKey] = $payload;
                    }
                }
            }
        }

        return $payloads;
    }
}
