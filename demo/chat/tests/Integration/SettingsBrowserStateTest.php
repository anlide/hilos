<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\ChatTableContext;
use Demo\Chat\Tables\Settings\SettingTableRow;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration coverage for the Hilos settings page browser payload.
 */
final class SettingsBrowserStateTest extends IntegrationTestCase
{
    private const string TEST_SETTINGS_AGENT_ID = 'test-settings-browser-agent';

    public function testSettingsSnapshotUsesBrowserRowsWithCatalogPlaceholdersAndOrphans(): void
    {
        $orphanKey = 'browser_snapshot_orphan_' . RandomHelper::hex(8);
        TruthSourceRegistry::register(HilosDbContext::settings, true, self::TEST_SETTINGS_AGENT_ID);

        try {
            $this->createOrphanSetting($orphanKey, 'snapshot orphan');
            $this->resetFrontendRouter();
            Hilos::$browser->subscribeSnapshot(PageConstants::HILOS_SETTINGS, 'settings-snapshot-ak', new PageRouteParams([]));

            $payload = $this->drainSinglePayload(
                SignalTypeConstants::PAGE_RESPONSE,
                'settings-snapshot-ak',
            );
            $rows = $payload[PagePayload::tables][ChatTableContext::settings][PagePayload::rows] ?? [];

            $placeholderRow = $this->findSettingsBrowserRow(
                $rows,
                SettingsCatalogConstants::STUB_KEY_EXAMPLE_INTEGER,
            );
            $this->assertIsArray($placeholderRow);
            $placeholder = $this->settingsSource($placeholderRow);
            $this->assertArrayNotHasKey(SettingTableRow::id, $placeholder);
            $this->assertSame('0', $placeholder[SettingTableRow::value]);
            $this->assertSame(SettingTableRow::VALUE_SOURCE_DEFAULT, $placeholder[SettingTableRow::valueSource]);

            $referenceRow = $this->findSettingsBrowserRow($rows, ChatSettingsConstants::CHAT_BOT_MODEL);
            $this->assertIsArray($referenceRow);
            $reference = $this->settingsSource($referenceRow);
            $this->assertSame(ChatSettingsConstants::DEFAULT_BOT_MODEL, $reference[SettingTableRow::defaultReferenceKey]);
            $this->assertSame(SettingTableRow::VALUE_SOURCE_REFERENCE, $reference[SettingTableRow::valueSource]);

            $orphanRow = $this->findSettingsBrowserRow($rows, $orphanKey);
            $this->assertIsArray($orphanRow);
            $orphan = $this->settingsSource($orphanRow);
            $this->assertSame($orphanKey, $orphan[SettingTableRow::key]);
            $this->assertSame(SettingTableRow::VALUE_SOURCE_ORPHAN, $orphan[SettingTableRow::valueSource]);
        } finally {
            $this->deleteSettingIfExists($orphanKey);
            TruthSourceRegistry::unregisterAgent(self::TEST_SETTINGS_AGENT_ID);
            $this->resetFrontendRouter();
        }
    }

    public function testSettingsBrowserMutationsUpdateCatalogRowsAndDeleteOrphans(): void
    {
        $catalogKey = SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING;
        $original = Hilos::$db->settings[$catalogKey] ?? null;
        $originalExists = $original !== null;
        $originalValue = $original?->value;
        $orphanKey = 'browser_delete_orphan_' . RandomHelper::hex(8);

        TruthSourceRegistry::register(HilosDbContext::settings, true, self::TEST_SETTINGS_AGENT_ID);

        try {
            $this->deleteSettingIfExists($catalogKey);
            $this->createOrphanSetting($orphanKey, 'delete orphan');
            $this->resetFrontendRouter();
            Hilos::$sr->subscribeToPage(PageConstants::HILOS_SETTINGS, new WebSocketPageSubscribeSignalDTO(
                'settings-mutation-ak',
                PageConstants::HILOS_SETTINGS,
                [],
            ));

            Hilos::$table->settings->actions->add($catalogKey, 'browser custom');
            $payload = $this->drainSinglePayload(
                SignalTypeConstants::PAGE_RESPONSE,
                'settings-mutation-ak',
                flushFrontend: true,
            );
            $created = $this->settingsSource($this->findSettingsBrowserRow(
                $payload[PagePayload::tables][ChatTableContext::settings][PagePayload::rows] ?? [],
                $catalogKey,
            ));
            $this->assertSame('browser custom', $created[SettingTableRow::overrideValue]);
            $this->assertSame(SettingTableRow::VALUE_SOURCE_OVERRIDE, $created[SettingTableRow::valueSource]);

            Hilos::$table->settings[$catalogKey]->actions->updateValue(null);
            $payload = $this->drainSinglePayload(
                SignalTypeConstants::PAGE_RESPONSE,
                'settings-mutation-ak',
                flushFrontend: true,
            );
            $updated = $this->settingsSource($this->findSettingsBrowserRow(
                $payload[PagePayload::tables][ChatTableContext::settings][PagePayload::rows] ?? [],
                $catalogKey,
            ));
            $this->assertNull($updated[SettingTableRow::overrideValue]);
            $this->assertSame(SettingTableRow::VALUE_SOURCE_DEFAULT, $updated[SettingTableRow::valueSource]);

            Hilos::$db->settings[$catalogKey]?->actions->delete();
            $payload = $this->drainSinglePayload(
                SignalTypeConstants::PAGE_RESPONSE,
                'settings-mutation-ak',
                flushFrontend: true,
            );
            $afterDelete = $this->settingsSource($this->findSettingsBrowserRow(
                $payload[PagePayload::tables][ChatTableContext::settings][PagePayload::rows] ?? [],
                $catalogKey,
            ));
            $this->assertArrayNotHasKey(SettingTableRow::id, $afterDelete);
            $this->assertSame(SettingTableRow::VALUE_SOURCE_DEFAULT, $afterDelete[SettingTableRow::valueSource]);
            $deleted = $payload[PagePayload::tables][ChatTableContext::settings][PagePayload::deleted] ?? [];
            $this->assertNotContains($catalogKey, $deleted);

            Hilos::$table->settings[$orphanKey]->actions->delete();
            $payload = $this->drainSinglePayload(
                SignalTypeConstants::PAGE_RESPONSE,
                'settings-mutation-ak',
                flushFrontend: true,
            );
            $deleted = $payload[PagePayload::tables][ChatTableContext::settings][PagePayload::deleted] ?? [];
            $this->assertContains($orphanKey, $deleted);
        } finally {
            $this->deleteSettingIfExists($catalogKey);
            if ($originalExists) {
                Hilos::$db->settings->actions->add($catalogKey, $originalValue, SettingsCatalog::getCatalog());
            }
            $this->deleteSettingIfExists($orphanKey);
            TruthSourceRegistry::unregisterAgent(self::TEST_SETTINGS_AGENT_ID);
            $this->resetFrontendRouter();
        }
    }

    /**
     * Reinitializes worker-local routers before recording browser source changes.
     */
    private function resetFrontendRouter(): void
    {
        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initBrowser();
    }

    /**
     * Drains one browser payload from the queue.
     *
     * @param string $signalName Browser page signal name
     * @param string $targetAcceptKey Expected target accept key
     * @param bool $flushFrontend Whether queued DB sync signals should be flushed first
     * @return array<string, mixed> Browser payload array
     */
    private function drainSinglePayload(string $signalName, string $targetAcceptKey, bool $flushFrontend = false): array
    {
        if ($flushFrontend) {
            $this->drainSyncSignalsToFrontendBuffers();
            Hilos::$browser?->flushToSignalRouter();
        }

        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            if (
                $signal->signalName->getName() === $signalName
                && $signal->data instanceof WebSocketSignalData
                && $signal->data->targetAcceptKey === $targetAcceptKey
            ) {
                $signals[] = $signal;
            }
        }

        $this->assertCount(1, $signals);
        $webSocketData = $signals[0]->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $webSocketData);
        $this->assertInstanceOf(PageResponseSignalData::class, $webSocketData->data);

        $payload = $webSocketData->data->toArray()[PageResponseSignalData::payload] ?? [];
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * Records queued DB sync signals as browser source changes.
     */
    private function drainSyncSignalsToFrontendBuffers(): void
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $change = $this->sourceChangeFromSignal($signal);
            if ($change === null) {
                continue;
            }
            Hilos::$browser?->record($change);
        }
    }

    /**
     * Converts a sync signal to a source change.
     */
    private function sourceChangeFromSignal(SignalDTO $signal): ?SourceChange
    {
        $signalType = $signal->signalType->getType();
        $signalData = $signal->data;

        if ($signalType === SignalTypeConstants::DB_SYNC_CREATED && $signalData instanceof DbSyncCreatedSignalData) {
            return SourceChange::dbCreated($signalData->collectionKey, $signalData->idString, $signalData->row);
        }
        if ($signalType === SignalTypeConstants::DB_SYNC_UPDATED && $signalData instanceof DbSyncUpdatedSignalData) {
            return SourceChange::dbUpdated($signalData->collectionKey, $signalData->idString, $signalData->row);
        }
        if ($signalType === SignalTypeConstants::DB_SYNC_DELETED && $signalData instanceof DbSyncDeletedSignalData) {
            return SourceChange::dbDeleted($signalData->collectionKey, $signalData->idString, $signalData->row);
        }

        return null;
    }

    /**
     * Finds a settings browser row by settings key.
     *
     * @param list<array<string, mixed>> $rows Browser rows
     * @param string $key Setting key to find
     * @return ?array<string, mixed> Matching browser row
     */
    private function findSettingsBrowserRow(array $rows, string $key): ?array
    {
        foreach ($rows as $row) {
            $setting = $row[PagePayload::slots][HilosDbContext::settings] ?? null;
            if (is_array($setting) && ($setting[SettingTableRow::key] ?? null) === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Returns the settings source fragment from a browser row.
     *
     * @param ?array<string, mixed> $row Browser row
     * @return array<string, mixed> Settings source fragment
     */
    private function settingsSource(?array $row): array
    {
        $this->assertIsArray($row);
        $setting = $row[PagePayload::slots][HilosDbContext::settings] ?? null;
        $this->assertIsArray($setting);

        return $setting;
    }

    /**
     * Creates an uncataloged persisted setting row for orphan coverage.
     *
     * @param string $key Orphan setting key
     * @param string $value Orphan setting value
     */
    private function createOrphanSetting(string $key, string $value): void
    {
        $setting = ObjectSetting::create();
        $setting->key = $key;
        $setting->type = SettingsCatalogConstants::TYPE_STRING;
        $setting->value = $value;
        $setting->sync();
    }

    /**
     * Deletes a persisted setting row when present.
     *
     * @param string $key Setting key
     */
    private function deleteSettingIfExists(string $key): void
    {
        Hilos::$db->settings[$key]?->actions->delete();
    }
}
