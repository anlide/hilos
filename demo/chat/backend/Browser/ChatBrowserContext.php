<?php

declare(strict_types=1);

namespace Demo\Chat\Browser;

use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\SettingsPage;
use Demo\Chat\Runtime\View\DTO\UserConnectionSummary;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeSet;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Tables\Settings\HilosSettingTableRow;
use Hilos\Tables\Settings\HilosSettingsTable;
use Throwable;

/**
 * Chat demo browser-facing context.
 */
final class ChatBrowserContext extends BrowserContext
{
    /**
     * Sends a settings-specific full browser snapshot with catalog placeholder rows.
     *
     * @param string $page Page name from the subscription request
     * @param string $acceptKey Subscribing WebSocket accept key
     * @param PageRouteParams $params Route params for this page subscription
     * @throws PageSubscriptionException When a non-settings browser subscription is rejected
     */
    public function subscribeSnapshot(string $page, string $acceptKey, PageRouteParams $params): void
    {
        if ($page !== SettingsPage::PAGE) {
            parent::subscribeSnapshot($page, $acceptKey, $params);
            return;
        }

        $this->queueSettingsSnapshot($acceptKey);
    }

    /**
     * Emits settings browser rows through the table-owned catalog row builder.
     *
     * Other page/table browser changes continue through the generic BrowserContext
     * implementation.
     */
    protected function emitBrowserSignals(): void
    {
        $settingsChanges = new SourceChangeSet();
        $otherChanges = new SourceChangeSet();

        foreach ($this->changes->all() as $change) {
            if ($this->isSettingsSourceChange($change)) {
                $settingsChanges->add($change);
                continue;
            }

            $otherChanges->add($change);
        }

        if (!$settingsChanges->isEmpty()) {
            $this->emitSettingsBrowserSignals($settingsChanges);
        }

        if (!$otherChanges->isEmpty()) {
            $this->changes = $otherChanges;
            parent::emitBrowserSignals();
        }
    }

    /**
     * Computes chat browser fields named by page/table configs.
     *
     * @param string $tableKey Browser table key
     * @param string $field Computed field name from the mirrored browser config
     * @param int|string $rowKey Logical browser table row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $tableParams Resolved table params for this page subscription
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return mixed Computed browser field value, or null when unavailable
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
        if (
            $field === UserConnectionSummary::presence
            || $field === UserConnectionSummary::onlineSessionCount
        ) {
            return $this->computeUserConnectionSummaryField($field, $rowKey);
        }

        if (
            $field === SelfConnectionSignalData::messageRateLimitSecondsRemaining
            || $field === SelfConnectionSignalData::outboundModerationState
            || $field === SelfConnectionSignalData::fileUploadState
            || $field === SelfConnectionSignalData::fileUploadProgress
        ) {
            return $this->computeSelfConnectionField($field, $acceptKey);
        }

        if ($tableKey === ChatTableContext::settings) {
            return $this->computeSettingField($field, $rowKey);
        }

        return parent::computeBrowserField(
            $tableKey,
            $field,
            $rowKey,
            $acceptKey,
            $pageParams,
            $tableParams,
            $sources,
        );
    }

    /**
     * Computes runtime connection summary fields for user-shaped rows.
     *
     * @param string $field Summary field name
     * @param int|string $rowKey User id row key
     * @return mixed Summary field value, or null when runtime state is unavailable
     */
    private function computeUserConnectionSummaryField(string $field, int|string $rowKey): mixed
    {
        $userId = (int) $rowKey;
        if ($userId <= 0 || (string) $userId !== (string) $rowKey) {
            return null;
        }

        try {
            $summary = Hilos::$rt?->connections->summaryForUser($userId);
        } catch (Throwable) {
            return null;
        }

        return match ($field) {
            UserConnectionSummary::presence => $summary?->presence,
            UserConnectionSummary::onlineSessionCount => $summary?->onlineSessionCount,
            default => null,
        };
    }

    /**
     * Computes current-connection fields for one subscribed accept key.
     *
     * @param string $field Self-connection field name
     * @param string $acceptKey Subscriber accept key
     * @return mixed Self-connection field value, or null when the connection is gone
     */
    private function computeSelfConnectionField(string $field, string $acceptKey): mixed
    {
        try {
            $connection = Hilos::$rt?->connections[$acceptKey] ?? null;
        } catch (Throwable) {
            return null;
        }

        if ($connection === null) {
            return null;
        }

        $payload = SelfConnectionBrowserPayload::forConnection($connection);

        return $payload[$field] ?? null;
    }

    /**
     * Computes catalog-enriched settings table fields.
     *
     * @param string $field Settings table field name
     * @param int|string $rowKey Setting key row key
     * @return mixed Settings field value, or null when the persisted row is absent
     */
    private function computeSettingField(string $field, int|string $rowKey): mixed
    {
        try {
            $table = Hilos::$table?->get(ChatTableContext::settings);
            if (!$table instanceof HilosSettingsTable) {
                return null;
            }

            $row = $table->rowForKey((string) $rowKey)?->toArray();
        } catch (Throwable) {
            return null;
        }

        return is_array($row) ? ($row[$field] ?? null) : null;
    }

    /**
     * Sends the current settings browser table snapshot to one accept key.
     *
     * @param string $acceptKey Target WebSocket accept key
     */
    private function queueSettingsSnapshot(string $acceptKey): void
    {
        if (Hilos::$sr === null) {
            return;
        }

        $rows = $this->settingsSnapshotRows();
        if ($rows === []) {
            return;
        }

        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(SignalTypeConstants::PAGE_RESPONSE),
            signalData: new WebSocketSignalData(
                data: new PageResponseSignalData(
                    SettingsPage::PAGE,
                    new PagePayload(tables: [
                        ChatTableContext::settings => [
                            PagePayload::rows => $rows,
                        ],
                    ]),
                ),
                targetAcceptKey: $acceptKey,
            ),
        );
    }

    /**
     * Builds full settings browser rows from the settings table snapshot.
     *
     * @return list<array{rowKey: int|string, slots: array<string, mixed>}> Settings page-response rows
     */
    private function settingsSnapshotRows(): array
    {
        $table = Hilos::$table?->get(ChatTableContext::settings);
        if (!$table instanceof HilosSettingsTable) {
            return [];
        }

        try {
            $rows = [];
            foreach ($table->getFullSnapshot()->rows as $row) {
                if ($row instanceof HilosSettingTableRow) {
                    $rows[] = $this->settingsBrowserRow($row);
                }
            }

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Emits page-response updates for settings source changes.
     *
     * Accumulates per subscriber the changed rows and removed keys, then sends one
     * settings page_response per subscriber — the same envelope the generic
     * browser path emits for every other page.
     *
     * @param SourceChangeSet $changes Settings source changes
     */
    private function emitSettingsBrowserSignals(SourceChangeSet $changes): void
    {
        if (Hilos::$sr === null) {
            return;
        }

        /** @var array<string, array<string, array{rowKey: int|string, slots: array<string, mixed>}>> $rowsByAccept */
        $rowsByAccept = [];
        /** @var array<string, array<string, int|string>> $deletedByAccept */
        $deletedByAccept = [];

        foreach ($changes->all() as $change) {
            $key = $this->settingKeyFromSourceChange($change);
            if ($key === '') {
                continue;
            }

            foreach (Hilos::$sr->getPageSubscriptions() as $acceptKey => $subscription) {
                $page = is_array($subscription)
                    ? ($subscription[SignalPayloadConstants::SUBSCRIPTION_PAGE_KEY] ?? '')
                    : '';
                if ($page !== SettingsPage::PAGE) {
                    continue;
                }

                $row = $this->settingsBrowserRowForKey($key);
                if ($row === null) {
                    unset($rowsByAccept[$acceptKey][$key]);
                    $deletedByAccept[$acceptKey][$key] = $key;
                    continue;
                }

                unset($deletedByAccept[$acceptKey][$key]);
                $rowsByAccept[$acceptKey][$key] = $row;
            }
        }

        $acceptKeys = array_unique(array_merge(
            array_keys($rowsByAccept),
            array_keys($deletedByAccept),
        ));
        foreach ($acceptKeys as $acceptKey) {
            $section = [];
            $rows = array_values($rowsByAccept[$acceptKey] ?? []);
            $deleted = array_values($deletedByAccept[$acceptKey] ?? []);
            if ($rows !== []) {
                $section[PagePayload::rows] = $rows;
            }
            if ($deleted !== []) {
                $section[PagePayload::deleted] = $deleted;
            }
            if ($section === []) {
                continue;
            }

            Hilos::$sr->queueSignal(
                signalSource: new SignalSource(SignalSource::WORKER),
                signalType: new SignalType(SignalTypeConstants::WS_USER),
                signalName: new SignalName(SignalTypeConstants::PAGE_RESPONSE),
                signalData: new WebSocketSignalData(
                    data: new PageResponseSignalData(
                        SettingsPage::PAGE,
                        new PagePayload(tables: [ChatTableContext::settings => $section]),
                    ),
                    targetAcceptKey: $acceptKey,
                ),
            );
        }
    }

    /**
     * Builds the current page-response row for one settings key.
     *
     * @param string $key Setting key
     * @return ?array{rowKey: int|string, slots: array<string, mixed>} Page-response row, or null when absent
     */
    private function settingsBrowserRowForKey(string $key): ?array
    {
        $table = Hilos::$table?->get(ChatTableContext::settings);
        if (!$table instanceof HilosSettingsTable) {
            return null;
        }

        try {
            $row = $table->rowForKey($key);
        } catch (Throwable) {
            return null;
        }

        return $row instanceof HilosSettingTableRow ? $this->settingsBrowserRow($row) : null;
    }

    /**
     * Wraps a settings table row in the page-response row envelope.
     *
     * The DB id is dropped from the slot: the frontend normalizer treats any slot
     * carrying a non-null id as a referenced entity, but settings rows are
     * page-scoped, keyed by the setting key, and carry a nullable id (catalog
     * placeholders have none). Dropping it keeps the slot a uniform inline record;
     * persisted-ness is read from value_source instead.
     *
     * @param HilosSettingTableRow $row Settings table row
     * @return array{rowKey: int|string, slots: array<string, mixed>} Page-response row payload
     */
    private function settingsBrowserRow(HilosSettingTableRow $row): array
    {
        $slot = $row->toArray();
        unset($slot[HilosSettingTableRow::id]);

        return [
            PagePayload::rowKey => $row->getRowKey(),
            PagePayload::slots => [
                HilosDbContext::settings => $slot,
            ],
        ];
    }

    /**
     * Checks whether a source change belongs to the settings DB collection.
     *
     * @param SourceChange $change Source change to inspect
     * @return bool True when this is a settings DB change
     */
    private function isSettingsSourceChange(SourceChange $change): bool
    {
        return $change->kind === SourceChange::KIND_DB
            && $change->sourceKey === HilosDbContext::settings;
    }

    /**
     * Resolves the settings key from a settings source change.
     *
     * @param SourceChange $change Settings source change
     * @return string Setting key, or an empty string when unavailable
     */
    private function settingKeyFromSourceChange(SourceChange $change): string
    {
        $key = $change->row[HilosSettingTableRow::key] ?? null;
        if (is_string($key) || is_int($key)) {
            return (string) $key;
        }

        try {
            $setting = ctype_digit($change->sourceId)
                ? (Hilos::$db?->settings[(int) $change->sourceId] ?? null)
                : null;
            if ($setting !== null) {
                return $setting->key;
            }
        } catch (Throwable) {
            return '';
        }

        return $change->sourceId;
    }
}
